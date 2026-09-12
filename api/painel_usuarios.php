<?php

// Gestão dos usuários do Painel de Comando. Nunca expõe senha_hash — só o
// necessário para listar/gerenciar contas (ver painel_usuarios_core.php).

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../painel_usuarios_core.php';

$metodo = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;

switch ($metodo) {

    // ---------- LISTAR / BUSCAR ----------
    case 'GET':
        if ($id) {
            $usuario = buscarUsuarioPainelPorId($conexao, $id);
            if (!$usuario) {
                erro("Usuário não encontrado.", 404);
            }
            responder($usuario);
        }
        responder(listarUsuariosPainel($conexao));
        break;

    // ---------- CRIAR ----------
    case 'POST':
        $dados = corpoJson();
        $resultado = criarUsuarioPainel($conexao, $dados);
        if (!$resultado['ok']) {
            erro($resultado['erro']);
        }
        responder(['id' => $resultado['id']], 201);
        break;

    // ---------- ATUALIZAR / RESETAR SENHA ----------
    case 'PUT':
        if (!$id) {
            erro("Informe o id do usuário (?id=).");
        }

        $dados = corpoJson();

        if (($_GET['acao'] ?? '') === 'resetar_senha') {
            if (empty($dados['nova_senha'])) {
                erro("Informe nova_senha.");
            }
            $resultado = resetarSenhaUsuarioPainel($conexao, $id, $dados['nova_senha']);
        } else {
            $resultado = atualizarUsuarioPainel($conexao, $id, $dados);
        }

        if (!$resultado['ok']) {
            erro($resultado['erro']);
        }
        responder(['atualizado' => true]);
        break;

    // ---------- EXCLUIR ----------
    case 'DELETE':
        if (!$id) {
            erro("Informe o id do usuário (?id=).");
        }
        $resultado = excluirUsuarioPainel($conexao, $id);
        if (!$resultado['ok']) {
            erro($resultado['erro']);
        }
        responder(['excluido' => true]);
        break;

    default:
        erro("Método não suportado.", 405);
}

mysqli_close($conexao);
