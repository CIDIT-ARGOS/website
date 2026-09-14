<?php

// Grupos de acesso estilo POSIX/UNIX: um painel_usuario pode pertencer a vários
// grupos, e cada grupo concede permissões (opcionalmente restritas a uma unidade
// organizacional). Não confundir com a tabela `grupos` (agrupamentos de retirada
// tipo CIDIT) — conceito totalmente diferente, mesmo nome só por coincidência de
// vocabulário em português.
//
// A checagem em si (temPermissao -> _temPermissaoViaGrupoAcesso) vive em
// core/config.php, porque precisa estar disponível desde o primeiro require do
// projeto. Este arquivo cobre a administração (CRUD) usada pelo Controle do
// Domínio de Negócio.

function listarGruposAcesso($conexao, $incluirInativos = false) {
    $where = $incluirInativos ? "" : "WHERE ativo = 1";
    $resultado = mysqli_query($conexao, "SELECT * FROM grupos_acesso $where ORDER BY nome");
    return mysqli_fetch_all($resultado, MYSQLI_ASSOC);
}

function buscarGrupoAcessoPorId($conexao, $id) {
    $stmt = mysqli_prepare($conexao, "SELECT * FROM grupos_acesso WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

function criarGrupoAcesso($conexao, $nome, $descricao) {
    $nome = trim($nome);
    if ($nome === '') {
        return ['ok' => false, 'erro' => 'Informe o nome do grupo.'];
    }

    $stmt = mysqli_prepare($conexao, "SELECT id FROM grupos_acesso WHERE nome = ?");
    mysqli_stmt_bind_param($stmt, "s", $nome);
    mysqli_stmt_execute($stmt);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        return ['ok' => false, 'erro' => 'Já existe um grupo de acesso com esse nome.'];
    }

    $descricao = trim($descricao ?? '') ?: null;
    $stmt = mysqli_prepare($conexao, "INSERT INTO grupos_acesso (nome, descricao) VALUES (?, ?)");
    mysqli_stmt_bind_param($stmt, "ss", $nome, $descricao);
    mysqli_stmt_execute($stmt);
    return ['ok' => true, 'id' => mysqli_insert_id($conexao)];
}

function atualizarGrupoAcesso($conexao, $id, $nome, $descricao, $ativo) {
    $nome = trim($nome);
    if ($nome === '') {
        return ['ok' => false, 'erro' => 'Informe o nome do grupo.'];
    }
    $descricao = trim($descricao ?? '') ?: null;
    $ativo = $ativo ? 1 : 0;

    $stmt = mysqli_prepare($conexao, "UPDATE grupos_acesso SET nome = ?, descricao = ?, ativo = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "ssii", $nome, $descricao, $ativo, $id);
    if (!mysqli_stmt_execute($stmt)) {
        return ['ok' => false, 'erro' => 'Erro ao atualizar grupo: ' . mysqli_error($conexao)];
    }
    return ['ok' => true];
}

function excluirGrupoAcesso($conexao, $id) {
    $stmt = mysqli_prepare($conexao, "UPDATE grupos_acesso SET ativo = 0 WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    return ['ok' => true];
}

// ---------- Membros ----------

function listarMembrosGrupoAcesso($conexao, $grupoAcessoId) {
    $stmt = mysqli_prepare($conexao, "
        SELECT gm.id, gm.painel_usuario_id, gm.unidade_id, pu.nome, pu.usuario, pu.cargo, u.nome as unidade_nome
        FROM grupo_acesso_membros gm
        JOIN painel_usuarios pu ON pu.id = gm.painel_usuario_id
        LEFT JOIN unidades u ON u.id = gm.unidade_id
        WHERE gm.grupo_acesso_id = ?
        ORDER BY pu.nome
    ");
    mysqli_stmt_bind_param($stmt, "i", $grupoAcessoId);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

function adicionarMembroGrupoAcesso($conexao, $grupoAcessoId, $painelUsuarioId, $unidadeId = null) {
    $stmt = mysqli_prepare($conexao, "INSERT IGNORE INTO grupo_acesso_membros (grupo_acesso_id, painel_usuario_id, unidade_id) VALUES (?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "iii", $grupoAcessoId, $painelUsuarioId, $unidadeId);
    return mysqli_stmt_execute($stmt);
}

function removerMembroGrupoAcesso($conexao, $membroId) {
    $stmt = mysqli_prepare($conexao, "DELETE FROM grupo_acesso_membros WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $membroId);
    return mysqli_stmt_execute($stmt);
}

// ---------- Permissões concedidas ----------

function listarPermissoesGrupoAcesso($conexao, $grupoAcessoId) {
    $stmt = mysqli_prepare($conexao, "
        SELECT gp.id, gp.permissao_id, gp.unidade_id, p.chave, p.descricao, u.nome as unidade_nome
        FROM grupo_acesso_permissoes gp
        JOIN permissoes p ON p.id = gp.permissao_id
        LEFT JOIN unidades u ON u.id = gp.unidade_id
        WHERE gp.grupo_acesso_id = ?
        ORDER BY p.chave
    ");
    mysqli_stmt_bind_param($stmt, "i", $grupoAcessoId);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

function concederPermissaoGrupoAcesso($conexao, $grupoAcessoId, $permissaoId, $unidadeId = null) {
    $stmt = mysqli_prepare($conexao, "INSERT IGNORE INTO grupo_acesso_permissoes (grupo_acesso_id, permissao_id, unidade_id) VALUES (?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "iii", $grupoAcessoId, $permissaoId, $unidadeId);
    return mysqli_stmt_execute($stmt);
}

function revogarPermissaoGrupoAcesso($conexao, $concessaoId) {
    $stmt = mysqli_prepare($conexao, "DELETE FROM grupo_acesso_permissoes WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $concessaoId);
    return mysqli_stmt_execute($stmt);
}

function listarTodasPermissoes($conexao) {
    $resultado = mysqli_query($conexao, "SELECT * FROM permissoes ORDER BY chave");
    return mysqli_fetch_all($resultado, MYSQLI_ASSOC);
}
