<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$conexao = conectarBanco();
$escopo = escopoEsquadrao();

$agrupamentoTipo = $_GET['agrupamento_tipo'] ?? 'esquadrilha';
$alunos = [];

if ($agrupamentoTipo === 'esquadrilha') {
    $esquadrao = $escopo ?? ($_GET['esquadrao'] ?? '');
    $esquadrilha = $_GET['esquadrilha'] ?? '';

    if ($esquadrao !== '' && $esquadrilha !== '') {
        $stmt = mysqli_prepare($conexao, "SELECT id, nome_guerra, milhao FROM alunos WHERE esquadrao = ? AND esquadrilha = ? AND ativo = 1 ORDER BY nome_guerra");
        mysqli_stmt_bind_param($stmt, "ss", $esquadrao, $esquadrilha);
        mysqli_stmt_execute($stmt);
        $alunos = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    }
} else {
    $grupoNome = $_GET['grupo'] ?? '';
    if ($grupoNome !== '') {
        $stmt = mysqli_prepare($conexao, "
            SELECT a.id, a.nome_guerra, a.milhao
            FROM alunos a
            JOIN grupo_membros gm ON gm.aluno_id = a.id
            JOIN grupos g ON g.id = gm.grupo_id
            WHERE g.nome = ? AND a.ativo = 1
            ORDER BY a.nome_guerra
        ");
        mysqli_stmt_bind_param($stmt, "s", $grupoNome);
        mysqli_stmt_execute($stmt);
        $alunos = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    }
}

echo json_encode($alunos, JSON_UNESCAPED_UNICODE);
mysqli_close($conexao);
