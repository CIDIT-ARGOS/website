<?php

// Login do app do aluno via QR code — issue #26. Exige uma X-API-Key
// válida (já checado em bootstrap.php) + o QR code do aluno; devolve
// um token de sessão (16h) que passa a ser exigido, junto com a chave, em
// toda rota do fluxo do app (ver exigirSessaoAluno em bootstrap.php).

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/api_sessoes_core.php';
require_once __DIR__ . '/../core/qrcodes_core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    erro('Método não suportado. Use POST com { "qrcode": "conteúdo lido do QR" }.', 405);
}

$dados = corpoJson();

// "qrcode": o conteúdo do QR como foi lido, em qualquer formato — o servidor
// calcula a impressão digital e compara com a do cadastro (core/qrcodes_core.php).
// "qrcode_hash": o jeito antigo, pra quem já manda a impressão pronta.
if (array_key_exists('qrcode', $dados)) {
    $qrcodeHash = impressaoDoQr($dados['qrcode']);
    if ($qrcodeHash === null) {
        erro('qrcode inválido: envie o conteúdo lido do QR (texto, até ' . QR_CONTEUDO_MAX . ' caracteres).');
    }
} else {
    $qrcodeHash = is_string($dados['qrcode_hash'] ?? null) ? trim($dados['qrcode_hash']) : '';
    if (!preg_match('/^[a-f0-9]{64}$/i', $qrcodeHash)) {
        erro('qrcode_hash inválido: deve ser um hash sha256 (64 caracteres hexadecimais).');
    }
}

$stmt = mysqli_prepare($conexao, "
    SELECT id, posto_graduacao, nome_guerra, milhao, esquadrao, esquadrilha, especialidade
    FROM alunos WHERE qrcode_hash = ? AND ativo = 1
");
mysqli_stmt_bind_param($stmt, "s", $qrcodeHash);
mysqli_stmt_execute($stmt);
$aluno = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$aluno) {
    // Mensagem genérica de propósito — não confirma se o QR existe mas o
    // aluno está inativo, vs. o hash simplesmente não corresponde a ninguém.
    erro('QR Code não reconhecido.', 401);
}

$token = criarSessaoAluno($conexao, $aluno['id'], $GLOBALS['__api_chave_id'], $_SERVER['REMOTE_ADDR'] ?? null);

responder([
    'token' => $token,
    'expira_em_horas' => API_SESSAO_TTL_HORAS,
    'aluno' => $aluno,
    // Onde ele está de serviço: começa no próprio esquadrão; o app pergunta e
    // troca em PUT /api/servico.php.
    'servico' => ['esquadrao' => $aluno['esquadrao'], 'esquadrilha' => $aluno['esquadrilha'], 'funcao' => 'esquadrilha'],
], 201);

mysqli_close($conexao);
