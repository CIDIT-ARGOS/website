<?php

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    erro("Método não suportado.", 405);
}

$resultado = mysqli_query($conexao, "
    SELECT id, nome, requer_observacao, ordem
    FROM motivos_falta
    WHERE ativo = 1
    ORDER BY ordem ASC
");

$motivos = [];
while ($linha = mysqli_fetch_assoc($resultado)) {
    $motivos[] = $linha;
}

responder($motivos);

mysqli_close($conexao);
