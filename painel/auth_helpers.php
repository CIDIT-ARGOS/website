<?php

require_once __DIR__ . '/../config.php';

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

// null = enxerga todos os esquadrões. String = restrito a um esquadrão.
function escopoEsquadrao() {
    if (temPermissao(_painelConexao(), 'painel', $_SESSION['painel_cargo'], 'ver_todos_esquadroes')) {
        return null;
    }
    return $_SESSION['painel_esquadrao'];
}

function podeGerenciarUsuarios() {
    return temPermissao(_painelConexao(), 'painel', $_SESSION['painel_cargo'], 'gerenciar_usuarios_painel');
}

function podeEditarEfetivo() {
    return temPermissao(_painelConexao(), 'painel', $_SESSION['painel_cargo'], 'editar_efetivo');
}

function nomeCargo($cargo) {
    $nomes = [
        'CMD_CA' => 'Comandante do CA',
        'SUBCMD_CA' => 'Subcomandante do CA',
        'ADMIN_TECNICO' => 'Administrador Técnico',
        'AUX_CA' => 'Auxiliar CA',
        'CMD_ESQUADRAO' => 'Comandante de Esquadrão',
        'ENC_ESQUADRAO' => 'Encarregado de Esquadrão',
        'AUX_ESQUADRAO' => 'Auxiliar de Esquadrão',
    ];
    return $nomes[$cargo] ?? $cargo;
}
