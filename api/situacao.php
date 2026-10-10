<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/situacao_core.php';

// Endpoint administrativo: só com chave de escopo admin (a chave do app recebe 403).
exigirEscopoAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    erro("Método não suportado.", 405);
}

$filtros = [
    'esquadrao' => $_GET['esquadrao'] ?? null,
    'esquadrilha' => $_GET['esquadrilha'] ?? null,
    'busca' => $_GET['busca'] ?? null,
    'somente_ausentes' => isset($_GET['somente_ausentes']),
];

responder([
    'resumo' => situacaoResumo($conexao, $filtros),
    'alunos' => situacaoAtualAlunos($conexao, $filtros),
]);

mysqli_close($conexao);
