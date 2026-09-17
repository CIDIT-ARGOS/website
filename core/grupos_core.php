<?php

// Lógica de negócio dos grupos (ex: CIDIT) — agrupamentos que cruzam esquadrões.
// Usada tanto pela API (/api/grupos.php) quanto pelas telas do painel.

require_once __DIR__ . '/motivos_core.php';
require_once __DIR__ . '/validacao_core.php';

const CATEGORIAS_GRUPO_VALIDAS = ['clube', 'servico', 'comissao'];

// grupos.nome é VARCHAR(50) (database/init_db.sql).
const GRUPO_LIMITE_NOME = 50;

function listarGrupos($conexao, $incluirInativos = false) {
    $where = $incluirInativos ? "" : "WHERE ativo = 1";
    $resultado = mysqli_query($conexao, "SELECT * FROM grupos $where ORDER BY nome");
    return mysqli_fetch_all($resultado, MYSQLI_ASSOC);
}

function buscarGrupoPorId($conexao, $id) {
    $stmt = mysqli_prepare($conexao, "SELECT * FROM grupos WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

function buscarGrupoPorNome($conexao, $nome) {
    $stmt = mysqli_prepare($conexao, "SELECT * FROM grupos WHERE nome = ?");
    mysqli_stmt_bind_param($stmt, "s", $nome);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

/**
 * @return array{ok: bool, erro?: string, id?: int}
 */
function criarGrupo($conexao, $nome, $categoria) {
    $nome = trim($nome);
    if ($nome === '') {
        return ['ok' => false, 'erro' => 'Informe o nome do grupo.'];
    }
    if (!in_array($categoria, CATEGORIAS_GRUPO_VALIDAS)) {
        return ['ok' => false, 'erro' => 'Categoria inválida.'];
    }
    if (mb_strlen($nome) > GRUPO_LIMITE_NOME) {
        return ['ok' => false, 'erro' => "Nome excede o tamanho máximo permitido (" . GRUPO_LIMITE_NOME . " caracteres)."];
    }
    if (buscarGrupoPorNome($conexao, $nome)) {
        return ['ok' => false, 'erro' => 'Já existe um grupo com esse nome.'];
    }

    $stmt = mysqli_prepare($conexao, "INSERT INTO grupos (nome, categoria) VALUES (?, ?)");
    mysqli_stmt_bind_param($stmt, "ss", $nome, $categoria);
    mysqli_stmt_execute($stmt);
    $grupoId = mysqli_insert_id($conexao);

    // Todo grupo também vira uma opção de motivo de falta (ex: marcar "CIDIT" como
    // motivo na chamada normal da esquadrilha, sem precisar de retirada própria).
    criarMotivoSeNaoExiste($conexao, $nome);

    return ['ok' => true, 'id' => $grupoId];
}

function excluirGrupo($conexao, $id) {
    $stmt = mysqli_prepare($conexao, "UPDATE grupos SET ativo = 0 WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    return mysqli_stmt_execute($stmt);
}

function listarMembros($conexao, $grupoId) {
    $stmt = mysqli_prepare($conexao, "
        SELECT a.id, a.posto_graduacao, COALESCE(p.exibicao, a.posto_graduacao) AS posto_exibicao,
               a.especialidade, a.nome_guerra, a.milhao, a.esquadrao, a.esquadrilha
        FROM grupo_membros gm
        JOIN alunos a ON a.id = gm.aluno_id
        LEFT JOIN postos_graduacao p ON p.codigo = a.posto_graduacao
        WHERE gm.grupo_id = ?
        ORDER BY a.nome_guerra
    ");
    mysqli_stmt_bind_param($stmt, "i", $grupoId);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

function adicionarMembro($conexao, $grupoId, $alunoId) {
    $stmt = mysqli_prepare($conexao, "INSERT IGNORE INTO grupo_membros (grupo_id, aluno_id) VALUES (?, ?)");
    mysqli_stmt_bind_param($stmt, "ii", $grupoId, $alunoId);
    return mysqli_stmt_execute($stmt);
}

function removerMembro($conexao, $grupoId, $alunoId) {
    $stmt = mysqli_prepare($conexao, "DELETE FROM grupo_membros WHERE grupo_id = ? AND aluno_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $grupoId, $alunoId);
    return mysqli_stmt_execute($stmt);
}

/**
 * Alunos ativos que batem com o termo de busca e ainda não são membros do grupo.
 */
function buscarCandidatosGrupo($conexao, $grupoId, $termo, $limite = 20) {
    if (trim($termo) === '') {
        return [];
    }
    $like = "%$termo%";
    $limite = (int) $limite;
    $stmt = mysqli_prepare($conexao, "
        SELECT a.id, a.posto_graduacao, COALESCE(p.exibicao, a.posto_graduacao) AS posto_exibicao,
               a.especialidade, a.nome_guerra, a.milhao, a.esquadrao, a.esquadrilha
        FROM alunos a
        LEFT JOIN postos_graduacao p ON p.codigo = a.posto_graduacao
        WHERE a.ativo = 1 AND (a.nome_guerra LIKE ? OR a.milhao LIKE ?)
        AND a.id NOT IN (SELECT aluno_id FROM grupo_membros WHERE grupo_id = ?)
        ORDER BY a.nome_guerra LIMIT $limite
    ");
    mysqli_stmt_bind_param($stmt, "ssi", $like, $like, $grupoId);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

function listarAlunosPorGrupo($conexao, $nomeGrupo) {
    $stmt = mysqli_prepare($conexao, "
        SELECT a.id, a.nome_guerra, a.milhao
        FROM alunos a
        JOIN grupo_membros gm ON gm.aluno_id = a.id
        JOIN grupos g ON g.id = gm.grupo_id
        WHERE g.nome = ? AND a.ativo = 1
        ORDER BY a.nome_guerra
    ");
    mysqli_stmt_bind_param($stmt, "s", $nomeGrupo);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}
