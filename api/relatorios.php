<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../retiradas_core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    erro("Método não suportado.", 405);
}

$filtros = [
    'data_inicio' => $_GET['data_inicio'] ?? date('Y-m-01'),
    'data_fim' => $_GET['data_fim'] ?? date('Y-m-d'),
    'tipo' => $_GET['tipo'] ?? null,
    'esquadrao' => $_GET['esquadrao'] ?? null,
];

responder([
    'resumo' => relatorioResumo($conexao, $filtros),
    'por_motivo' => relatorioPorMotivo($conexao, $filtros),
    'retiradas' => relatorioRetiradas($conexao, $filtros),
]);

mysqli_close($conexao);
