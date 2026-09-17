<?php

// Lógica de negócio da retirada de falta. Usada tanto pela API (/api/retiradas.php)
// quanto pelas telas do painel — para não duplicar regra em dois lugares.

require_once __DIR__ . '/dispensas_core.php';
require_once __DIR__ . '/motivos_core.php';
require_once __DIR__ . '/validacao_core.php';

const TIPOS_RETIRADA_VALIDOS = ['1_jornada', '2_jornada', 'educacao_fisica', 'pernoite'];
const AGRUPAMENTOS_VALIDOS = ['esquadrilha', 'grupo']; // 'especialidade' ainda não implementado

// Limites batendo com o VARCHAR de retiradas (database/init_db.sql). A
// observação de um item é TEXT no banco (até 64KB), mas uma observação de
// verdade é uma frase — sem limite aqui, dava pra lotar o banco com um
// arquivo gigante por item marcado (issue #25).
const RETIRADA_LIMITES_CAMPOS = ['responsavel_nome' => 100, 'agrupamento_valor' => 50, 'esquadrao' => 50];
const RETIRADA_ITEM_LIMITE_OBSERVACAO = 500;

/**
 * Abre uma nova retirada e já cria os itens (um por aluno do grupo), todos como "presente"
 * por padrão — a chamada em campo marca só as exceções (falta), como já é a lógica do Argos.
 *
 * O responsável pela retirada é sempre quem está de fato autenticado no momento —
 * nunca uma escolha livre — pra não dar pra abrir uma retirada e atribuí-la ao
 * nome de outra pessoa. $responsavelNome é obrigatório; $painelUsuarioId é opcional
 * (fica NULL quando o login veio da ponte do Ikarus37, que não tem linha própria em
 * painel_usuarios). $alunoServicoId é legado, mantido só pra quem ainda envia esse
 * campo via API.
 *
 * @return array{ok: bool, erro?: string, retirada_id?: int}
 */
