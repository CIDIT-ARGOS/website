<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';

$conexao = conectarBanco();

$tabela = $_GET['tabela'] ?? '';

// Valida que a tabela realmente existe (evita injeção via nome de tabela)
$tabelasValidas = [];
$r = mysqli_query($conexao, "SHOW TABLES");
while ($linha = mysqli_fetch_array($r)) {
    $tabelasValidas[] = $linha[0];
}

if (!in_array($tabela, $tabelasValidas)) {
    http_response_code(400);
    die("Tabela inválida.");
}

$resultado = mysqli_query($conexao, "SELECT * FROM `$tabela`");
$colunas = array_map(fn($c) => $c->name, mysqli_fetch_fields($resultado));

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $tabela . '_' . date('Y-m-d_His') . '.csv"');

$saida = fopen('php://output', 'w');
fputcsv($saida, $colunas);

while ($linha = mysqli_fetch_assoc($resultado)) {
    fputcsv($saida, $linha);
}

fclose($saida);
mysqli_close($conexao);
