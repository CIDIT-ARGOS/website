<?php

// Migrations pela linha de comando — CI e Docker local.
// (Em produção quem roda é o deploy, via deploy/endpoint.php.)
//
//   php deploy/migrar.php status [--baseline=N]
//   php deploy/migrar.php migrar [--baseline=N]
//
// No Docker local:
//   docker compose exec web php deploy/migrar.php migrar

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/migracoes.php';
require_once dirname(__DIR__) . '/core/config.php';

$acao = $argv[1] ?? 'status';
$baseline = null;
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--baseline=(\d+)$/', $arg, $m)) {
        $baseline = (int) $m[1];
    }
}

$conexao = conectarBanco();
mysqli_set_charset($conexao, 'utf8mb4');
$diretorio = dirname(__DIR__) . '/database';

try {
    if ($acao === 'status') {
        $status = migracoesStatus($conexao, $diretorio, $baseline);
        echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        exit(0);
    }

    if ($acao === 'migrar') {
        $resultado = migracoesExecutar($conexao, $diretorio, $baseline);
        echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        exit($resultado['ok'] ? 0 : 1);
    }

    fwrite(STDERR, "Uso: php deploy/migrar.php status|migrar [--baseline=N]\n");
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, "Erro: " . $e->getMessage() . "\n");
    exit(1);
}
