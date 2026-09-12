<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../alunos_core.php';

header('Content-Type: application/json; charset=utf-8');

$conexao = conectarBanco();

// Busca em TODO o efetivo, sem respeitar escopo de esquadrão: o aluno de serviço
// (ex: Aluno de Dia à Esquadrilha) pode legitimamente ser de outro esquadrão.
$termo = trim($_GET['termo'] ?? '');
$alunos = buscarAlunosLivre($conexao, $termo);

echo json_encode($alunos, JSON_UNESCAPED_UNICODE);
mysqli_close($conexao);
