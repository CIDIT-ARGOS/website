<?php

// Lógica de negócio do efetivo (alunos). Usada tanto pela API (/api/alunos.php)
// quanto pelas telas do painel — para não duplicar consulta/regra em dois lugares.

require_once __DIR__ . '/validacao_core.php';

const CURSOS_VALIDOS = ['CFS', 'EAGS'];

// Limites de caracteres batendo com o VARCHAR de cada coluna em alunos
// (database/init_db.sql) — validarAluno() barra antes de qualquer query.
const ALUNO_LIMITES_CAMPOS = [
    'posto_graduacao' => 10,
    'quadro' => 50,
    'especialidade' => 50,
    'sub_especialidade' => 50,
    'nome_guerra' => 50,
    'identidade_militar' => 30,
    'organizacao_militar' => 100,
    'setor' => 50,
    'secao' => 50,
    'ramal' => 10,
    'milhao' => 20,
    'esquadrao' => 50,
    'esquadrilha' => 50,
    'serie' => 10,
];

/**
 * Identificação padrão do aluno em qualquer listagem/relatório do sistema:
 * "AL 26/3138 SIN SIMIONI" (posto/graduação de exibição, milhão,
 * especialidade, nome de guerra). Aceita tanto uma linha com
 * `posto_exibicao` já resolvido (join com postos_graduacao) quanto uma linha
 * "crua" (só `posto_graduacao`, sem o join) — nesse caso usa o próprio
 * código como fallback.
 */
function identificacaoAluno($aluno) {
    $posto = $aluno['posto_exibicao'] ?? $aluno['posto_graduacao'] ?? '';
    $partes = array_filter([
        $posto,
        $aluno['milhao'] ?? '',
        $aluno['especialidade'] ?? '',
        $aluno['nome_guerra'] ?? '',
    ], fn($v) => trim((string) $v) !== '');
    return implode(' ', $partes);
}

function validarAluno($dados, $parcial = false) {
    $camposObrigatorios = [
        'posto_graduacao', 'nome_guerra', 'sexo', 'identidade_militar',
        'milhao', 'esquadrao', 'esquadrilha', 'curso', 'serie'
    ];

    if (!$parcial) {
        foreach ($camposObrigatorios as $campo) {
            if (empty($dados[$campo])) {
                return "Campo obrigatório ausente: $campo";
            }
        }
    }

    $erroComprimento = validarComprimentos($dados, ALUNO_LIMITES_CAMPOS);
    if ($erroComprimento) {
        return $erroComprimento;
    }

    if (isset($dados['sexo']) && !in_array($dados['sexo'], ['M', 'F'])) {
        return "Campo sexo inválido. Use M ou F.";
    }

    if (isset($dados['curso']) && !in_array($dados['curso'], CURSOS_VALIDOS)) {
        return "Curso inválido. Use CFS ou EAGS.";
    }

    if (($dados['curso'] ?? null) === 'EAGS' && ($dados['serie'] ?? null) !== 'EAGS') {
        return "Para o curso EAGS, a série deve ser 'EAGS'.";
    }

    if (($dados['curso'] ?? null) === 'CFS' && isset($dados['serie']) && !in_array($dados['serie'], ['1', '2', '3', '4'])) {
        return "Para o curso CFS, a série deve ser 1, 2, 3 ou 4.";
    }

    if (!empty($dados['qrcode_hash']) && !preg_match('/^[a-f0-9]{64}$/i', $dados['qrcode_hash'])) {
        return "qrcode_hash inválido: deve ser um hash sha256 (64 caracteres hexadecimais).";
    }

    return null;
}

/**
 * Lista alunos com filtros opcionais.
 * $filtros aceitos: esquadrilha, especialidade, esquadrao, curso, qrcode_hash,
 * busca (nome_guerra/milhao), incluir_inativos (bool), com_posto_exibicao (bool),
 * order_by (padrão "nome_guerra ASC").
 */
