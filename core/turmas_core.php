<?php

// Turmas: leva de alunos que entrou junto e vai se formar (sair da escola)
// junto. "nome" (apelido, ex: "Fera Azul") é o identificador — não curso+
// série, porque pode existir mais de uma turma do mesmo curso/série ao
// mesmo tempo (ex: duas CFS na mesma série, como ocorreu na época do Covid).
// "Formar turma" desativa a turma E todos os alunos vinculados a ela de uma
// vez, quando ela sai da escola (issue #32).

require_once __DIR__ . '/validacao_core.php';

const TURMA_LIMITES_CAMPOS = ['nome' => 50, 'serie' => 10, 'esquadrao_atual' => 50];
const TURMA_CURSOS_VALIDOS = ['CFS', 'EAGS'];

/**
 * Lista turmas com a contagem de alunos ativos vinculados a cada uma.
 */
function listarTurmas($conexao, $incluirInativas = false) {
    $where = $incluirInativas ? "" : "WHERE t.ativo = 1";
    $resultado = mysqli_query($conexao, "
        SELECT t.*, (SELECT COUNT(*) FROM alunos a WHERE a.turma_id = t.id AND a.ativo = 1) AS alunos_ativos
        FROM turmas t
        $where
        ORDER BY (t.ano_formatura_previsto IS NULL), t.ano_formatura_previsto ASC, t.semestre_formatura_previsto ASC, t.nome ASC
    ");
    return mysqli_fetch_all($resultado, MYSQLI_ASSOC);
}

function buscarTurmaPorId($conexao, $id) {
    $stmt = mysqli_prepare($conexao, "SELECT * FROM turmas WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

function _turmaExisteComNome($conexao, $nome, $idAtual = null) {
    $sql = "SELECT id FROM turmas WHERE nome = ?";
    $tipos = "s";
    $params = [$nome];
    if ($idAtual !== null) {
        $sql .= " AND id != ?";
        $tipos .= "i";
        $params[] = $idAtual;
    }
    $stmt = mysqli_prepare($conexao, $sql);
    mysqli_stmt_bind_param($stmt, $tipos, ...$params);
    mysqli_stmt_execute($stmt);
    return (bool) mysqli_stmt_get_result($stmt)->fetch_row();
}

/**
 * @return string|null mensagem de erro, ou null se os dados forem válidos.
 */
function _validarTurma($dados, $conexao, $idAtual = null) {
    $nome = trim($dados['nome'] ?? '');
    $curso = $dados['curso'] ?? '';
    $anoIngresso = (int) ($dados['ano_ingresso'] ?? 0);
    $semestreIngresso = (int) ($dados['semestre_ingresso'] ?? 0);

    if ($nome === '') {
        return 'Informe o nome (apelido) da turma.';
    }
    if (!in_array($curso, TURMA_CURSOS_VALIDOS, true)) {
        return 'Curso inválido. Use CFS ou EAGS.';
    }
    if ($curso === 'CFS' && !in_array((string) ($dados['serie'] ?? ''), ['1', '2', '3', '4'], true)) {
        return 'Para o curso CFS, a série deve ser 1, 2, 3 ou 4.';
    }
    if ($anoIngresso < 2000 || $anoIngresso > 2100) {
        return 'Ano de ingresso inválido.';
    }
    if (!in_array($semestreIngresso, [1, 2], true)) {
        return 'Semestre de ingresso deve ser 1 ou 2.';
    }
    if (!empty($dados['ano_formatura_previsto'])) {
        $anoFormatura = (int) $dados['ano_formatura_previsto'];
        if ($anoFormatura < $anoIngresso) {
            return 'Ano de formatura previsto não pode ser antes do ano de ingresso.';
        }
        $semestreFormatura = (int) ($dados['semestre_formatura_previsto'] ?? 0);
        if (!in_array($semestreFormatura, [1, 2], true)) {
            return 'Informe o semestre de formatura previsto (1 ou 2).';
        }
    }

    $erroComprimento = validarComprimentos([
        'nome' => $nome,
        'serie' => $dados['serie'] ?? null,
        'esquadrao_atual' => $dados['esquadrao_atual'] ?? null,
    ], TURMA_LIMITES_CAMPOS);
    if ($erroComprimento) {
        return $erroComprimento;
    }

    if (_turmaExisteComNome($conexao, $nome, $idAtual)) {
        return 'Já existe uma turma com esse nome.';
    }

    return null;
}

/**
 * @return array{ok: bool, erro?: string, id?: int}
 */
function criarTurma($conexao, $dados) {
    $erro = _validarTurma($dados, $conexao);
    if ($erro) {
        return ['ok' => false, 'erro' => $erro];
    }

    $nome = trim($dados['nome']);
    $curso = $dados['curso'];
    $serie = ($dados['serie'] ?? '') !== '' ? trim($dados['serie']) : null;
    $anoIngresso = (int) $dados['ano_ingresso'];
    $semestreIngresso = (int) $dados['semestre_ingresso'];
    $anoFormatura = !empty($dados['ano_formatura_previsto']) ? (int) $dados['ano_formatura_previsto'] : null;
    $semestreFormatura = !empty($dados['semestre_formatura_previsto']) ? (int) $dados['semestre_formatura_previsto'] : null;
    $esquadraoAtual = trim($dados['esquadrao_atual'] ?? '') ?: null;

    $stmt = mysqli_prepare($conexao, "
        INSERT INTO turmas (nome, curso, serie, ano_ingresso, semestre_ingresso, ano_formatura_previsto, semestre_formatura_previsto, esquadrao_atual)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    mysqli_stmt_bind_param(
        $stmt, "sssiiiis",
        $nome, $curso, $serie, $anoIngresso, $semestreIngresso, $anoFormatura, $semestreFormatura, $esquadraoAtual
    );
    if (!mysqli_stmt_execute($stmt)) {
        return ['ok' => false, 'erro' => 'Erro ao criar turma: ' . mysqli_error($conexao)];
    }
    return ['ok' => true, 'id' => mysqli_insert_id($conexao)];
}

function atualizarTurma($conexao, $id, $dados) {
    $erro = _validarTurma($dados, $conexao, $id);
    if ($erro) {
        return ['ok' => false, 'erro' => $erro];
    }

    $nome = trim($dados['nome']);
    $curso = $dados['curso'];
    $serie = ($dados['serie'] ?? '') !== '' ? trim($dados['serie']) : null;
    $anoIngresso = (int) $dados['ano_ingresso'];
    $semestreIngresso = (int) $dados['semestre_ingresso'];
    $anoFormatura = !empty($dados['ano_formatura_previsto']) ? (int) $dados['ano_formatura_previsto'] : null;
    $semestreFormatura = !empty($dados['semestre_formatura_previsto']) ? (int) $dados['semestre_formatura_previsto'] : null;
    $esquadraoAtual = trim($dados['esquadrao_atual'] ?? '') ?: null;

    $stmt = mysqli_prepare($conexao, "
        UPDATE turmas SET nome = ?, curso = ?, serie = ?, ano_ingresso = ?, semestre_ingresso = ?,
            ano_formatura_previsto = ?, semestre_formatura_previsto = ?, esquadrao_atual = ?
        WHERE id = ?
    ");
    mysqli_stmt_bind_param(
        $stmt, "sssiiiisi",
        $nome, $curso, $serie, $anoIngresso, $semestreIngresso, $anoFormatura, $semestreFormatura, $esquadraoAtual, $id
    );
    if (!mysqli_stmt_execute($stmt)) {
        return ['ok' => false, 'erro' => 'Erro ao atualizar turma: ' . mysqli_error($conexao)];
    }
    return ['ok' => true];
}

/**
 * "Formar" a turma: desativa a turma e todos os alunos ainda ativos
 * vinculados a ela, numa única ação (issue #32). Não apaga nada — mesmo
 * soft delete usado no resto do sistema, então continua tudo consultável
 * em relatórios/histórico.
 *
 * @return array{ok: bool, erro?: string, alunos_desativados?: int}
 */
function formarTurma($conexao, $id) {
    $turma = buscarTurmaPorId($conexao, $id);
    if (!$turma) {
        return ['ok' => false, 'erro' => 'Turma não encontrada.'];
    }

    $stmtAlunos = mysqli_prepare($conexao, "UPDATE alunos SET ativo = 0 WHERE turma_id = ? AND ativo = 1");
    mysqli_stmt_bind_param($stmtAlunos, "i", $id);
    if (!mysqli_stmt_execute($stmtAlunos)) {
        return ['ok' => false, 'erro' => 'Erro ao desativar os alunos da turma: ' . mysqli_error($conexao)];
    }
    $alunosDesativados = mysqli_stmt_affected_rows($stmtAlunos);

    $stmtTurma = mysqli_prepare($conexao, "UPDATE turmas SET ativo = 0 WHERE id = ?");
    mysqli_stmt_bind_param($stmtTurma, "i", $id);
    if (!mysqli_stmt_execute($stmtTurma)) {
        return ['ok' => false, 'erro' => 'Erro ao desativar a turma: ' . mysqli_error($conexao)];
    }

    return ['ok' => true, 'alunos_desativados' => $alunosDesativados];
}
