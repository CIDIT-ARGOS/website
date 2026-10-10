<?php

// Postos de serviço (ex: Aluno de Dia, Plantão, Sentinela): catálogo editável
// em Controle do Domínio de Negócio → Postos de serviço. Quando o motivo da
// ausência numa chamada é "Serviço", o posto diz onde o aluno está.
// Usado pela chamada do Painel e pela API (/api/postos_servico.php).

/**
 * Postos ativos, na ordem do cadastro. Se a tabela ainda não existir (banco
 * sem a migration 014), devolve lista vazia em vez de derrubar a tela.
 */
function listarServicos($conexao) {
    $resultado = mysqli_query($conexao, "SELECT id, nome, codigo, ativo FROM postos_servico WHERE ativo = 1 ORDER BY ordem, nome");
    if (!$resultado) {
        return [];
    }
    return mysqli_fetch_all($resultado, MYSQLI_ASSOC);
}

function buscarServicoPorId($conexao, $id) {
    $stmt = mysqli_prepare($conexao, "SELECT * FROM postos_servico WHERE id = ? AND ativo = 1");
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}
