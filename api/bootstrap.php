<?php

// Entrada comum de todos os endpoints da API: cabeçalhos, CORS, rate limit,
// autenticação da chave e as funções de autorização que cada endpoint chama.
//
// Modelo de segurança (detalhes em docs/SEGURANCA-API.md):
//
//   1. Toda requisição traz uma chave de API (X-API-Key). A chave tem ESCOPO:
//        app    — só as rotas do app do aluno, e sempre junto com a sessão
//        admin  — também os endpoints administrativos
//      A chave do app é pública por natureza (vai pro navegador de quem abre a
//      PWA), então sozinha ela não pode dar acesso a nada.
//   2. As rotas do app exigem também a sessão do aluno (login por QR code),
//      em X-Session-Token ou Authorization: Bearer — exigirSessaoAluno().
//   3. Os endpoints administrativos exigem chave de escopo admin —
//      exigirEscopoAdmin(). Sem isso respondem 403.
//
// Códigos e cabeçalhos de erro seguem as RFCs: 401 + WWW-Authenticate quando
// falta credencial válida (RFC 7235 / 6750), 403 quando a credencial é válida
// mas não tem permissão (insufficient_scope), 429 + Retry-After no bloqueio
// por excesso de tentativas (RFC 6585).

require_once __DIR__ . '/../core/config.php';

header('Content-Type: application/json; charset=utf-8');
// Respostas da API têm dado pessoal: nada de cache intermediário, nada de o
// navegador "adivinhar" outro tipo de conteúdo, nada de vazar a URL em Referer.
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

// CORS aberto de propósito: a API não usa cookie (a credencial vai sempre em
// cabeçalho explícito), então outra origem não consegue "pegar carona" na
// sessão de ninguém — sem a chave e o token ela não lê nada.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-Session-Token, Authorization');
header('Access-Control-Max-Age: 600');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$conexao = conectarBanco();

$GLOBALS['__api_chave_id'] = null;
$GLOBALS['__api_escopo'] = null;
$GLOBALS['__api_endpoint'] = basename($_SERVER['SCRIPT_NAME']);
$GLOBALS['__api_metodo'] = $_SERVER['REQUEST_METHOD'];

