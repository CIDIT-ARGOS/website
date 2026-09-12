<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../grupos_core.php';

$metodo = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;

switch ($metodo) {

    // ---------- LISTAR / BUSCAR (com membros) ----------
    case 'GET':
        if ($id) {
            $grupo = buscarGrupoPorId($conexao, $id);
            if (!$grupo) {
                erro("Grupo não encontrado.", 404);
            }
            $grupo['membros'] = listarMembros($conexao, $id);
            responder($grupo);
        }

        responder(listarGrupos($conexao));
        break;

    // ---------- CRIAR GRUPO OU ADICIONAR MEMBRO ----------
    case 'POST':
        $dados = corpoJson();

        // ?id=X + aluno_id no corpo -> adiciona membro ao grupo existente
        if ($id) {
            if (empty($dados['aluno_id'])) {
                erro("Informe aluno_id.");
            }
            if (!buscarGrupoPorId($conexao, $id)) {
                erro("Grupo não encontrado.", 404);
            }
            adicionarMembro($conexao, $id, (int) $dados['aluno_id']);
            responder(['adicionado' => true]);
        }

        // sem ?id= -> cria grupo novo
        if (empty($dados['nome']) || empty($dados['categoria'])) {
            erro("Campos obrigatórios: nome, categoria.");
        }
        $resultado = criarGrupo($conexao, $dados['nome'], $dados['categoria']);
        if (!$resultado['ok']) {
            erro($resultado['erro']);
        }
        responder(['id' => $resultado['id']], 201);
        break;

    // ---------- REMOVER MEMBRO ----------
    case 'DELETE':
        $alunoId = isset($_GET['aluno_id']) ? (int) $_GET['aluno_id'] : null;
        if (!$id || !$alunoId) {
            erro("Informe id (do grupo) e aluno_id na query string.");
        }
        removerMembro($conexao, $id, $alunoId);
        responder(['removido' => true]);
        break;

    default:
        erro("Método não suportado.", 405);
}

mysqli_close($conexao);
