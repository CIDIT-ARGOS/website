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
        ? "a.*, COALESCE(p.exibicao, a.posto_graduacao) as posto_exibicao"
        : "a.*";
    $joinPosto = !empty($filtros['com_posto_exibicao'])
        ? "LEFT JOIN postos_graduacao p ON p.codigo = a.posto_graduacao"
        : "";

    $sql = "SELECT $selectPosto FROM alunos a $joinPosto $where ORDER BY $orderBy";

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
            milhao, esquadrao, esquadrilha, curso, serie
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $qrcodeHash = $dados['qrcode_hash'] ?? null;

    mysqli_stmt_bind_param(
        $stmt, "sssssssssssssssss",
        $dados['posto_graduacao'], $dados['quadro'], $dados['especialidade'], $dados['sub_especialidade'], $dados['nome_guerra'], $dados['sexo'],
        $dados['identidade_militar'], $dados['organizacao_militar'], $dados['setor'], $dados['secao'], $dados['ramal'], $qrcodeHash,
        $dados['milhao'], $dados['esquadrao'], $dados['esquadrilha'], $dados['curso'], $dados['serie']
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
            milhao = ?, esquadrao = ?, esquadrilha = ?, curso = ?, serie = ?
        WHERE id = ?
    ");

    $qrcodeHash = $dados['qrcode_hash'] ?? null;

    mysqli_stmt_bind_param(
        $stmt, "sssssssssssssssssi",
        $dados['posto_graduacao'], $dados['quadro'], $dados['especialidade'], $dados['sub_especialidade'], $dados['nome_guerra'], $dados['sexo'],
        $dados['identidade_militar'], $dados['organizacao_militar'], $dados['setor'], $dados['secao'], $dados['ramal'], $qrcodeHash,
        $dados['milhao'], $dados['esquadrao'], $dados['esquadrilha'], $dados['curso'], $dados['serie'], $id
    );

    if (!mysqli_stmt_execute($stmt)) {
        return ['ok' => false, 'erro' => 'Erro ao atualizar aluno: ' . mysqli_error($conexao), 'codigo' => 500];
    }

    return ['ok' => true];
}

/**
 * Atualização parcial (sexo, curso, série, esquadrilha, especialidade,
 * sub_especialidade, ativo) — o subconjunto de campos editável pelo painel,
 * que não mexe nos dados de identificação vindos da planilha oficial.
 */
function atualizarAlunoParcial($conexao, $id, $dados) {
    $sexo = $dados['sexo'] ?? '';
    $curso = $dados['curso'] ?? '';
    $serie = trim($dados['serie'] ?? '');
    $esquadrilha = trim($dados['esquadrilha'] ?? '');
    $especialidade = trim($dados['especialidade'] ?? '');
    $subEspecialidade = trim($dados['sub_especialidade'] ?? '');
    $ativo = !empty($dados['ativo']) ? 1 : 0;

    $mensagemErro = validarAluno(['sexo' => $sexo, 'curso' => $curso, 'serie' => $serie], true);
    if ($mensagemErro) {
        return ['ok' => false, 'erro' => $mensagemErro];
    }

    $stmt = mysqli_prepare($conexao, "
        UPDATE alunos SET sexo = ?, curso = ?, serie = ?, esquadrilha = ?, especialidade = ?, sub_especialidade = ?, ativo = ?
        WHERE id = ?
    ");
    mysqli_stmt_bind_param($stmt, "ssssssii", $sexo, $curso, $serie, $esquadrilha, $especialidade, $subEspecialidade, $ativo, $id);

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