function registrarLogApi($conexao, $status) {
    $chaveId = $GLOBALS['__api_chave_id'];
    $endpoint = $GLOBALS['__api_endpoint'];
    $metodo = $GLOBALS['__api_metodo'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    $stmt = mysqli_prepare($conexao, "INSERT INTO api_logs (api_chave_id, endpoint, metodo, status_code, ip) VALUES (?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "issis", $chaveId, $endpoint, $metodo, $status, $ip);
    mysqli_stmt_execute($stmt);
}

function responder($dados, $status = 200) {
    global $conexao;
    registrarLogApi($conexao, $status);
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

function erro($mensagem, $status = 400) {
    responder(['erro' => $mensagem], $status);
}

function corpoJson() {
    $dados = json_decode(file_get_contents('php://input'), true);
    return is_array($dados) ? $dados : [];
}

// ---------- Rate limit por IP contra força bruta de chave ----------
// A chave em si (256 bits aleatórios) é inviável de adivinhar por força
// bruta pura, mas nada impedia um script martelar tentativas indefinidamente
// — isso aqui trava temporariamente um IP que já errou demais, reaproveitando
// o api_logs que já existe (sem tabela nova).
const API_RATE_LIMITE_MAX_ERROS = 20;
const API_RATE_LIMITE_JANELA_MINUTOS = 5;

$ipRequisicao = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ipRequisicao !== '') {
    $stmt = mysqli_prepare($conexao, "
        SELECT COUNT(*) as total FROM api_logs
        WHERE ip = ? AND status_code = 401 AND criado_em >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
    ");
    $janelaMinutos = API_RATE_LIMITE_JANELA_MINUTOS;
    mysqli_stmt_bind_param($stmt, "si", $ipRequisicao, $janelaMinutos);
    mysqli_stmt_execute($stmt);
    $totalErros401 = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'] ?? 0);

    if ($totalErros401 >= API_RATE_LIMITE_MAX_ERROS) {
        header('Retry-After: ' . (API_RATE_LIMITE_JANELA_MINUTOS * 60));
        erro('Muitas tentativas com chave inválida a partir deste IP. Tente novamente mais tarde.', 429);
    }
}

// ---------- Autenticação por API key (validada contra o banco, não mais fixa) ----------
$chaveRecebida = $_SERVER['HTTP_X_API_KEY'] ?? '';
$chaveHash = hash('sha256', $chaveRecebida);

$stmt = mysqli_prepare($conexao, "SELECT id, escopo FROM api_chaves WHERE chave_hash = ? AND ativo = 1");
mysqli_stmt_bind_param($stmt, "s", $chaveHash);
mysqli_stmt_execute($stmt);
$chaveEncontrada = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$chaveEncontrada) {
    header('WWW-Authenticate: ApiKey realm="Argos API"');
    erro('Chave de API ausente ou inválida.', 401);
}

$GLOBALS['__api_chave_id'] = (int) $chaveEncontrada['id'];
$GLOBALS['__api_escopo'] = $chaveEncontrada['escopo'];
mysqli_query($conexao, "UPDATE api_chaves SET ultimo_uso = NOW() WHERE id = " . (int)$chaveEncontrada['id']);

// ---------- Autorização ----------

function chaveTemEscopoAdmin() {
    return $GLOBALS['__api_escopo'] === 'admin';
}

/**
 * Endpoints administrativos (gestão de alunos, grupos, usuários do Painel,
 * relatórios, situação): só com chave de escopo admin. A chave do app — que
 * qualquer um tira do navegador — recebe 403.
 */
function exigirEscopoAdmin() {
    if (!chaveTemEscopoAdmin()) {
        header('WWW-Authenticate: ApiKey realm="Argos API", error="insufficient_scope", scope="admin"');
        erro('Esta chave de API não tem permissão para este endpoint. Ele exige uma chave de escopo "admin" (Ikarus37 → API).', 403);
    }
}

// Token de sessão: X-Session-Token (o que o app sempre usou) ou o padrão
// Authorization: Bearer <token> (RFC 6750). Alguns servidores só repassam o
// Authorization pro PHP via REDIRECT_HTTP_AUTHORIZATION.
function tokenDeSessaoRecebido() {
    $token = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
    if ($token !== '') {
        return $token;
    }
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Rotas do fluxo do app do aluno (efetivo, motivos, retiradas, dispensas,
 * livro): além da chave, exigem a sessão de um aluno, obtida em
 * POST /api/sessao.php com o QR code dele. Devolve o aluno, já com o posto de
 * serviço da sessão (esquadrao_servico / esquadrilha_servico) — é esse o
 * esquadrão que limita o que ele vê e lança, não o de origem.
 */
function exigirSessaoAluno($conexao) {
    require_once __DIR__ . '/../core/api_sessoes_core.php';

    $aluno = validarSessaoAluno($conexao, tokenDeSessaoRecebido());

    if (!$aluno) {
        header('WWW-Authenticate: Bearer realm="Argos API", error="invalid_token"');
        erro('Sessão inválida ou expirada. Faça login de novo (POST /api/sessao.php) e envie o token em X-Session-Token.', 401);
    }

    return $aluno;
}

/**
 * Pra leitura que serve aos dois públicos: o app (sessão do aluno, restrito
 * ao esquadrão de serviço) e integrações administrativas (chave admin, sem
 * restrição). Devolve o aluno da sessão, ou null quando é chave admin.
 */
function exigirSessaoOuAdmin($conexao) {
    if (chaveTemEscopoAdmin() && tokenDeSessaoRecebido() === '') {
        return null;
    }
    return exigirSessaoAluno($conexao);
}
