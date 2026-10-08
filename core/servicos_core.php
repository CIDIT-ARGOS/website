<?php

// Lógica de negócio dos motivos de falta. Usada tanto pela API (/api/motivos.php)
// quanto pelas telas do painel.

function listarServicos($conexao) {
    $resultado = mysqli_query($conexao, "
        SELECT id, nome, ativo FROM postos_servico
    ");
    return mysqli_fetch_all($resultado, MYSQLI_ASSOC);
}

function buscarServicoPorNome($conexao, $nome) {
    $stmt = mysqli_prepare($conexao, "SELECT * FROM postos_servico WHERE nome = ?");
    mysqli_stmt_bind_param($stmt, "s", $nome);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

function buscarServicosPorCodigo($conexao, $codigo) {
    $stmt = mysqli_prepare($conexao, "SELECT * FROM postos_servico WHERE codigo = ? AND ativo = 1");
    mysqli_stmt_bind_param($stmt, "s", $codigo);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}
?>