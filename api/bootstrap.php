<?php

require_once __DIR__ . '/../core/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$conexao = conectarBanco();

$GLOBALS['__api_chave_id'] = null;
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
        erro('Muitas tentativas com chave inválida a partir deste IP. Tente novamente mais tarde.', 429);
    }
}

// ---------- Autenticação por API key (validada contra o banco, não mais fixa) ----------
$chaveRecebida = $_SERVER['HTTP_X_API_KEY'] ?? '';
$chaveHash = hash('sha256', $chaveRecebida);

$stmt = mysqli_prepare($conexao, "SELECT id FROM api_chaves WHERE chave_hash = ? AND ativo = 1");
mysqli_stmt_bind_param($stmt, "s", $chaveHash);
mysqli_stmt_execute($stmt);
$chaveEncontrada = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$chaveEncontrada) {
    erro('Chave de API ausente ou inválida.', 401);
}

$GLOBALS['__api_chave_id'] = (int) $chaveEncontrada['id'];
mysqli_query($conexao, "UPDATE api_chaves SET ultimo_uso = NOW() WHERE id = " . (int)$chaveEncontrada['id']);
