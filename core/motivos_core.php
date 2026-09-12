<?php

// Lógica de negócio dos motivos de falta. Usada tanto pela API (/api/motivos.php)
// quanto pelas telas do painel.

function listarMotivos($conexao) {
    $resultado = mysqli_query($conexao, "
        SELECT id, nome, requer_observacao, ordem
        FROM motivos_falta
        WHERE ativo = 1
        ORDER BY ordem ASC
    ");
    return mysqli_fetch_all($resultado, MYSQLI_ASSOC);
}

function buscarMotivoPorNome($conexao, $nome) {
    $stmt = mysqli_prepare($conexao, "SELECT * FROM motivos_falta WHERE nome = ?");
    mysqli_stmt_bind_param($stmt, "s", $nome);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

/**
 * Cria um motivo de falta com o nome informado, caso ainda não exista — usado
 * para que todo grupo (ex: CIDIT) também vire uma opção de motivo disponível
 * na chamada normal da esquadrilha.
 */
function criarMotivoSeNaoExiste($conexao, $nome) {
    $existente = buscarMotivoPorNome($conexao, $nome);
    if ($existente) {
        return $existente['id'];
    }

    $proximaOrdem = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT COALESCE(MAX(ordem), 0) + 1 as prox FROM motivos_falta"))['prox'];
    $stmt = mysqli_prepare($conexao, "INSERT INTO motivos_falta (nome, requer_observacao, ordem) VALUES (?, 0, ?)");
    mysqli_stmt_bind_param($stmt, "si", $nome, $proximaOrdem);
    mysqli_stmt_execute($stmt);
    return mysqli_insert_id($conexao);
}
