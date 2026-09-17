<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/alunos_core.php';

$metodo = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;

switch ($metodo) {

    // ---------- LISTAR / BUSCAR (exige sessão de aluno — issue #26) ----------
    // Escopo sempre travado no próprio esquadrão da sessão: o app do aluno
    // usa isso pra montar o efetivo da chamada, não pra consultar o CA
    // inteiro. esquadrao da query string é ignorado de propósito.
    case 'GET':
        $alunoSessao = exigirSessaoAluno($conexao);

        if ($id) {
            $aluno = buscarAlunoPorId($conexao, $id);
            if (!$aluno || $aluno['esquadrao'] !== $alunoSessao['esquadrao']) {
                erro("Aluno não encontrado.", 404);
            }
            responder($aluno);
        }

        $filtros = [
            'esquadrilha' => $_GET['esquadrilha'] ?? null,
            'especialidade' => $_GET['especialidade'] ?? null,
            'esquadrao' => $alunoSessao['esquadrao'],
            'curso' => $_GET['curso'] ?? null,
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
