<?php

// Lógica de negócio dos usuários do painel de comando. Usada tanto pela API
// (/api/painel_usuarios.php) quanto pela tela painel/usuarios.php.
//
// Cargos não são mais uma lista fixa (ENUM) — vêm da tabela `cargos`
// (core/cargos_core.php), editável no Controle do Domínio de Negócio.

require_once __DIR__ . '/cargos_core.php';

function cargosEsquadrao($conexao) {
    return listarChavesCargos($conexao, 'painel', 'esquadrao');
}

function cargosCA($conexao) {
    return listarChavesCargos($conexao, 'painel', 'ca');
}

function todosOsCargosPainel($conexao) {
    return listarChavesCargos($conexao, 'painel');
}

// Nunca inclui senha_hash — nem a API nem o painel devem expor esse campo.
const COLUNAS_PUBLICAS_USUARIO_PAINEL = "id, nome, usuario, cargo, esquadrao, ativo, ultimo_login, criado_em";

function listarUsuariosPainel($conexao) {
    $resultado = mysqli_query($conexao, "SELECT " . COLUNAS_PUBLICAS_USUARIO_PAINEL . " FROM painel_usuarios ORDER BY criado_em ASC");
    return mysqli_fetch_all($resultado, MYSQLI_ASSOC);
}

function buscarUsuarioPainelPorId($conexao, $id) {
    $stmt = mysqli_prepare($conexao, "SELECT " . COLUNAS_PUBLICAS_USUARIO_PAINEL . " FROM painel_usuarios WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

function contarCmdCaAtivos($conexao) {
    $r = mysqli_query($conexao, "SELECT COUNT(*) as total FROM painel_usuarios WHERE cargo = 'CMD_CA' AND ativo = 1");
    return (int) mysqli_fetch_assoc($r)['total'];
}

/**
 * @return array{ok: bool, erro?: string, id?: int}
 */
function criarUsuarioPainel($conexao, $dados) {
    $nome = trim($dados['nome'] ?? '');
    $usuario = trim($dados['usuario'] ?? '');
    $senha = $dados['senha'] ?? '';
    $cargo = $dados['cargo'] ?? '';
    $esquadrao = trim($dados['esquadrao'] ?? '');

    if ($nome === '' || $usuario === '' || $senha === '') {
        return ['ok' => false, 'erro' => 'Preencha nome, usuário e senha.'];
    }
    if (!in_array($cargo, todosOsCargosPainel($conexao))) {
        return ['ok' => false, 'erro' => 'Cargo inválido.'];
    }
    if (in_array($cargo, cargosEsquadrao($conexao)) && $esquadrao === '') {
        return ['ok' => false, 'erro' => 'Cargos de esquadrão exigem informar o esquadrão.'];
    }

    $esquadraoFinal = in_array($cargo, cargosCA($conexao)) ? null : $esquadrao;

    $stmt = mysqli_prepare($conexao, "SELECT id FROM painel_usuarios WHERE usuario = ?");
    mysqli_stmt_bind_param($stmt, "s", $usuario);
    mysqli_stmt_execute($stmt);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        return ['ok' => false, 'erro' => 'Já existe um usuário com esse login.'];
    }

    $hash = password_hash($senha, PASSWORD_BCRYPT);
    $stmt = mysqli_prepare($conexao, "INSERT INTO painel_usuarios (nome, usuario, senha_hash, cargo, esquadrao) VALUES (?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "sssss", $nome, $usuario, $hash, $cargo, $esquadraoFinal);
    mysqli_stmt_execute($stmt);

    return ['ok' => true, 'id' => mysqli_insert_id($conexao)];
}

function atualizarUsuarioPainel($conexao, $id, $dados) {
    $nome = trim($dados['nome'] ?? '');
    $cargo = $dados['cargo'] ?? '';
    $esquadrao = trim($dados['esquadrao'] ?? '');
    $ativo = !empty($dados['ativo']) ? 1 : 0;

    $alvo = buscarUsuarioPainelPorId($conexao, $id);
    if (!$alvo) {
        return ['ok' => false, 'erro' => 'Usuário não encontrado.'];
    }
    if ($alvo['cargo'] === 'CMD_CA' && ($cargo !== 'CMD_CA' || $ativo === 0) && contarCmdCaAtivos($conexao) <= 1) {
        return ['ok' => false, 'erro' => 'Não é possível rebaixar ou desativar o último CMD_CA ativo.'];
    }
    if (in_array($cargo, cargosEsquadrao($conexao)) && $esquadrao === '') {
        return ['ok' => false, 'erro' => 'Cargos de esquadrão exigem informar o esquadrão.'];
    }

    $esquadraoFinal = in_array($cargo, cargosCA($conexao)) ? null : $esquadrao;
    $stmt = mysqli_prepare($conexao, "UPDATE painel_usuarios SET nome = ?, cargo = ?, esquadrao = ?, ativo = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "sssii", $nome, $cargo, $esquadraoFinal, $ativo, $id);
    mysqli_stmt_execute($stmt);

    return ['ok' => true];
}

function resetarSenhaUsuarioPainel($conexao, $id, $novaSenha) {
    if (strlen($novaSenha) < 6) {
        return ['ok' => false, 'erro' => 'A nova senha precisa ter pelo menos 6 caracteres.'];
    }
    $hash = password_hash($novaSenha, PASSWORD_BCRYPT);
    $stmt = mysqli_prepare($conexao, "UPDATE painel_usuarios SET senha_hash = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "si", $hash, $id);
    mysqli_stmt_execute($stmt);
    return ['ok' => true];
}

/**
 * $meuId: id de quem está executando a ação (sessão do painel). Passe null
 * quando não houver esse conceito (ex: chamada via API), pulando a checagem
 * de "não pode excluir a própria conta".
 */
function excluirUsuarioPainel($conexao, $id, $meuId = null) {
    if ($meuId !== null && $id === $meuId) {
        return ['ok' => false, 'erro' => 'Você não pode excluir a própria conta.'];
    }
    $alvo = buscarUsuarioPainelPorId($conexao, $id);
    if ($alvo && $alvo['cargo'] === 'CMD_CA' && contarCmdCaAtivos($conexao) <= 1) {
        return ['ok' => false, 'erro' => 'Não é possível excluir o último CMD_CA ativo.'];
    }
    $stmt = mysqli_prepare($conexao, "DELETE FROM painel_usuarios WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    return ['ok' => true];
}
