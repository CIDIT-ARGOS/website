<?php

// Lógica de negócio da retirada de falta. Usada tanto pela API (/api/retiradas.php)
// quanto pelas telas do painel — para não duplicar regra em dois lugares.

const TIPOS_RETIRADA_VALIDOS = ['1_jornada', '2_jornada', 'educacao_fisica', 'pernoite'];
const AGRUPAMENTOS_VALIDOS = ['esquadrilha', 'grupo']; // 'especialidade' ainda não implementado

/**
 * Abre uma nova retirada e já cria os itens (um por aluno do grupo), todos como "presente"
 * por padrão — a chamada em campo marca só as exceções (falta), como já é a lógica do Argos.
 *
 * @return array{ok: bool, erro?: string, retirada_id?: int}
 */
function abrirRetirada($conexao, $tipo, $agrupamentoTipo, $agrupamentoValor, $esquadrao, $alunoServicoId) {
    if (!in_array($tipo, TIPOS_RETIRADA_VALIDOS)) {
        return ['ok' => false, 'erro' => 'Tipo de retirada inválido.'];
    }
    if (!in_array($agrupamentoTipo, AGRUPAMENTOS_VALIDOS)) {
        return ['ok' => false, 'erro' => 'Tipo de agrupamento inválido ou ainda não suportado.'];
    }

    // ---------- Descobre o efetivo do grupo ----------
    if ($agrupamentoTipo === 'esquadrilha') {
        if (empty($esquadrao) || empty($agrupamentoValor)) {
            return ['ok' => false, 'erro' => 'Esquadrão e esquadrilha são obrigatórios para esse tipo de retirada.'];
        }
        $stmt = mysqli_prepare($conexao, "SELECT id FROM alunos WHERE esquadrao = ? AND esquadrilha = ? AND ativo = 1");
        mysqli_stmt_bind_param($stmt, "ss", $esquadrao, $agrupamentoValor);
    } else { // grupo
        $grupo = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT id FROM grupos WHERE nome = '" . mysqli_real_escape_string($conexao, $agrupamentoValor) . "' AND ativo = 1"));
        if (!$grupo) {
            return ['ok' => false, 'erro' => 'Grupo não encontrado.'];
        }
        $esquadrao = null;
        $stmt = mysqli_prepare($conexao, "
            SELECT a.id FROM alunos a
            JOIN grupo_membros gm ON gm.aluno_id = a.id
            WHERE gm.grupo_id = ? AND a.ativo = 1
        ");
        mysqli_stmt_bind_param($stmt, "i", $grupo['id']);
    }

    mysqli_stmt_execute($stmt);
    $resultado = mysqli_stmt_get_result($stmt);
    $alunoIds = [];
    while ($linha = mysqli_fetch_assoc($resultado)) {
        $alunoIds[] = $linha['id'];
    }

    if (empty($alunoIds)) {
        return ['ok' => false, 'erro' => 'Nenhum aluno ativo encontrado para esse agrupamento.'];
    }

    // ---------- Confere se o aluno de serviço pertence ao próprio agrupamento ----------
    if (!in_array((int)$alunoServicoId, $alunoIds)) {
        return ['ok' => false, 'erro' => 'O aluno de serviço precisa pertencer ao efetivo dessa retirada.'];
    }

    // ---------- Cria a retirada ----------
    $stmt = mysqli_prepare($conexao, "
        INSERT INTO retiradas (tipo, agrupamento_tipo, agrupamento_valor, esquadrao, aluno_servico_id, status)
        VALUES (?, ?, ?, ?, ?, 'pendente')
    ");
    mysqli_stmt_bind_param($stmt, "ssssi", $tipo, $agrupamentoTipo, $agrupamentoValor, $esquadrao, $alunoServicoId);
    mysqli_stmt_execute($stmt);
    $retiradaId = mysqli_insert_id($conexao);

    // ---------- Cria um item por aluno, presente por padrão ----------
    $stmtItem = mysqli_prepare($conexao, "INSERT INTO retirada_itens (retirada_id, aluno_id, presente) VALUES (?, ?, 1)");
    foreach ($alunoIds as $alunoId) {
        mysqli_stmt_bind_param($stmtItem, "ii", $retiradaId, $alunoId);
        mysqli_stmt_execute($stmtItem);
    }

    return ['ok' => true, 'retirada_id' => $retiradaId];
}

/**
 * Marca presença/falta de um aluno específico dentro de uma retirada já aberta.
 */
function marcarItem($conexao, $retiradaId, $alunoId, $presente, $motivoFaltaId, $observacao) {
    $motivoFaltaId = $presente ? null : $motivoFaltaId;

    $stmt = mysqli_prepare($conexao, "
        UPDATE retirada_itens SET presente = ?, motivo_falta_id = ?, observacao = ?
        WHERE retirada_id = ? AND aluno_id = ?
    ");
    mysqli_stmt_bind_param($stmt, "iisii", $presente, $motivoFaltaId, $observacao, $retiradaId, $alunoId);
    return mysqli_stmt_execute($stmt);
}

/**
 * Fecha a retirada: gera protocolo e marca como enviada.
 *
 * @return array{ok: bool, erro?: string, protocolo?: string}
 */
function enviarRetirada($conexao, $retiradaId) {
    $retirada = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT * FROM retiradas WHERE id = " . (int)$retiradaId));
    if (!$retirada) {
        return ['ok' => false, 'erro' => 'Retirada não encontrada.'];
    }
    if ($retirada['status'] === 'enviada') {
        return ['ok' => false, 'erro' => 'Essa retirada já foi enviada.', 'protocolo' => $retirada['protocolo']];
    }

    $protocolo = date('Ymd') . '-' . str_pad($retiradaId, 4, '0', STR_PAD_LEFT) . '-' . strtoupper(bin2hex(random_bytes(2)));

    $stmt = mysqli_prepare($conexao, "UPDATE retiradas SET status = 'enviada', protocolo = ?, enviada_em = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "si", $protocolo, $retiradaId);
    mysqli_stmt_execute($stmt);

    return ['ok' => true, 'protocolo' => $protocolo];
}