function listarAlunos($conexao, $filtros = []) {
    $condicoes = [];
    $params = [];
    $tipos = "";

    if (!empty($filtros['esquadrilha'])) {
        $condicoes[] = "a.esquadrilha = ?";
        $params[] = $filtros['esquadrilha'];
        $tipos .= "s";
    }
    if (!empty($filtros['especialidade'])) {
        $condicoes[] = "a.especialidade = ?";
        $params[] = $filtros['especialidade'];
        $tipos .= "s";
    }
    if (!empty($filtros['esquadrao'])) {
        $condicoes[] = "a.esquadrao = ?";
        $params[] = $filtros['esquadrao'];
        $tipos .= "s";
    }
    if (!empty($filtros['curso'])) {
        $condicoes[] = "a.curso = ?";
        $params[] = $filtros['curso'];
        $tipos .= "s";
    }
    if (!empty($filtros['turma_id'])) {
        $condicoes[] = "a.turma_id = ?";
        $params[] = (int) $filtros['turma_id'];
        $tipos .= "i";
    }
    if (!empty($filtros['qrcode_hash'])) {
        $condicoes[] = "a.qrcode_hash = ?";
        $params[] = $filtros['qrcode_hash'];
        $tipos .= "s";
    }
    if (!empty($filtros['busca'])) {
        $condicoes[] = "(a.nome_guerra LIKE ? OR a.milhao LIKE ?)";
        $termo = "%" . $filtros['busca'] . "%";
        $params[] = $termo;
        $params[] = $termo;
        $tipos .= "ss";
    }
    if (empty($filtros['incluir_inativos'])) {
        $condicoes[] = "a.ativo = 1";
    }

    $where = $condicoes ? "WHERE " . implode(" AND ", $condicoes) : "";
    $orderBy = $filtros['order_by'] ?? "a.nome_guerra ASC";

    $selectPosto = !empty($filtros['com_posto_exibicao'])
        ? "a.*, COALESCE(p.exibicao, a.posto_graduacao) as posto_exibicao, t.nome AS turma_nome"
        : "a.*, t.nome AS turma_nome";
    $joinPosto = !empty($filtros['com_posto_exibicao'])
        ? "LEFT JOIN postos_graduacao p ON p.codigo = a.posto_graduacao"
        : "";

    $sql = "SELECT $selectPosto FROM alunos a $joinPosto LEFT JOIN turmas t ON t.id = a.turma_id $where ORDER BY $orderBy";

    $stmt = mysqli_prepare($conexao, $sql);
    if ($params) {
        mysqli_stmt_bind_param($stmt, $tipos, ...$params);
    }
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

function buscarAlunoPorId($conexao, $id) {
    $stmt = mysqli_prepare($conexao, "SELECT * FROM alunos WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

function buscarAlunoPorMilhao($conexao, $milhao) {
    $stmt = mysqli_prepare($conexao, "SELECT * FROM alunos WHERE milhao = ? AND ativo = 1");
    mysqli_stmt_bind_param($stmt, "s", $milhao);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

/**
 * Busca livre por nome/milhão em todo o efetivo ativo, sem respeitar escopo de
 * esquadrão — usada para localizar um aluno de serviço que pode ser de qualquer
 * esquadrão (ex: Aluno de Dia à Esquadrilha).
 */
function buscarAlunosLivre($conexao, $termo, $limite = 20) {
    if (strlen($termo) < 2) {
        return [];
    }
    $like = "%$termo%";
    $limite = (int) $limite;
    $stmt = mysqli_prepare($conexao, "
        SELECT id, nome_guerra, milhao, esquadrao, esquadrilha
        FROM alunos
        WHERE ativo = 1 AND (nome_guerra LIKE ? OR milhao LIKE ?)
        ORDER BY nome_guerra
        LIMIT $limite
    ");
    mysqli_stmt_bind_param($stmt, "ss", $like, $like);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

function listarAlunosPorEsquadrilha($conexao, $esquadrao, $esquadrilha) {
    $stmt = mysqli_prepare($conexao, "
        SELECT id, nome_guerra, milhao FROM alunos
        WHERE esquadrao = ? AND esquadrilha = ? AND ativo = 1
        ORDER BY nome_guerra
    ");
    mysqli_stmt_bind_param($stmt, "ss", $esquadrao, $esquadrilha);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

function contarAlunosAtivos($conexao, $esquadrao = null) {
    if ($esquadrao !== null) {
        $stmt = mysqli_prepare($conexao, "SELECT COUNT(*) as total FROM alunos WHERE ativo = 1 AND esquadrao = ?");
        mysqli_stmt_bind_param($stmt, "s", $esquadrao);
        mysqli_stmt_execute($stmt);
        return (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];
    }
    return (int) mysqli_fetch_assoc(mysqli_query($conexao, "SELECT COUNT(*) as total FROM alunos WHERE ativo = 1"))['total'];
}

function listarEsquadroesDistintos($conexao) {
    $r = mysqli_query($conexao, "SELECT DISTINCT esquadrao FROM alunos WHERE ativo = 1 ORDER BY esquadrao");
    $esquadroes = [];
    while ($l = mysqli_fetch_assoc($r)) {
        $esquadroes[] = $l['esquadrao'];
    }
    return $esquadroes;
}

const ESQUADRILHAS_PADRAO = ['A', 'B', 'C', 'D'];

/**
 * Quadro "Quantitativo por Esquadrão / Esquadrilha": uma linha por esquadrão
 * com a contagem em cada esquadrilha (A/B/C/D), total e masculino/feminino.
 * Não é afetado pelos filtros de busca da tela — é o retrato geral do
 * efetivo ativo (dentro do escopo de quem está vendo).
 *
 * @return array<string, array{esquadrilhas: array<string,int>, total: int, m: int, f: int}>
 */
function quantitativoPorEsquadraoEsquadrilha($conexao, $escopo = null) {
    $sql = "SELECT esquadrao, esquadrilha, sexo, COUNT(*) as total FROM alunos WHERE ativo = 1";
    $params = [];
    $tipos = "";
    if ($escopo !== null) {
        $sql .= " AND esquadrao = ?";
        $params[] = $escopo;
        $tipos .= "s";
    }
    $sql .= " GROUP BY esquadrao, esquadrilha, sexo";

    $stmt = mysqli_prepare($conexao, $sql);
    if ($params) {
        mysqli_stmt_bind_param($stmt, $tipos, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $linhas = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $porEsquadrao = [];
    foreach ($linhas as $l) {
        $esq = $l['esquadrao'];
        if (!isset($porEsquadrao[$esq])) {
            $porEsquadrao[$esq] = ['esquadrilhas' => array_fill_keys(ESQUADRILHAS_PADRAO, 0), 'total' => 0, 'm' => 0, 'f' => 0];
        }
        $total = (int) $l['total'];
        $porEsquadrao[$esq]['esquadrilhas'][$l['esquadrilha']] = ($porEsquadrao[$esq]['esquadrilhas'][$l['esquadrilha']] ?? 0) + $total;
        $porEsquadrao[$esq]['total'] += $total;
        $porEsquadrao[$esq][$l['sexo'] === 'F' ? 'f' : 'm'] += $total;
    }
    ksort($porEsquadrao);
    return $porEsquadrao;
}

/**
 * Quantidade de alunos ativos por especialidade, dentro do escopo. Usado no
 * mesmo card de quantitativo da tela de efetivo.
 */
function quantitativoPorEspecialidade($conexao, $escopo = null) {
    $sql = "SELECT COALESCE(especialidade, 'Sem especialidade') as especialidade, COUNT(*) as total FROM alunos WHERE ativo = 1";
    $params = [];
    $tipos = "";
    if ($escopo !== null) {
        $sql .= " AND esquadrao = ?";
        $params[] = $escopo;
        $tipos .= "s";
    }
    $sql .= " GROUP BY especialidade ORDER BY total DESC";

    $stmt = mysqli_prepare($conexao, $sql);
    if ($params) {
        mysqli_stmt_bind_param($stmt, $tipos, ...$params);
    }
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

/**
 * @return array{ok: bool, erro?: string, id?: int}
 */
function criarAluno($conexao, $dados) {
    $mensagemErro = validarAluno($dados);
    if ($mensagemErro) {
        return ['ok' => false, 'erro' => $mensagemErro];
    }

    $stmt = mysqli_prepare($conexao, "
        INSERT INTO alunos (
            posto_graduacao, quadro, especialidade, sub_especialidade, nome_guerra, sexo,
            identidade_militar, organizacao_militar, setor, secao, ramal, qrcode_hash,
            milhao, esquadrao, esquadrilha, curso, serie, turma_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $qrcodeHash = $dados['qrcode_hash'] ?? null;
    $turmaId = !empty($dados['turma_id']) ? (int) $dados['turma_id'] : null;

    mysqli_stmt_bind_param(
        $stmt, "sssssssssssssssssi",
        $dados['posto_graduacao'], $dados['quadro'], $dados['especialidade'], $dados['sub_especialidade'], $dados['nome_guerra'], $dados['sexo'],
        $dados['identidade_militar'], $dados['organizacao_militar'], $dados['setor'], $dados['secao'], $dados['ramal'], $qrcodeHash,
        $dados['milhao'], $dados['esquadrao'], $dados['esquadrilha'], $dados['curso'], $dados['serie'], $turmaId
    );

    if (!mysqli_stmt_execute($stmt)) {
        if (mysqli_errno($conexao) === 1062) {
            return ['ok' => false, 'erro' => 'Já existe um aluno com essa identidade militar, milhão ou qrcode_hash.', 'codigo' => 409];
        }
        return ['ok' => false, 'erro' => 'Erro ao criar aluno: ' . mysqli_error($conexao), 'codigo' => 500];
    }

    return ['ok' => true, 'id' => mysqli_insert_id($conexao)];
}

/**
 * Atualização completa (todos os campos), usada pela API.
 */
function atualizarAluno($conexao, $id, $dados) {
    $mensagemErro = validarAluno($dados);
    if ($mensagemErro) {
        return ['ok' => false, 'erro' => $mensagemErro];
    }

    $stmt = mysqli_prepare($conexao, "
        UPDATE alunos SET
            posto_graduacao = ?, quadro = ?, especialidade = ?, sub_especialidade = ?, nome_guerra = ?, sexo = ?,
            identidade_militar = ?, organizacao_militar = ?, setor = ?, secao = ?, ramal = ?, qrcode_hash = ?,
            milhao = ?, esquadrao = ?, esquadrilha = ?, curso = ?, serie = ?, turma_id = ?
        WHERE id = ?
    ");

    $qrcodeHash = $dados['qrcode_hash'] ?? null;
    $turmaId = !empty($dados['turma_id']) ? (int) $dados['turma_id'] : null;

    mysqli_stmt_bind_param(
        $stmt, "sssssssssssssssssii",
        $dados['posto_graduacao'], $dados['quadro'], $dados['especialidade'], $dados['sub_especialidade'], $dados['nome_guerra'], $dados['sexo'],
        $dados['identidade_militar'], $dados['organizacao_militar'], $dados['setor'], $dados['secao'], $dados['ramal'], $qrcodeHash,
        $dados['milhao'], $dados['esquadrao'], $dados['esquadrilha'], $dados['curso'], $dados['serie'], $turmaId, $id
    );

    if (!mysqli_stmt_execute($stmt)) {
        return ['ok' => false, 'erro' => 'Erro ao atualizar aluno: ' . mysqli_error($conexao), 'codigo' => 500];
    }

    return ['ok' => true];
}

/**
 * Atualização parcial (sexo, curso, série, esquadrilha, especialidade,
 * sub_especialidade, turma, ativo) — o subconjunto de campos editável pelo
 * painel, que não mexe nos dados de identificação vindos da planilha oficial.
 */
function atualizarAlunoParcial($conexao, $id, $dados) {
    $sexo = $dados['sexo'] ?? '';
    $curso = $dados['curso'] ?? '';
    $serie = trim($dados['serie'] ?? '');
    $esquadrilha = trim($dados['esquadrilha'] ?? '');
    $especialidade = trim($dados['especialidade'] ?? '');
    $subEspecialidade = trim($dados['sub_especialidade'] ?? '');
    $turmaId = !empty($dados['turma_id']) ? (int) $dados['turma_id'] : null;
    $ativo = !empty($dados['ativo']) ? 1 : 0;

    $mensagemErro = validarAluno(['sexo' => $sexo, 'curso' => $curso, 'serie' => $serie], true);
    if ($mensagemErro) {
        return ['ok' => false, 'erro' => $mensagemErro];
    }

    $stmt = mysqli_prepare($conexao, "
        UPDATE alunos SET sexo = ?, curso = ?, serie = ?, esquadrilha = ?, especialidade = ?, sub_especialidade = ?, turma_id = ?, ativo = ?
        WHERE id = ?
    ");
    mysqli_stmt_bind_param($stmt, "ssssssiii", $sexo, $curso, $serie, $esquadrilha, $especialidade, $subEspecialidade, $turmaId, $ativo, $id);

    if (!mysqli_stmt_execute($stmt)) {
        return ['ok' => false, 'erro' => 'Erro ao atualizar: ' . mysqli_stmt_error($stmt), 'codigo' => 500];
    }

    return ['ok' => true];
}

function inativarAluno($conexao, $id) {
    $stmt = mysqli_prepare($conexao, "UPDATE alunos SET ativo = 0 WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    return mysqli_stmt_execute($stmt);
}

/**
 * Atribui (ou remove, se $turmaId for null) uma turma a vários alunos de uma
 * vez — usada pela ação em lote da tela de Efetivo, pra não depender de
 * editar aluno por aluno quando uma turma inteira precisa ser vinculada.
 * $escopo (esquadrão), quando informado, é reforçado na query — mesmo que o
 * POST venha manipulado, só afeta alunos dentro do esquadrão de quem está
 * autenticado.
 *
 * @return array{ok: bool, erro?: string, atualizados?: int}
 */
function atribuirTurmaEmLote($conexao, array $alunoIds, $turmaId, $escopo = null) {
    $alunoIds = array_values(array_unique(array_filter(array_map('intval', $alunoIds))));
    if (empty($alunoIds)) {
        return ['ok' => false, 'erro' => 'Selecione ao menos um aluno.'];
    }

    $marcadores = implode(',', array_fill(0, count($alunoIds), '?'));
    $tipos = str_repeat('i', count($alunoIds));
    $valores = $alunoIds;

    $sql = "UPDATE alunos SET turma_id = ? WHERE ativo = 1 AND id IN ($marcadores)";
    array_unshift($valores, $turmaId);
    $tipos = 'i' . $tipos;

    if ($escopo !== null) {
        $sql .= " AND esquadrao = ?";
        $valores[] = $escopo;
        $tipos .= 's';
    }

    $stmt = mysqli_prepare($conexao, $sql);
    mysqli_stmt_bind_param($stmt, $tipos, ...$valores);
    if (!mysqli_stmt_execute($stmt)) {
        return ['ok' => false, 'erro' => 'Erro ao atribuir turma: ' . mysqli_error($conexao)];
    }

    return ['ok' => true, 'atualizados' => mysqli_stmt_affected_rows($stmt)];
}

function listarPostosGraduacao($conexao) {
    $r = mysqli_query($conexao, "SELECT codigo, exibicao FROM postos_graduacao ORDER BY exibicao");
    return mysqli_fetch_all($r, MYSQLI_ASSOC);
}

/**
 * Cadastra uma leva inteira de alunos novos de uma vez, um por linha de
 * texto colada (formato planilha: campos separados por TAB ou ";"), todos
 * com curso/série/turma_id em comum — usado pela tela de "cadastrar alunos
 * da turma" (issue #32), pra não depender de cadastrar um por um.
 *
 * Formato de cada linha: Posto;Nome de Guerra;Sexo;Identidade Militar;
 * Milhão;Esquadrilha;Especialidade (opcional);Sub-especialidade (opcional)
 *
 * @return array{sucesso: int, erros: array<array{linha:int, texto:string, erro:string}>}
 */
function cadastrarAlunosEmLote($conexao, $textoLinhas, $camposComuns) {
    $sucesso = 0;
    $erros = [];
    $numeroLinha = 0;

    foreach (preg_split('/\r\n|\r|\n/', (string) $textoLinhas) as $linha) {
        $numeroLinha++;
        $linha = trim($linha);
        if ($linha === '') {
            continue;
        }

        $campos = preg_split('/\t|;/', $linha);
        $campos = array_map('trim', $campos);

        if (count($campos) < 5) {
            $erros[] = ['linha' => $numeroLinha, 'texto' => $linha, 'erro' => 'Linha incompleta — informe ao menos Posto, Nome de Guerra, Sexo, Identidade Militar e Milhão.'];
            continue;
        }

        [$posto, $nomeGuerra, $sexo, $identidadeMilitar, $milhao] = array_slice($campos, 0, 5);
        $esquadrilha = $campos[5] ?? '';
        $especialidade = $campos[6] ?? '';
        $subEspecialidade = $campos[7] ?? '';

        $dados = array_merge(
            ['quadro' => null, 'organizacao_militar' => null, 'setor' => null, 'secao' => null, 'ramal' => null, 'qrcode_hash' => null],
            $camposComuns,
            [
                'posto_graduacao' => $posto !== '' ? $posto : 'GS',
                'nome_guerra' => $nomeGuerra,
                'sexo' => strtoupper($sexo),
                'identidade_militar' => $identidadeMilitar,
                'milhao' => $milhao,
                'esquadrilha' => $esquadrilha,
                'especialidade' => $especialidade ?: null,
                'sub_especialidade' => $subEspecialidade ?: null,
            ]
        );

        $resultado = criarAluno($conexao, $dados);
        if ($resultado['ok']) {
            $sucesso++;
        } else {
            $erros[] = ['linha' => $numeroLinha, 'texto' => $linha, 'erro' => $resultado['erro']];
        }
    }

    return ['sucesso' => $sucesso, 'erros' => $erros];
}
