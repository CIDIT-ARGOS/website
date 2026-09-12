<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$conexao = conectarBanco();

// Busca em TODO o efetivo, sem respeitar escopo de esquadrão: o aluno de serviço
// (ex: Aluno de Dia à Esquadrilha) pode legitimamente ser de outro esquadrão.
$termo = trim($_GET['termo'] ?? '');
$alunos = [];

if (strlen($termo) >= 2) {
    $like = "%$termo%";
    $stmt = mysqli_prepare($conexao, "
        SELECT id, nome_guerra, milhao, esquadrao, esquadrilha
        FROM alunos
        WHERE ativo = 1 AND (nome_guerra LIKE ? OR milhao LIKE ?)
        ORDER BY nome_guerra
        LIMIT 20
    ");
    mysqli_stmt_bind_param($stmt, "ss", $like, $like);
    mysqli_stmt_execute($stmt);
    $alunos = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

echo json_encode($alunos, JSON_UNESCAPED_UNICODE);
mysqli_close($conexao);
