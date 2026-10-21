<?php

require_once __DIR__ . '/../core/fuso.php';

header('Content-Type: application/json; charset=utf-8');
http_response_code(200);

echo json_encode([
    'servico' => 'Argos API',
    // Hora do servidor, no fuso do sistema (Brasília) — o smoke test confere.
    'hora_servidor' => date('c'),
    'documentacao' => 'Consulte a documentação no painel administrativo (Ikarus37 → API).',
    'autenticacao' => 'Header obrigatório: X-API-Key. Endpoints marcados (sessão) exigem também a sessão do aluno, obtida em POST /api/sessao.php com o conteúdo do QR code dele (campo qrcode) e enviada em X-Session-Token (ou Authorization: Bearer). Endpoints marcados (admin) exigem uma chave de escopo admin, gerada em Ikarus37 → API.',
    'endpoints' => [
        'POST /api/sessao.php — login do app do aluno via QR code',
        'GET (sessão)/PUT (sessão) /api/servico.php — em qual esquadrão/esquadrilha o aluno está de serviço, e a função dele (Aluno de Dia à Esquadrilha, ao Esquadrão ou ao CA)',
        'GET (sessão) · POST/PUT/DELETE (admin) /api/alunos.php',
        'GET (sessão) /api/motivos.php',
        'GET (sessão) /api/postos_servico.php',
        'GET (sessão ou admin) · POST (sessão) · PUT (sessão) · DELETE (admin) /api/retiradas.php',
        'GET (sessão)/PUT (sessão) /api/retirada_itens.php',
        'GET (sessão)/POST (sessão) /api/dispensas.php — dispensas médicas do esquadrão',
        'GET (sessão)/PUT (sessão)/POST (sessão) /api/livro.php — Livro do Dia da função do aluno: ler, escrever as alterações, enviar, validar ou devolver',
        'GET/POST/DELETE (admin) /api/grupos.php',
        'GET/POST/PUT/DELETE (admin) /api/painel_usuarios.php',
        'GET (admin) /api/relatorios.php',
        'GET (admin) /api/situacao.php',
    ],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
