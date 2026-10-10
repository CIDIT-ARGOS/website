<?php

// Postos de serviço (ex: Aluno de Dia, Plantão, Sentinela) e quem está neles.
//
// O posto é criado à mão, como um clube. A diferença é que não tem membros
// fixos: quem está de serviço muda todo dia, e às vezes no meio do serviço
// (por alguma pane). Por isso cada pessoa num posto é um PERÍODO
// (posto_servico_escalas): começa quando assume e termina quando sai ou é
// rendida — e o histórico fica.
//
// Na chamada, quem está de serviço num posto já entra marcado como ausente
// por "Serviço", com o posto preenchido (dá pra sobrepor).
// Usado pelo Painel (postos_servico.php, retirada_marcar.php) e pela API.

const POSTO_SERVICO_LIMITE_NOME = 100;
const POSTO_SERVICO_LIMITE_SIGLA = 10;
const POSTO_SERVICO_LIMITE_MOTIVO = 255;

/**
 * Postos ativos, na ordem do cadastro. Se a tabela ainda não existir (banco
 * sem a migration 014), devolve lista vazia em vez de derrubar a tela.
 */
function listarServicos($conexao) {
    $resultado = mysqli_query($conexao, "SELECT id, nome, codigo, ativo FROM postos_servico WHERE ativo = 1 ORDER BY ordem, nome");
    if (!$resultado) {
        return [];
    }
    return mysqli_fetch_all($resultado, MYSQLI_ASSOC);
}

