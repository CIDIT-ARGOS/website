<?php

// Dispensas médicas: período (início/término), número da dispensa, motivo e
// "dispensado de quê" — mesmos campos do Livro de Serviço do Aluno de Dia.
// Usada pela tela de gestão (dispensas.php), pela sugestão automática na
// chamada (retiradas_core.php::abrirRetirada) e pelo Livro do Dia.

function listarDispensaTipos($conexao) {
    $resultado = mysqli_query($conexao, "SELECT * FROM dispensa_tipos WHERE ativo = 1 ORDER BY ordem");
    return mysqli_fetch_all($resultado, MYSQLI_ASSOC);
}

/**
 * Substitui as tags "dispensado de" de uma dispensa pelo conjunto informado.
 */
function definirTagsDispensa($conexao, $dispensaId, array $tipoIds) {
    $stmt = mysqli_prepare($conexao, "DELETE FROM dispensa_dispensa_tipos WHERE dispensa_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $dispensaId);
    mysqli_stmt_execute($stmt);

    $stmt = mysqli_prepare($conexao, "INSERT IGNORE INTO dispensa_dispensa_tipos (dispensa_id, dispensa_tipo_id) VALUES (?, ?)");
    foreach ($tipoIds as $tipoId) {
        $tipoId = (int) $tipoId;
        if ($tipoId <= 0) {
            continue;
        }
        mysqli_stmt_bind_param($stmt, "ii", $dispensaId, $tipoId);
        mysqli_stmt_execute($stmt);
    }
}

function listarTagsDispensa($conexao, $dispensaId) {
    $stmt = mysqli_prepare($conexao, "
        SELECT dt.id, dt.nome
        FROM dispensa_dispensa_tipos ddt
        JOIN dispensa_tipos dt ON dt.id = ddt.dispensa_tipo_id
        WHERE ddt.dispensa_id = ?
        ORDER BY dt.ordem
    ");
    mysqli_stmt_bind_param($stmt, "i", $dispensaId);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

function listarDispensas($conexao, $filtros = []) {
    $condicoes = [];
    $params = [];
    $tipos = "";

    if (!empty($filtros['aluno_id'])) {
        $condicoes[] = "d.aluno_id = ?";
        $params[] = (int) $filtros['aluno_id'];
        $tipos .= "i";
    }
    if (!empty($filtros['esquadrao'])) {
        $condicoes[] = "a.esquadrao = ?";
        $params[] = $filtros['esquadrao'];
        $tipos .= "s";
    }
    if (!empty($filtros['ativas_em'])) {
        $condicoes[] = "d.data_inicio <= ? AND d.data_termino >= ?";
        $params[] = $filtros['ativas_em'];
        $params[] = $filtros['ativas_em'];
        $tipos .= "ss";
    }

    $where = $condicoes ? "WHERE " . implode(" AND ", $condicoes) : "";

    $sql = "
        SELECT d.*, a.posto_graduacao, COALESCE(p.exibicao, a.posto_graduacao) AS posto_exibicao,
               a.especialidade, a.nome_guerra, a.milhao, a.esquadrao, a.esquadrilha,
               (SELECT GROUP_CONCAT(dt.nome ORDER BY dt.ordem SEPARATOR ', ')
                FROM dispensa_dispensa_tipos ddt
                JOIN dispensa_tipos dt ON dt.id = ddt.dispensa_tipo_id
                WHERE ddt.dispensa_id = d.id) AS tags_nomes
        FROM dispensas d
        JOIN alunos a ON a.id = d.aluno_id
        LEFT JOIN postos_graduacao p ON p.codigo = a.posto_graduacao
        $where
        ORDER BY d.data_inicio DESC
    ";
    $stmt = mysqli_prepare($conexao, $sql);
    if ($params) {
        mysqli_stmt_bind_param($stmt, $tipos, ...$params);
    }
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

function buscarDispensaPorId($conexao, $id) {
    $stmt = mysqli_prepare($conexao, "
        SELECT d.*, a.posto_graduacao, COALESCE(p.exibicao, a.posto_graduacao) AS posto_exibicao,
               a.especialidade, a.nome_guerra, a.milhao, a.esquadrao, a.esquadrilha,
               (SELECT GROUP_CONCAT(dt.nome ORDER BY dt.ordem SEPARATOR ', ')
                FROM dispensa_dispensa_tipos ddt
                JOIN dispensa_tipos dt ON dt.id = ddt.dispensa_tipo_id
                WHERE ddt.dispensa_id = d.id) AS tags_nomes
        FROM dispensas d
        JOIN alunos a ON a.id = d.aluno_id
        LEFT JOIN postos_graduacao p ON p.codigo = a.posto_graduacao
        WHERE d.id = ?
    ");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

/**
 * Dispensa ativa de um aluno numa data específica (ou null). Usada pra
 * sugerir automaticamente o motivo DMED ao abrir uma retirada, e pra montar
 * a seção de Ocorrências Médicas do Livro do Dia.
 */
function dispensaAtivaParaAluno($conexao, $alunoId, $data) {
    $stmt = mysqli_prepare($conexao, "
        SELECT * FROM dispensas
        WHERE aluno_id = ? AND data_inicio <= ? AND data_termino >= ?
        ORDER BY data_inicio DESC
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, "iss", $alunoId, $data, $data);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

/**
 * @return array{ok: bool, erro?: string, id?: int}
 */
function criarDispensa($conexao, $dados) {
    $alunoId = (int) ($dados['aluno_id'] ?? 0);
    $dataInicio = trim($dados['data_inicio'] ?? '');
    $dataTermino = trim($dados['data_termino'] ?? '');
    $numero = trim($dados['numero'] ?? '') ?: null;
    $motivo = trim($dados['motivo'] ?? '');
    $dispensadoDe = trim($dados['dispensado_de'] ?? '') ?: null;
    $painelUsuarioId = $dados['painel_usuario_id'] ?? null;

    if ($alunoId <= 0) {
        return ['ok' => false, 'erro' => 'Escolha o aluno.'];
    }
    if ($dataInicio === '' || $dataTermino === '') {
        return ['ok' => false, 'erro' => 'Informe início e término da dispensa.'];
    }
    if ($dataTermino < $dataInicio) {
        return ['ok' => false, 'erro' => 'O término não pode ser antes do início.'];
    }
    if ($motivo === '') {
        return ['ok' => false, 'erro' => 'Informe o motivo da dispensa.'];
    }

    $stmt = mysqli_prepare($conexao, "
        INSERT INTO dispensas (aluno_id, data_inicio, data_termino, numero, motivo, dispensado_de, painel_usuario_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    mysqli_stmt_bind_param($stmt, "isssssi", $alunoId, $dataInicio, $dataTermino, $numero, $motivo, $dispensadoDe, $painelUsuarioId);
    if (!mysqli_stmt_execute($stmt)) {
        return ['ok' => false, 'erro' => 'Erro ao criar dispensa: ' . mysqli_error($conexao)];
    }
    $novoId = mysqli_insert_id($conexao);
    definirTagsDispensa($conexao, $novoId, $dados['dispensa_tipo_ids'] ?? []);
    return ['ok' => true, 'id' => $novoId];
}

function atualizarDispensa($conexao, $id, $dados) {
    $dataInicio = trim($dados['data_inicio'] ?? '');
    $dataTermino = trim($dados['data_termino'] ?? '');
    $numero = trim($dados['numero'] ?? '') ?: null;
    $motivo = trim($dados['motivo'] ?? '');
    $dispensadoDe = trim($dados['dispensado_de'] ?? '') ?: null;

    if ($dataInicio === '' || $dataTermino === '') {
        return ['ok' => false, 'erro' => 'Informe início e término da dispensa.'];
    }
    if ($dataTermino < $dataInicio) {
        return ['ok' => false, 'erro' => 'O término não pode ser antes do início.'];
    }
    if ($motivo === '') {
        return ['ok' => false, 'erro' => 'Informe o motivo da dispensa.'];
    }

    $stmt = mysqli_prepare($conexao, "
        UPDATE dispensas SET data_inicio = ?, data_termino = ?, numero = ?, motivo = ?, dispensado_de = ?
        WHERE id = ?
    ");
    mysqli_stmt_bind_param($stmt, "sssssi", $dataInicio, $dataTermino, $numero, $motivo, $dispensadoDe, $id);
    if (!mysqli_stmt_execute($stmt)) {
        return ['ok' => false, 'erro' => 'Erro ao atualizar dispensa: ' . mysqli_error($conexao)];
    }
    definirTagsDispensa($conexao, $id, $dados['dispensa_tipo_ids'] ?? []);
    return ['ok' => true];
}

function excluirDispensa($conexao, $id) {
    $stmt = mysqli_prepare($conexao, "DELETE FROM dispensas WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    if (!mysqli_stmt_execute($stmt)) {
        return ['ok' => false, 'erro' => 'Erro ao excluir dispensa: ' . mysqli_error($conexao)];
    }
    return ['ok' => true];
}
