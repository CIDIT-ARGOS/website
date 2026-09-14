<?php

// Dispensas médicas: período (início/término), número da dispensa, motivo e
// "dispensado de quê" — mesmos campos do Livro de Serviço do Aluno de Dia.
// Usada pela tela de gestão (dispensas.php), pela sugestão automática na
// chamada (retiradas_core.php::abrirRetirada) e pelo Livro do Dia.

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
        SELECT d.*, a.nome_guerra, a.milhao, a.esquadrao, a.esquadrilha
        FROM dispensas d
        JOIN alunos a ON a.id = d.aluno_id
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
        SELECT d.*, a.nome_guerra, a.milhao, a.esquadrao, a.esquadrilha
        FROM dispensas d
        JOIN alunos a ON a.id = d.aluno_id
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
    return ['ok' => true, 'id' => mysqli_insert_id($conexao)];
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
