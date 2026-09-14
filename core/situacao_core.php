<?php

// Situação atual de cada aluno: presente ou ausente (e por qual motivo), a
// partir da retirada mais recente em que ele apareceu. Não é "tempo real" no
// sentido de ida/volta com horário (isso pede um tipo de registro que ainda
// não existe) — é a melhor resposta possível hoje pra "onde esse aluno está
// agora?", a partir do que já é registrado nas chamadas normais.
//
// Usada tanto pela API (/api/situacao.php) quanto pelo painel — a API é a
// fonte de verdade; o painel só chama essas mesmas funções.

function situacaoAtualAlunos($conexao, $filtros = []) {
    $condicoes = ["a.ativo = 1"];
    $params = [];
    $tipos = "";

    if (!empty($filtros['esquadrao'])) {
        $condicoes[] = "a.esquadrao = ?";
        $params[] = $filtros['esquadrao'];
        $tipos .= "s";
    }
    if (!empty($filtros['esquadrilha'])) {
        $condicoes[] = "a.esquadrilha = ?";
        $params[] = $filtros['esquadrilha'];
        $tipos .= "s";
    }
    if (!empty($filtros['busca'])) {
        $condicoes[] = "(a.nome_guerra LIKE ? OR a.milhao LIKE ?)";
        $termo = "%" . $filtros['busca'] . "%";
        $params[] = $termo;
        $params[] = $termo;
        $tipos .= "ss";
    }
    if (!empty($filtros['somente_ausentes'])) {
        $condicoes[] = "u.presente = 0";
    }

    $where = "WHERE " . implode(" AND ", $condicoes);

    $sql = "
        SELECT
            a.id, a.posto_graduacao, COALESCE(p.exibicao, a.posto_graduacao) AS posto_exibicao,
            a.especialidade, a.nome_guerra, a.milhao, a.esquadrao, a.esquadrilha, a.curso, a.serie,
            u.presente, u.observacao, u.motivo_nome, u.motivo_codigo, u.motivo_classificacao, u.tipo AS retirada_tipo, u.data_hora AS retirada_data_hora
        FROM alunos a
        LEFT JOIN postos_graduacao p ON p.codigo = a.posto_graduacao
        LEFT JOIN (
            SELECT x.aluno_id, x.presente, x.observacao, mf.nome AS motivo_nome, mf.codigo AS motivo_codigo, mf.classificacao AS motivo_classificacao, x.tipo, x.data_hora
            FROM (
                SELECT ri.aluno_id, ri.presente, ri.observacao, ri.motivo_falta_id, r.tipo, r.data_hora,
                       ROW_NUMBER() OVER (PARTITION BY ri.aluno_id ORDER BY r.data_hora DESC) AS rn
                FROM retirada_itens ri
                JOIN retiradas r ON r.id = ri.retirada_id
                WHERE r.status = 'enviada'
            ) x
            LEFT JOIN motivos_falta mf ON mf.id = x.motivo_falta_id
            WHERE x.rn = 1
        ) u ON u.aluno_id = a.id
        $where
        ORDER BY a.esquadrao, a.esquadrilha, a.nome_guerra
    ";

    $stmt = mysqli_prepare($conexao, $sql);
    if ($params) {
        mysqli_stmt_bind_param($stmt, $tipos, ...$params);
    }
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

/**
 * KPIs sobre a mesma lista (respeita os mesmos filtros, exceto somente_ausentes).
 */
function situacaoResumo($conexao, $filtros = []) {
    unset($filtros['somente_ausentes']);
    $lista = situacaoAtualAlunos($conexao, $filtros);

    $ausentes = 0;
    $semRegistro = 0;
    $porMotivo = [];

    foreach ($lista as $a) {
        if ($a['presente'] === null) {
            $semRegistro++;
            continue;
        }
        if ((int) $a['presente'] === 0) {
            $ausentes++;
            $motivo = $a['motivo_nome'] ?? 'Sem motivo';
            $porMotivo[$motivo] = ($porMotivo[$motivo] ?? 0) + 1;
        }
    }
    arsort($porMotivo);

    $total = count($lista);

    return [
        'total_efetivo' => $total,
        'presentes' => $total - $ausentes - $semRegistro,
        'ausentes' => $ausentes,
        'sem_registro' => $semRegistro,
        'por_motivo' => $porMotivo,
    ];
}
