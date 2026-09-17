<?php

// Proteção contra força bruta no login (painel e ikarus37) — bloqueio
// temporário por conta após tentativas seguidas erradas. Usa as colunas
// tentativas_login/bloqueado_ate de admin_usuarios e painel_usuarios
// (database/migration_010_protecao_forca_bruta.sql).
//
// $tabela é sempre uma das duas strings fixas abaixo, escritas em código —
// nunca vem de input do usuário, então interpolar no SQL aqui é seguro
// (mesmo padrão já usado em core/dominio_core.php pra nome de tabela/coluna).

const LOGIN_TABELAS_VALIDAS = ['admin_usuarios', 'painel_usuarios'];
const LOGIN_MAX_TENTATIVAS = 5;
const LOGIN_BLOQUEIO_MINUTOS = 15;

/**
 * Se a conta estiver bloqueada agora, devolve o DATETIME (string) até quando.
 * Senão, devolve null — inclui o caso de bloqueio já vencido (não bloqueia
 * de novo só por tentativas antigas, mas o contador só zera no login OK).
 */
function loginContaBloqueada($conexao, $tabela, $usuario) {
    if (!in_array($tabela, LOGIN_TABELAS_VALIDAS)) {
        return null;
    }
    $stmt = mysqli_prepare($conexao, "SELECT bloqueado_ate FROM `$tabela` WHERE usuario = ?");
    mysqli_stmt_bind_param($stmt, "s", $usuario);
    mysqli_stmt_execute($stmt);
    $linha = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if ($linha && $linha['bloqueado_ate'] && $linha['bloqueado_ate'] > date('Y-m-d H:i:s')) {
        return $linha['bloqueado_ate'];
    }
    return null;
}

/**
 * Chame após uma senha errada. Incrementa o contador da conta e, ao atingir
 * o limite, bloqueia por LOGIN_BLOQUEIO_MINUTOS a partir de agora.
 */
function loginRegistrarFalha($conexao, $tabela, $usuario) {
    if (!in_array($tabela, LOGIN_TABELAS_VALIDAS)) {
        return;
    }
    $stmt = mysqli_prepare($conexao, "
        UPDATE `$tabela`
        SET tentativas_login = tentativas_login + 1,
            bloqueado_ate = IF(tentativas_login + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), bloqueado_ate)
        WHERE usuario = ?
    ");
    $maxTentativas = LOGIN_MAX_TENTATIVAS;
    $bloqueioMinutos = LOGIN_BLOQUEIO_MINUTOS;
    mysqli_stmt_bind_param($stmt, "iis", $maxTentativas, $bloqueioMinutos, $usuario);
    mysqli_stmt_execute($stmt);

    // Atraso pequeno mesmo antes do bloqueio valer — encarece automação sem
    // atrapalhar quem errou a senha uma vez de verdade.
    usleep(400000);
}

/**
 * Chame após um login bem-sucedido — zera o contador e qualquer bloqueio.
 */
function loginResetarTentativas($conexao, $tabela, $id) {
    if (!in_array($tabela, LOGIN_TABELAS_VALIDAS)) {
        return;
    }
    $stmt = mysqli_prepare($conexao, "UPDATE `$tabela` SET tentativas_login = 0, bloqueado_ate = NULL WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
}
