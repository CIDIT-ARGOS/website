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

    // ---------- Confere só que o aluno de serviço existe e está ativo ----------
    // Não exige que pertença a este agrupamento: esquadrões mais antigos tiram serviço de
    // Aluno de Dia à Esquadrilha em OUTROS esquadrões, então quem identifica a retirada
    // pode legitimamente não ser do efetivo que está sendo chamado.
    $stmtServico = mysqli_prepare($conexao, "SELECT id FROM alunos WHERE id = ? AND ativo = 1");
    mysqli_stmt_bind_param($stmtServico, "i", $alunoServicoId);
    mysqli_stmt_execute($stmtServico);
    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($stmtServico))) {
        return ['ok' => false, 'erro' => 'Aluno de serviço inválido ou inativo.'];
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

function buscarRetiradaPorId($conexao, $id) {
    $stmt = mysqli_prepare($conexao, "SELECT * FROM retiradas WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

/**
 * $filtros aceitos: esquadrao, status, tipo, limite.
 */
function listarRetiradas($conexao, $filtros = []) {
    $condicoes = [];
    $params = [];
    $tipos = "";

    if (!empty($filtros['esquadrao'])) {
        $condicoes[] = "esquadrao = ?";
        $params[] = $filtros['esquadrao'];
        $tipos .= "s";
    }
    if (!empty($filtros['status'])) {
        $condicoes[] = "status = ?";
        $params[] = $filtros['status'];
        $tipos .= "s";
    }
    if (!empty($filtros['tipo'])) {
        $condicoes[] = "tipo = ?";
        $params[] = $filtros['tipo'];
        $tipos .= "s";
    }

    $where = $condicoes ? "WHERE " . implode(" AND ", $condicoes) : "";
    $limite = !empty($filtros['limite']) ? "LIMIT " . (int) $filtros['limite'] : "";

    $sql = "SELECT * FROM retiradas $where ORDER BY data_hora DESC $limite";
    $stmt = mysqli_prepare($conexao, $sql);
    if ($params) {
        mysqli_stmt_bind_param($stmt, $tipos, ...$params);
    }
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

function listarItensRetirada($conexao, $retiradaId) {
    $stmt = mysqli_prepare($conexao, "
        SELECT ri.*, a.nome_guerra, a.milhao, m.nome as motivo_nome
        FROM retirada_itens ri
        JOIN alunos a ON a.id = ri.aluno_id
        LEFT JOIN motivos_falta m ON m.id = ri.motivo_falta_id
        WHERE ri.retirada_id = ?
        ORDER BY a.nome_guerra
    ");
    mysqli_stmt_bind_param($stmt, "i", $retiradaId);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

/**
 * Monta a cláusula WHERE + params comuns aos três relatórios abaixo.
 * $filtros aceitos: data_inicio, data_fim (obrigatórios), esquadrao, tipo.
 */
function _filtroRelatorioRetiradas($filtros) {
    $condicoes = ["r.data_hora BETWEEN ? AND ?"];
    $params = [$filtros['data_inicio'] . " 00:00:00", $filtros['data_fim'] . " 23:59:59"];
    $tipos = "ss";

    if (!empty($filtros['esquadrao'])) {
        $condicoes[] = "a.esquadrao = ?";
        $params[] = $filtros['esquadrao'];
        $tipos .= "s";
    }
    if (!empty($filtros['tipo'])) {
        $condicoes[] = "r.tipo = ?";
        $params[] = $filtros['tipo'];
        $tipos .= "s";
    }

    return ["WHERE " . implode(" AND ", $condicoes), $params, $tipos];
}

function relatorioResumo($conexao, $filtros) {
    [$where, $params, $tipos] = _filtroRelatorioRetiradas($filtros);

    $sql = "
        SELECT COUNT(*) as total_itens, SUM(ri.presente) as total_presentes, SUM(1 - ri.presente) as total_faltas
        FROM retirada_itens ri
        JOIN retiradas r ON r.id = ri.retirada_id
        JOIN alunos a ON a.id = ri.aluno_id
        $where
    ";
    $stmt = mysqli_prepare($conexao, $sql);
    mysqli_stmt_bind_param($stmt, $tipos, ...$params);
    mysqli_stmt_execute($stmt);
    $resumo = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    $totalItens = (int) ($resumo['total_itens'] ?? 0);
    $totalPresentes = (int) ($resumo['total_presentes'] ?? 0);
    $totalFaltas = (int) ($resumo['total_faltas'] ?? 0);

    return [
        'total_itens' => $totalItens,
        'total_presentes' => $totalPresentes,
        'total_faltas' => $totalFaltas,
        'percentual_presenca' => $totalItens > 0 ? round($totalPresentes / $totalItens * 100, 1) : null,
    ];
}

function relatorioPorMotivo($conexao, $filtros) {
    [$where, $params, $tipos] = _filtroRelatorioRetiradas($filtros);

    $sql = "
        SELECT COALESCE(m.nome, 'Sem motivo') as motivo, COUNT(*) as total
        FROM retirada_itens ri
        JOIN retiradas r ON r.id = ri.retirada_id
        JOIN alunos a ON a.id = ri.aluno_id
        LEFT JOIN motivos_falta m ON m.id = ri.motivo_falta_id
        $where AND ri.presente = 0
        GROUP BY motivo
        ORDER BY total DESC
    ";
    $stmt = mysqli_prepare($conexao, $sql);
    mysqli_stmt_bind_param($stmt, $tipos, ...$params);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

function relatorioRetiradas($conexao, $filtros) {
    [$where, $params, $tipos] = _filtroRelatorioRetiradas($filtros);

    $sql = "
        SELECT
            r.id, r.tipo, r.agrupamento_tipo, r.agrupamento_valor, r.status, r.protocolo, r.data_hora,
            COUNT(ri.id) as total_itens, SUM(ri.presente) as presentes, SUM(1 - ri.presente) as faltas
        FROM retiradas r
        JOIN retirada_itens ri ON ri.retirada_id = r.id
        JOIN alunos a ON a.id = ri.aluno_id
        $where
        GROUP BY r.id
        ORDER BY r.data_hora DESC
    ";
    $stmt = mysqli_prepare($conexao, $sql);
    mysqli_stmt_bind_param($stmt, $tipos, ...$params);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}
