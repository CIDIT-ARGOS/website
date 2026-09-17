<?php

// Login do app do aluno via QR code — issue #26. Exige uma X-API-Key
// válida (já checado em bootstrap.php) + o qrcode_hash do aluno; devolve
// um token de sessão (16h) que passa a ser exigido, junto com a chave, em
// toda rota do fluxo do app (ver exigirSessaoAluno em bootstrap.php).

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/api_sessoes_core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    erro('Método não suportado. Use POST com { "qrcode_hash": "..." }.', 405);
}

$dados = corpoJson();
$qrcodeHash = trim($dados['qrcode_hash'] ?? '');

if (!preg_match('/^[a-f0-9]{64}$/i', $qrcodeHash)) {
    erro('qrcode_hash inválido: deve ser um hash sha256 (64 caracteres hexadecimais).');
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
], 201);

mysqli_close($conexao);
