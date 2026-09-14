<?php

// Gestão da concessão de permissões por cargo (tabela `cargo_permissoes`, que
// já existia desde o início do projeto mas só era editável via SQL cru).
// Fecha a lacuna de "como sei/controlo o que cada cargo pode fazer" sem abrir
// o console técnico do Ikarus37.

function listarPermissoesCatalogo($conexao) {
    return mysqli_fetch_all(mysqli_query($conexao, "SELECT * FROM permissoes ORDER BY chave"), MYSQLI_ASSOC);
}

function listarPermissaoIdsDoCargo($conexao, $sistema, $cargo) {
    $stmt = mysqli_prepare($conexao, "SELECT permissao_id FROM cargo_permissoes WHERE sistema = ? AND cargo = ?");
    mysqli_stmt_bind_param($stmt, "ss", $sistema, $cargo);
    mysqli_stmt_execute($stmt);
    $resultado = mysqli_stmt_get_result($stmt);
    $ids = [];
    while ($linha = mysqli_fetch_assoc($resultado)) {
        $ids[] = (int) $linha['permissao_id'];
    }
    return $ids;
}

/**
 * Substitui por completo o conjunto de permissões de um (sistema, cargo) —
 * usado pela matriz de checkboxes: cada envio manda a lista final marcada.
 */
function definirPermissoesDoCargo($conexao, $sistema, $cargo, array $permissaoIds) {
    $stmt = mysqli_prepare($conexao, "DELETE FROM cargo_permissoes WHERE sistema = ? AND cargo = ?");
    mysqli_stmt_bind_param($stmt, "ss", $sistema, $cargo);
    mysqli_stmt_execute($stmt);

    $stmt = mysqli_prepare($conexao, "INSERT IGNORE INTO cargo_permissoes (sistema, cargo, permissao_id) VALUES (?, ?, ?)");
    foreach ($permissaoIds as $permissaoId) {
        $permissaoId = (int) $permissaoId;
        mysqli_stmt_bind_param($stmt, "ssi", $sistema, $cargo, $permissaoId);
        mysqli_stmt_execute($stmt);
    }

    return ['ok' => true];
}
