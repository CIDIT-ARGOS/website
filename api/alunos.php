<?php

require_once __DIR__ . '/bootstrap.php';

$metodo = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;

$camposObrigatorios = [
    'posto_graduacao', 'nome_guerra', 'sexo', 'identidade_militar',
    'milhao', 'esquadrao', 'esquadrilha', 'curso', 'serie'
];

$cursosValidos = ['CFS', 'EAGS'];

function validarAluno($dados) {
    global $camposObrigatorios, $cursosValidos;

    foreach ($camposObrigatorios as $campo) {
        if (empty($dados[$campo])) {
            return "Campo obrigatório ausente: $campo";
        }
    }

    if (!in_array($dados['sexo'], ['M', 'F'])) {
        return "Campo sexo inválido. Use M ou F.";
    }

    if (!in_array($dados['curso'], $cursosValidos)) {
        return "Curso inválido. Use CFS ou EAGS.";
    }

    if ($dados['curso'] === 'EAGS' && $dados['serie'] !== 'EAGS') {
        return "Para o curso EAGS, a série deve ser 'EAGS'.";
    }

    if ($dados['curso'] === 'CFS' && !in_array($dados['serie'], ['1', '2', '3', '4'])) {
        return "Para o curso CFS, a série deve ser 1, 2, 3 ou 4.";
    }

    if (!empty($dados['qrcode_hash']) && !preg_match('/^[a-f0-9]{64}$/i', $dados['qrcode_hash'])) {
        return "qrcode_hash inválido: deve ser um hash sha256 (64 caracteres hexadecimais).";
    }

    return null;
}

switch ($metodo) {

    // ---------- LISTAR / BUSCAR ----------
    case 'GET':
        if ($id) {
            $stmt = mysqli_prepare($conexao, "SELECT * FROM alunos WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt);
            $aluno = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

            if (!$aluno) {
                erro("Aluno não encontrado.", 404);
            }
            responder($aluno);
        }

        $filtros = [];
        $params = [];
        $tipos = "";

        if (!empty($_GET['esquadrilha'])) {
            $filtros[] = "esquadrilha = ?";
            $params[] = $_GET['esquadrilha'];
            $tipos .= "s";
        }
        if (!empty($_GET['especialidade'])) {
            $filtros[] = "especialidade = ?";
            $params[] = $_GET['especialidade'];
            $tipos .= "s";
        }
        if (!empty($_GET['esquadrao'])) {
            $filtros[] = "esquadrao = ?";
            $params[] = $_GET['esquadrao'];
            $tipos .= "s";
        }
        if (!empty($_GET['curso'])) {
            $filtros[] = "curso = ?";
            $params[] = $_GET['curso'];
            $tipos .= "s";
        }
        if (!empty($_GET['qrcode_hash'])) {
            $filtros[] = "qrcode_hash = ?";
            $params[] = $_GET['qrcode_hash'];
            $tipos .= "s";
        }
        if (!isset($_GET['incluir_inativos'])) {
            $filtros[] = "ativo = 1";
        }

        $where = $filtros ? "WHERE " . implode(" AND ", $filtros) : "";
        $sql = "SELECT * FROM alunos $where ORDER BY nome_guerra ASC";

        $stmt = mysqli_prepare($conexao, $sql);
        if ($params) {
            mysqli_stmt_bind_param($stmt, $tipos, ...$params);
        }
        mysqli_stmt_execute($stmt);
        $resultado = mysqli_stmt_get_result($stmt);

        $alunos = [];
        while ($linha = mysqli_fetch_assoc($resultado)) {
            $alunos[] = $linha;
        }

        responder($alunos);
        break;

    // ---------- CRIAR ----------
    case 'POST':
        $dados = corpoJson();
        $mensagemErro = validarAluno($dados);
        if ($mensagemErro) {
            erro($mensagemErro);
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
                erro("Já existe um aluno com essa identidade militar, milhão ou qrcode_hash.", 409);
            }
            erro("Erro ao criar aluno: " . mysqli_error($conexao), 500);
        }

        responder(['id' => mysqli_insert_id($conexao)], 201);
        break;

    // ---------- ATUALIZAR ----------
    case 'PUT':
        if (!$id) {
            erro("Informe o id do aluno (?id=).");
        }

        $dados = corpoJson();
        $mensagemErro = validarAluno($dados);
        if ($mensagemErro) {
            erro($mensagemErro);
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
            erro("Erro ao atualizar aluno: " . mysqli_error($conexao), 500);
        }

        responder(['atualizado' => true]);
        break;

    // ---------- INATIVAR (soft delete) ----------
    case 'DELETE':
        if (!$id) {
            erro("Informe o id do aluno (?id=).");
        }

        $stmt = mysqli_prepare($conexao, "UPDATE alunos SET ativo = 0 WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);

        responder(['inativado' => true]);
        break;

    default:
        erro("Método não suportado.", 405);
}

mysqli_close($conexao);
