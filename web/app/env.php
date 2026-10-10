<?php

// Serve a chave de API da PWA (X-API-Key) como uma variável global JS, sem
// nunca deixar o valor literal entrar em um arquivo versionado — ela vive só
// em core/config.php (gitignorado). Não é dado sensível por aluno: é só a
// identificação do app, a sessão do aluno (X-Session-Token) é o segredo real.

require_once __DIR__ . '/../../core/config.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store');

echo 'window.ARGOS_API_KEY = ' . json_encode($APP_PWA_API_KEY ?? '', JSON_UNESCAPED_UNICODE) . ';';

// Base da API relativa à raiz do projeto (3 níveis acima de web/app/env.php),
// pra funcionar tanto no Docker local (/api/) quanto em produção, onde o site
// fica num subdiretório (ex: /cidit/projetos/11/api/).
$raizProjeto = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'], 3)), '/');
echo "\n" . 'window.ARGOS_API_BASE = ' . json_encode($raizProjeto . '/api/', JSON_UNESCAPED_SLASHES) . ';';

// Versão publicada (core/versao.php, gerado no deploy) — o app mostra no rodapé.
$versaoArgos = require __DIR__ . '/../../core/versao.php';
echo "\n" . 'window.ARGOS_VERSAO = ' . json_encode($versaoArgos['versao'] ?? '', JSON_UNESCAPED_UNICODE) . ';';