function abrirRetirada($conexao, $tipo, $agrupamentoTipo, $agrupamentoValor, $esquadrao, $responsavelNome, $painelUsuarioId = null, $alunoServicoId = null) {
    if (trim($responsavelNome ?? '') === '') {
        return ['ok' => false, 'erro' => 'Responsável pela retirada não identificado.'];
    }

    $erroComprimento = validarComprimentos(
        ['responsavel_nome' => $responsavelNome, 'agrupamento_valor' => $agrupamentoValor, 'esquadrao' => $esquadrao],
        RETIRADA_LIMITES_CAMPOS
    );
    if ($erroComprimento) {
        return ['ok' => false, 'erro' => $erroComprimento];
    }

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

    // ---------- Cria a retirada ----------
    $stmt = mysqli_prepare($conexao, "
        INSERT INTO retiradas (tipo, agrupamento_tipo, agrupamento_valor, esquadrao, aluno_servico_id, painel_usuario_id, responsavel_nome, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pendente')
    ");
    mysqli_stmt_bind_param($stmt, "ssssiis", $tipo, $agrupamentoTipo, $agrupamentoValor, $esquadrao, $alunoServicoId, $painelUsuarioId, $responsavelNome);
    mysqli_stmt_execute($stmt);
    $retiradaId = mysqli_insert_id($conexao);

    // ---------- Cria um item por aluno, presente por padrão ----------
    // Exceção: aluno com dispensa médica ativa hoje já nasce marcado como
    // falta com o motivo DMED — sugestão automática, mas o Xerife pode
    // sobrepor normalmente na tela de chamada.
    $motivoDispensa = buscarMotivoPorCodigo($conexao, 'DMED');
    $hoje = date('Y-m-d');

    $stmtItem = mysqli_prepare($conexao, "INSERT INTO retirada_itens (retirada_id, aluno_id, presente, motivo_falta_id) VALUES (?, ?, ?, ?)");
    foreach ($alunoIds as $alunoId) {
        $presente = 1;
        $motivoFaltaId = null;

        if ($motivoDispensa && dispensaAtivaParaAluno($conexao, $alunoId, $hoje)) {
            $presente = 0;
            $motivoFaltaId = $motivoDispensa['id'];
        }

        mysqli_stmt_bind_param($stmtItem, "iiii", $retiradaId, $alunoId, $presente, $motivoFaltaId);
        mysqli_stmt_execute($stmtItem);
    }

    return ['ok' => true, 'retirada_id' => $retiradaId];
}

/**
 * Marca presença/falta de um aluno específico dentro de uma retirada já aberta.
 */
function marcarItem($conexao, $retiradaId, $alunoId, $presente, $motivoFaltaId, $observacao) {
    $motivoFaltaId = $presente ? null : $motivoFaltaId;

    // observacao é TEXT no banco (até 64KB) — sem limite aqui, dava pra
    // lotar o banco mandando um arquivo gigante por item marcado numa
    // chamada (issue #25). Corta em vez de rejeitar: é nota de texto
    // livre, não tem por que travar o envio da chamada por causa disso.
    if ($observacao !== null) {
        $observacao = mb_substr((string) $observacao, 0, RETIRADA_ITEM_LIMITE_OBSERVACAO);
    }

    $stmt = mysqli_prepare($conexao, "
        UPDATE retirada_itens SET presente = ?, motivo_falta_id = ?, observacao = ?
        WHERE retirada_id = ? AND aluno_id = ?
    ");
    mysqli_stmt_bind_param($stmt, "iisii", $presente, $motivoFaltaId, $observacao, $retiradaId, $alunoId);
    return mysqli_stmt_execute($stmt);
}

/**
 * Alunos marcados como falta com motivo DMED (dispensa médica) nesta
 * retirada, mas sem uma dispensa de verdade cadastrada cobrindo a data —
 * ou seja, alguém selecionou o motivo sem "lançar" a dispensa de fato.
 * Usado pra travar o envio da chamada até a dispensa existir no sistema.
 */
function alunosSemDispensaComprovada($conexao, $retiradaId) {
    $stmt = mysqli_prepare($conexao, "
        SELECT ri.aluno_id, a.nome_guerra, a.milhao
        FROM retirada_itens ri
        JOIN retiradas r ON r.id = ri.retirada_id
        JOIN alunos a ON a.id = ri.aluno_id
        JOIN motivos_falta m ON m.id = ri.motivo_falta_id
        WHERE ri.retirada_id = ? AND ri.presente = 0 AND m.codigo = 'DMED'
          AND NOT EXISTS (
              SELECT 1 FROM dispensas d
              WHERE d.aluno_id = ri.aluno_id
                AND d.data_inicio <= DATE(r.data_hora)
                AND d.data_termino >= DATE(r.data_hora)
          )
    ");
    mysqli_stmt_bind_param($stmt, "i", $retiradaId);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
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

    $semDispensa = alunosSemDispensaComprovada($conexao, $retiradaId);
    if (!empty($semDispensa)) {
        $nomes = implode(', ', array_map(fn($a) => "{$a['milhao']} {$a['nome_guerra']}", $semDispensa));
        return ['ok' => false, 'erro' => "Não dá pra enviar: marcado(s) como dispensa médica sem a dispensa lançada no sistema ainda — $nomes. Lance a dispensa (botão \"Lançar\" na linha do aluno) antes de enviar."];
    }

    $protocolo = date('Ymd') . '-' . str_pad($retiradaId, 4, '0', STR_PAD_LEFT) . '-' . strtoupper(bin2hex(random_bytes(2)));

    $stmt = mysqli_prepare($conexao, "UPDATE retiradas SET status = 'enviada', protocolo = ?, enviada_em = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "si", $protocolo, $retiradaId);
    mysqli_stmt_execute($stmt);

    return ['ok' => true, 'protocolo' => $protocolo];
}

/**
 * Exclui uma retirada e seus itens. `retirada_itens.retirada_id` referencia
 * `retiradas.id` sem ON DELETE CASCADE — por isso os itens precisam ser
 * apagados primeiro, senão o banco recusa a exclusão por violação de chave
 * estrangeira.
 *
 * @return array{ok: bool, erro?: string}
 */
function excluirRetirada($conexao, $id) {
    if (!buscarRetiradaPorId($conexao, $id)) {
        return ['ok' => false, 'erro' => 'Retirada não encontrada.'];
    }

    $stmt = mysqli_prepare($conexao, "DELETE FROM retirada_itens WHERE retirada_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);

    $stmt = mysqli_prepare($conexao, "DELETE FROM retiradas WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    if (!mysqli_stmt_execute($stmt)) {
        return ['ok' => false, 'erro' => 'Erro ao excluir retirada: ' . mysqli_error($conexao)];
    }

    return ['ok' => true];
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

/**
 * Retiradas já enviadas de um esquadrão numa data específica — base do
 * Livro do Dia (uma retirada por tipo/esquadrilha que tiver sido enviada).
 */
function listarRetiradasDoDia($conexao, $esquadrao, $data) {
    $stmt = mysqli_prepare($conexao, "
        SELECT * FROM retiradas
        WHERE esquadrao = ? AND status = 'enviada' AND DATE(data_hora) = ?
        ORDER BY tipo, agrupamento_valor
    ");
    mysqli_stmt_bind_param($stmt, "ss", $esquadrao, $data);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

function listarItensRetirada($conexao, $retiradaId) {
    $stmt = mysqli_prepare($conexao, "
        SELECT ri.*, a.posto_graduacao, COALESCE(p.exibicao, a.posto_graduacao) AS posto_exibicao,
               a.especialidade, a.nome_guerra, a.milhao, m.nome as motivo_nome, m.codigo as motivo_codigo
        FROM retirada_itens ri
        JOIN alunos a ON a.id = ri.aluno_id
        LEFT JOIN postos_graduacao p ON p.codigo = a.posto_graduacao
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
            r.id, r.tipo, r.agrupamento_tipo, r.agrupamento_valor, r.status, r.protocolo, r.data_hora, r.responsavel_nome,
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

/**
 * Ausências no período divididas por classificação (falta de verdade vs
 * ausência justificada) — base do gráfico vermelho/amarelo do Painel Argos.
 */
function relatorioPorClassificacao($conexao, $filtros) {
    [$where, $params, $tipos] = _filtroRelatorioRetiradas($filtros);

    $sql = "
        SELECT COALESCE(m.classificacao, 'ausente_nao_falta') as classificacao, COUNT(*) as total
        FROM retirada_itens ri
        JOIN retiradas r ON r.id = ri.retirada_id
        JOIN alunos a ON a.id = ri.aluno_id
        LEFT JOIN motivos_falta m ON m.id = ri.motivo_falta_id
        $where AND ri.presente = 0
        GROUP BY classificacao
    ";
    $stmt = mysqli_prepare($conexao, $sql);
    mysqli_stmt_bind_param($stmt, $tipos, ...$params);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

/**
 * Faltas no período agrupadas por esquadrão — só faz sentido pra quem vê o
 * CA inteiro (visão de esquadrão já é implicitamente 1 linha só).
 */
function relatorioPorEsquadrao($conexao, $filtros) {
    [$where, $params, $tipos] = _filtroRelatorioRetiradas($filtros);

    $sql = "
        SELECT a.esquadrao, COUNT(*) as total_itens, SUM(1 - ri.presente) as faltas
        FROM retirada_itens ri
        JOIN retiradas r ON r.id = ri.retirada_id
        JOIN alunos a ON a.id = ri.aluno_id
        $where
        GROUP BY a.esquadrao
        ORDER BY faltas DESC
    ";
    $stmt = mysqli_prepare($conexao, $sql);
    mysqli_stmt_bind_param($stmt, $tipos, ...$params);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

/**
 * Faltas no período agrupadas por esquadrilha — usado quando o usuário já
 * está restrito a um único esquadrão (relatorioPorEsquadrao não ajudaria,
 * seria uma barra só).
 */
function relatorioPorEsquadrilha($conexao, $filtros) {
    [$where, $params, $tipos] = _filtroRelatorioRetiradas($filtros);

    $sql = "
        SELECT a.esquadrilha, COUNT(*) as total_itens, SUM(1 - ri.presente) as faltas
        FROM retirada_itens ri
        JOIN retiradas r ON r.id = ri.retirada_id
        JOIN alunos a ON a.id = ri.aluno_id
        $where
        GROUP BY a.esquadrilha
        ORDER BY a.esquadrilha
    ";
    $stmt = mysqli_prepare($conexao, $sql);
    mysqli_stmt_bind_param($stmt, $tipos, ...$params);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

/**
 * Taxa de presença por dia no período — base do gráfico de linha (tendência).
 */
function evolucaoDiariaPresenca($conexao, $filtros) {
    [$where, $params, $tipos] = _filtroRelatorioRetiradas($filtros);

    $sql = "
        SELECT DATE(r.data_hora) as dia, COUNT(*) as total_itens, SUM(ri.presente) as presentes
        FROM retirada_itens ri
        JOIN retiradas r ON r.id = ri.retirada_id
        JOIN alunos a ON a.id = ri.aluno_id
        $where
        GROUP BY dia
        ORDER BY dia
    ";
    $stmt = mysqli_prepare($conexao, $sql);
    mysqli_stmt_bind_param($stmt, $tipos, ...$params);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}
