<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/cargos_core.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function _painelConexao() {
    static $conexao = null;
    if ($conexao === null) {
        $conexao = conectarBanco();
    }
    return $conexao;
}

// Id numérico do painel_usuario logado — ou null quando o login veio da ponte do
// Ikarus37 ('admin_5' etc, que não tem linha própria em painel_usuarios).
function idUsuarioPainel() {
    $id = $_SESSION['painel_id'] ?? null;
    return is_numeric($id) ? (int) $id : null;
}

// null = enxerga todos os esquadrões. String = restrito a um esquadrão.
function escopoEsquadrao() {
    if (temPermissao(_painelConexao(), 'painel', $_SESSION['painel_cargo'], 'ver_todos_esquadroes', idUsuarioPainel())) {
        return null;
    }
    return $_SESSION['painel_esquadrao'];
}

function podeGerenciarUsuarios() {
    return temPermissao(_painelConexao(), 'painel', $_SESSION['painel_cargo'], 'gerenciar_usuarios_painel', idUsuarioPainel());
}

function podeEditarEfetivo() {
    return temPermissao(_painelConexao(), 'painel', $_SESSION['painel_cargo'], 'editar_efetivo', idUsuarioPainel());
}

function podeGerenciarDominio() {
    return temPermissao(_painelConexao(), 'painel', $_SESSION['painel_cargo'], 'gerenciar_dominio', idUsuarioPainel());
}

function nomeCargo($cargo) {
    $linha = buscarCargoPorChave(_painelConexao(), 'painel', $cargo);
    return $linha ? $linha['nome'] : $cargo;
}
