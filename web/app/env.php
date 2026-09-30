<?php

// Serve a chave de API da PWA (X-API-Key) como uma variável global JS, sem
// nunca deixar o valor literal entrar em um arquivo versionado — ela vive só
// em core/config.php (gitignorado). Não é dado sensível por aluno: é só a
// identificação do app, a sessão do aluno (X-Session-Token) é o segredo real.

require_once __DIR__ . '/../../core/config.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store');

echo 'window.ARGOS_API_KEY = ' . json_encode($APP_PWA_API_KEY ?? '', JSON_UNESCAPED_UNICODE) . ';';
