<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../alunos_core.php';

$metodo = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;

switch ($metodo) {

    // ---------- LISTAR / BUSCAR ----------
    case 'GET':
        if ($id) {
            $aluno = buscarAlunoPorId($conexao, $id);
            if (!$aluno) {
                erro("Aluno não encontrado.", 404);
            }
            responder($aluno);
        }

        $filtros = [
            'esquadrilha' => $_GET['esquadrilha'] ?? null,
            'especialidade' => $_GET['especialidade'] ?? null,
            'esquadrao' => $_GET['esquadrao'] ?? null,
            'curso' => $_GET['curso'] ?? null,
            'qrcode_hash' => $_GET['qrcode_hash'] ?? null,
            'incluir_inativos' => isset($_GET['incluir_inativos']),
        ];

        responder(listarAlunos($conexao, $filtros));
        break;

    // ---------- CRIAR ----------
    case 'POST':
        $dados = corpoJson();
        $resultado = criarAluno($conexao, $dados);
        if (!$resultado['ok']) {
            erro($resultado['erro'], $resultado['codigo'] ?? 400);
        }
        responder(['id' => $resultado['id']], 201);
        break;

    // ---------- ATUALIZAR ----------
    case 'PUT':
        if (!$id) {
            erro("Informe o id do aluno (?id=).");
        }

        $dados = corpoJson();
        $resultado = atualizarAluno($conexao, $id, $dados);
        if (!$resultado['ok']) {
            erro($resultado['erro'], $resultado['codigo'] ?? 400);
        }
        responder(['atualizado' => true]);
        break;

    // ---------- INATIVAR (soft delete) ----------
    case 'DELETE':
        if (!$id) {
            erro("Informe o id do aluno (?id=).");
        }

        inativarAluno($conexao, $id);
        responder(['inativado' => true]);
        break;

    default:
        erro("Método não suportado.", 405);
}

mysqli_close($conexao);
