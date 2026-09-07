<?php

header('Content-Type: application/json; charset=utf-8');
http_response_code(200);

echo json_encode([
    'servico' => 'Argos API',
    'documentacao' => 'Consulte a documentação no painel administrativo (Ikarus37 → API).',
    'autenticacao' => 'Header obrigatório: X-API-Key',
    'endpoints' => [
        'GET/POST/PUT/DELETE /api/alunos.php',
        'GET /api/motivos.php',
        'GET/POST/PUT /api/retiradas.php',
        'GET/PUT /api/retirada_itens.php',
    ],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
