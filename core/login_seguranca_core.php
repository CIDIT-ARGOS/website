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

// ---------------------------------------------------------------------
// Bloqueio por IP — complementa o bloqueio por conta acima. Sozinho, o
// bloqueio por conta não trava um password-spray (1 senha comum testada
// em muitos usuários diferentes: nenhuma conta individual chega ao limite
// de tentativas). Isso aqui trava por IP de origem, independente de qual
// usuário está sendo tentado (issue #23).
// ---------------------------------------------------------------------

const LOGIN_IP_MAX_TENTATIVAS = 20;
const LOGIN_IP_BLOQUEIO_MINUTOS = 15;

/**
 * Se o IP estiver bloqueado agora, devolve o DATETIME (string) até quando.
 * Senão, null.
 */
function loginIpBloqueado($conexao, $ip) {
    if ($ip === '') {
        return null;
    }
    $stmt = mysqli_prepare($conexao, "SELECT bloqueado_ate FROM login_ip_tentativas WHERE ip = ?");
    mysqli_stmt_bind_param($stmt, "s", $ip);
    mysqli_stmt_execute($stmt);
    $linha = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if ($linha && $linha['bloqueado_ate'] && $linha['bloqueado_ate'] > date('Y-m-d H:i:s')) {
        return $linha['bloqueado_ate'];
    }
    return null;
}

/**
 * Chame após uma senha errada, seja qual for o usuário tentado. Cria a
 * linha do IP se ainda não existir, incrementa e bloqueia ao atingir o
 * limite — mesmo padrão do bloqueio por conta, só que por IP.
 */
function loginRegistrarFalhaIp($conexao, $ip) {
    if ($ip === '') {
        return;
    }
    $stmt = mysqli_prepare($conexao, "
        INSERT INTO login_ip_tentativas (ip, tentativas, bloqueado_ate)
        VALUES (?, 1, NULL)
        ON DUPLICATE KEY UPDATE
            tentativas = tentativas + 1,
            bloqueado_ate = IF(tentativas + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), bloqueado_ate)
    ");
    $maxTentativas = LOGIN_IP_MAX_TENTATIVAS;
    $bloqueioMinutos = LOGIN_IP_BLOQUEIO_MINUTOS;
    mysqli_stmt_bind_param($stmt, "sii", $ip, $maxTentativas, $bloqueioMinutos);
    mysqli_stmt_execute($stmt);
}

/**
 * Chame após um login bem-sucedido a partir desse IP.
 */
function loginResetarTentativasIp($conexao, $ip) {
    if ($ip === '') {
        return;
    }
    $stmt = mysqli_prepare($conexao, "UPDATE login_ip_tentativas SET tentativas = 0, bloqueado_ate = NULL WHERE ip = ?");
    mysqli_stmt_bind_param($stmt, "s", $ip);
    mysqli_stmt_execute($stmt);
}