function buscarServicoPorId($conexao, $id) {
    $stmt = mysqli_prepare($conexao, "SELECT * FROM postos_servico WHERE id = ? AND ativo = 1");
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

/**
 * Cria um posto. Se já existir um com esse nome que foi desativado, reativa
 * (o nome é único no banco) — mesmo comportamento dos grupos.
 *
 * @return array{ok: bool, erro?: string, id?: int}
 */
function criarPostoServico($conexao, $nome, $sigla = '') {
    $nome = trim((string) $nome);
    $sigla = trim((string) $sigla) ?: null;

    if ($nome === '') {
        return ['ok' => false, 'erro' => 'Informe o nome do posto de serviço.'];
    }
    if (mb_strlen($nome) > POSTO_SERVICO_LIMITE_NOME) {
        return ['ok' => false, 'erro' => 'Nome excede o tamanho máximo permitido (' . POSTO_SERVICO_LIMITE_NOME . ' caracteres).'];
    }
    if ($sigla !== null && mb_strlen($sigla) > POSTO_SERVICO_LIMITE_SIGLA) {
        return ['ok' => false, 'erro' => 'Sigla excede o tamanho máximo permitido (' . POSTO_SERVICO_LIMITE_SIGLA . ' caracteres).'];
    }

    $stmt = mysqli_prepare($conexao, "SELECT id, ativo FROM postos_servico WHERE nome = ?");
    mysqli_stmt_bind_param($stmt, "s", $nome);
    mysqli_stmt_execute($stmt);
    $existente = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if ($existente) {
        if ((int) $existente['ativo'] === 1) {
            return ['ok' => false, 'erro' => 'Já existe um posto de serviço ativo com esse nome.'];
        }
        $stmt = mysqli_prepare($conexao, "UPDATE postos_servico SET ativo = 1, codigo = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "si", $sigla, $existente['id']);
        mysqli_stmt_execute($stmt);
        return ['ok' => true, 'id' => (int) $existente['id']];
    }

    $stmt = mysqli_prepare($conexao, "INSERT INTO postos_servico (nome, codigo) VALUES (?, ?)");
    mysqli_stmt_bind_param($stmt, "ss", $nome, $sigla);
    if (!mysqli_stmt_execute($stmt)) {
        return ['ok' => false, 'erro' => 'Erro ao criar o posto: ' . mysqli_error($conexao)];
    }
    return ['ok' => true, 'id' => mysqli_insert_id($conexao)];
}

/**
 * Desativa o posto (não apaga: chamadas antigas continuam apontando pra ele)
 * e encerra o serviço de quem estiver nele.
 */
function desativarPostoServico($conexao, $postoId) {
    $stmt = mysqli_prepare($conexao, "UPDATE posto_servico_escalas SET fim = NOW(), motivo_saida = 'Posto desativado' WHERE posto_servico_id = ? AND fim IS NULL");
    mysqli_stmt_bind_param($stmt, "i", $postoId);
    mysqli_stmt_execute($stmt);

    $stmt = mysqli_prepare($conexao, "UPDATE postos_servico SET ativo = 0 WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $postoId);
    return mysqli_stmt_execute($stmt);
}

function _escalasSelect() {
    return "
        SELECT e.*, a.posto_graduacao, COALESCE(p.exibicao, a.posto_graduacao) AS posto_exibicao,
               a.especialidade, a.nome_guerra, a.milhao, a.esquadrao, a.esquadrilha
        FROM posto_servico_escalas e
        JOIN alunos a ON a.id = e.aluno_id
        LEFT JOIN postos_graduacao p ON p.codigo = a.posto_graduacao
    ";
}

/** Quem está de serviço no posto agora (pode ser mais de um). */
function escalaAtualDoPosto($conexao, $postoId) {
    $stmt = mysqli_prepare($conexao, _escalasSelect() . " WHERE e.posto_servico_id = ? AND e.fim IS NULL ORDER BY e.inicio");
    mysqli_stmt_bind_param($stmt, "i", $postoId);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

/** Quem já saiu do posto num dia (rendido ou encerrado), do mais recente pro mais antigo. */
function historicoDoPosto($conexao, $postoId, $data) {
    $stmt = mysqli_prepare($conexao, _escalasSelect() . "
        WHERE e.posto_servico_id = ? AND e.fim IS NOT NULL AND DATE(e.inicio) <= ? AND DATE(e.fim) >= ?
        ORDER BY e.fim DESC
    ");
    mysqli_stmt_bind_param($stmt, "iss", $postoId, $data, $data);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

/**
 * O posto em que o aluno está de serviço agora, ou null. Devolve a escala com
 * posto_nome. É o que a chamada usa pra já marcar "Serviço" pra ele.
 */
function servicoAtualDoAluno($conexao, $alunoId) {
    $stmt = mysqli_prepare($conexao, "
        SELECT e.*, ps.nome AS posto_nome
        FROM posto_servico_escalas e
        JOIN postos_servico ps ON ps.id = e.posto_servico_id AND ps.ativo = 1
        WHERE e.aluno_id = ? AND e.fim IS NULL
        ORDER BY e.inicio DESC LIMIT 1
    ");
    // Banco sem a migration 019: ninguém está de serviço, a chamada segue normal.
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, "i", $alunoId);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

/**
 * Tira alguém do serviço (fim do período), com o motivo se houver.
 *
 * @return array{ok: bool, erro?: string}
 */
function encerrarEscala($conexao, $escalaId, $motivo = '') {
    $motivo = trim((string) $motivo) ?: null;
    if ($motivo !== null && mb_strlen($motivo) > POSTO_SERVICO_LIMITE_MOTIVO) {
        return ['ok' => false, 'erro' => 'Motivo excede o tamanho máximo permitido (' . POSTO_SERVICO_LIMITE_MOTIVO . ' caracteres).'];
    }
    $stmt = mysqli_prepare($conexao, "UPDATE posto_servico_escalas SET fim = NOW(), motivo_saida = ? WHERE id = ? AND fim IS NULL");
    mysqli_stmt_bind_param($stmt, "si", $motivo, $escalaId);
    mysqli_stmt_execute($stmt);
    if (mysqli_stmt_affected_rows($stmt) === 0) {
        return ['ok' => false, 'erro' => 'Esse serviço já tinha sido encerrado.'];
    }
    return ['ok' => true];
}

/**
 * Coloca um aluno de serviço num posto, a partir de agora.
 *
 * $substituiEscalaId: quem ele rende (a escala de quem sai é encerrada com
 * $motivo) — null pra só acrescentar. O aluno só fica num posto por vez: se
 * estava em outro, sai de lá.
 *
 * @return array{ok: bool, erro?: string, id?: int}
 */
function escalarAlunoNoPosto($conexao, $postoId, $alunoId, $registradoPor, $painelUsuarioId = null, $substituiEscalaId = null, $motivo = '') {
    $motivo = trim((string) $motivo);
    if (mb_strlen($motivo) > POSTO_SERVICO_LIMITE_MOTIVO) {
        return ['ok' => false, 'erro' => 'Motivo excede o tamanho máximo permitido (' . POSTO_SERVICO_LIMITE_MOTIVO . ' caracteres).'];
    }
    if (!buscarServicoPorId($conexao, $postoId)) {
        return ['ok' => false, 'erro' => 'Posto de serviço não encontrado.'];
    }

    $atual = servicoAtualDoAluno($conexao, $alunoId);
    if ($atual && (int) $atual['posto_servico_id'] === (int) $postoId) {
        return ['ok' => false, 'erro' => 'Esse aluno já está de serviço neste posto.'];
    }

    if ($substituiEscalaId) {
        $stmt = mysqli_prepare($conexao, "SELECT id FROM posto_servico_escalas WHERE id = ? AND posto_servico_id = ? AND fim IS NULL");
        mysqli_stmt_bind_param($stmt, "ii", $substituiEscalaId, $postoId);
        mysqli_stmt_execute($stmt);
        if (!mysqli_stmt_get_result($stmt)->fetch_row()) {
            return ['ok' => false, 'erro' => 'Quem seria rendido não está mais de serviço neste posto.'];
        }
        encerrarEscala($conexao, (int) $substituiEscalaId, $motivo !== '' ? $motivo : 'Rendido');
    }
    if ($atual) {
        encerrarEscala($conexao, (int) $atual['id'], 'Assumiu outro posto');
    }

    $stmt = mysqli_prepare($conexao, "INSERT INTO posto_servico_escalas (posto_servico_id, aluno_id, registrado_por, painel_usuario_id) VALUES (?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "iisi", $postoId, $alunoId, $registradoPor, $painelUsuarioId);
    if (!mysqli_stmt_execute($stmt)) {
        return ['ok' => false, 'erro' => 'Erro ao registrar o serviço: ' . mysqli_error($conexao)];
    }
    return ['ok' => true, 'id' => mysqli_insert_id($conexao)];
}
