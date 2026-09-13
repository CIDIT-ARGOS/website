<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../core/alunos_core.php';
require_once __DIR__ . '/../../core/grupos_core.php';

header('Content-Type: application/json; charset=utf-8');

$conexao = conectarBanco();
$escopo = escopoEsquadrao();

$agrupamentoTipo = $_GET['agrupamento_tipo'] ?? 'esquadrilha';
$alunos = [];

if ($agrupamentoTipo === 'esquadrilha') {
    $esquadrao = $escopo ?? ($_GET['esquadrao'] ?? '');
    $esquadrilha = $_GET['esquadrilha'] ?? '';

    if ($esquadrao !== '' && $esquadrilha !== '') {
        $alunos = listarAlunosPorEsquadrilha($conexao, $esquadrao, $esquadrilha);
    }
} else {
    $grupoNome = $_GET['grupo'] ?? '';
    if ($grupoNome !== '') {
        $alunos = listarAlunosPorGrupo($conexao, $grupoNome);
    }
}

echo json_encode($alunos, JSON_UNESCAPED_UNICODE);
mysqli_close($conexao);
