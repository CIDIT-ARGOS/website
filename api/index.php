<?php

header('Content-Type: application/json; charset=utf-8');
http_response_code(200);

echo json_encode([
    'servico' => 'Argos API',
    'documentacao' => 'Consulte a documentação no painel administrativo (Ikarus37 → API).',
    'autenticacao' => 'Header obrigatório: X-API-Key. Endpoints marcados (sessão) também exigem X-Session-Token, obtido em POST /api/sessao.php com o qrcode_hash do aluno.',
    'endpoints' => [
        'POST /api/sessao.php — login do app do aluno via QR code',
        'GET (sessão)/POST/PUT/DELETE /api/alunos.php',
        'GET (sessão) /api/motivos.php',
        'GET/POST (sessão)/PUT (sessão) /api/retiradas.php',
        'GET (sessão)/PUT (sessão) /api/retirada_itens.php',
        'GET/POST/DELETE /api/grupos.php',
        'GET/POST/PUT/DELETE /api/painel_usuarios.php',
        'GET /api/relatorios.php',
        'GET /api/situacao.php',
    ],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
