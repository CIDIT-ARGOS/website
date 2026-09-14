<?php

// Árvore organizacional: EEAR { DEF { Galpões }, CA { Doutrina, Esquadrões } }.
// Usada pelo Controle do Domínio de Negócio e pelo sistema de grupos de acesso
// (grupos_acesso_core.php) pra restringir concessões a uma unidade específica.

const TIPOS_UNIDADE_VALIDOS = ['escola', 'divisao', 'galpao', 'ca', 'esquadrao', 'doutrina'];

function listarUnidades($conexao, $incluirInativas = false) {
    $where = $incluirInativas ? "" : "WHERE ativo = 1";
    $resultado = mysqli_query($conexao, "SELECT * FROM unidades $where ORDER BY tipo, nome");
    return mysqli_fetch_all($resultado, MYSQLI_ASSOC);
}

/**
 * Mesma lista, mas organizada em árvore (cada unidade ganha um array 'filhos').
 * Unidades órfãs (pai inativo/removido) aparecem soltas na raiz.
 */
function listarUnidadesEmArvore($conexao, $incluirInativas = false) {
    $todas = listarUnidades($conexao, $incluirInativas);
    $porId = [];
    foreach ($todas as $u) {
        $u['filhos'] = [];
        $porId[$u['id']] = $u;
    }

    $raizes = [];
    foreach ($porId as $id => $u) {
        $paiId = $u['unidade_pai_id'];
        if ($paiId !== null && isset($porId[$paiId])) {
            $porId[$paiId]['filhos'][] = &$porId[$id];
        } else {
            $raizes[] = &$porId[$id];
        }
    }
    return $raizes;
}

function buscarUnidadePorId($conexao, $id) {
    $stmt = mysqli_prepare($conexao, "SELECT * FROM unidades WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

/**
 * @return array{ok: bool, erro?: string, id?: int}
 */
function criarUnidade($conexao, $nome, $tipo, $unidadePaiId) {
    $nome = trim($nome);
    if ($nome === '') {
        return ['ok' => false, 'erro' => 'Informe o nome da unidade.'];
    }
    if (!in_array($tipo, TIPOS_UNIDADE_VALIDOS)) {
        return ['ok' => false, 'erro' => 'Tipo de unidade inválido.'];
    }
    if ($unidadePaiId !== null && !buscarUnidadePorId($conexao, $unidadePaiId)) {
        return ['ok' => false, 'erro' => 'Unidade pai não encontrada.'];
    }

    $stmt = mysqli_prepare($conexao, "INSERT INTO unidades (nome, tipo, unidade_pai_id) VALUES (?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "ssi", $nome, $tipo, $unidadePaiId);
    if (!mysqli_stmt_execute($stmt)) {
        return ['ok' => false, 'erro' => 'Erro ao criar unidade: ' . mysqli_error($conexao)];
    }
    return ['ok' => true, 'id' => mysqli_insert_id($conexao)];
}

function atualizarUnidade($conexao, $id, $nome, $tipo, $unidadePaiId, $ativo) {
    $nome = trim($nome);
    if ($nome === '') {
        return ['ok' => false, 'erro' => 'Informe o nome da unidade.'];
    }
    if (!in_array($tipo, TIPOS_UNIDADE_VALIDOS)) {
        return ['ok' => false, 'erro' => 'Tipo de unidade inválido.'];
    }
    if ($unidadePaiId !== null && $unidadePaiId === $id) {
        return ['ok' => false, 'erro' => 'Uma unidade não pode ser pai dela mesma.'];
    }

    $ativo = $ativo ? 1 : 0;
    $stmt = mysqli_prepare($conexao, "UPDATE unidades SET nome = ?, tipo = ?, unidade_pai_id = ?, ativo = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "ssiii", $nome, $tipo, $unidadePaiId, $ativo, $id);
    if (!mysqli_stmt_execute($stmt)) {
        return ['ok' => false, 'erro' => 'Erro ao atualizar unidade: ' . mysqli_error($conexao)];
    }
    return ['ok' => true];
}

function excluirUnidade($conexao, $id) {
    $stmt = mysqli_prepare($conexao, "SELECT COUNT(*) as total FROM unidades WHERE unidade_pai_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $temFilhos = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'] > 0;
    if ($temFilhos) {
        return ['ok' => false, 'erro' => 'Essa unidade tem unidades filhas — inative ou mova as filhas primeiro.'];
    }

    $stmt = mysqli_prepare($conexao, "UPDATE unidades SET ativo = 0 WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    return ['ok' => true];
}
