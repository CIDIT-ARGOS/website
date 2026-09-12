<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../retiradas_core.php';

$metodo = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;

switch ($metodo) {

    // ---------- LISTAR / BUSCAR ----------
    case 'GET':
        if ($id) {
            $retirada = buscarRetiradaPorId($conexao, $id);
            if (!$retirada) {
                erro("Retirada não encontrada.", 404);
            }
            responder($retirada);
        }

        $filtros = [
            'esquadrao' => $_GET['esquadrao'] ?? null,
            'status' => $_GET['status'] ?? null,
            'tipo' => $_GET['tipo'] ?? null,
        ];
        responder(listarRetiradas($conexao, $filtros));
        break;

    // ---------- ABRIR NOVA RETIRADA ----------
    case 'POST':
        $dados = corpoJson();

        foreach (['tipo', 'agrupamento_tipo', 'agrupamento_valor', 'aluno_servico_id'] as $campo) {
            if (empty($dados[$campo])) {
                erro("Campo obrigatório ausente: $campo");
            }
        }

        $resultado = abrirRetirada(
            $conexao,
            $dados['tipo'],
            $dados['agrupamento_tipo'],
            $dados['agrupamento_valor'],
            $dados['esquadrao'] ?? null,
            $dados['aluno_servico_id']
        );

        if (!$resultado['ok']) {
            erro($resultado['erro']);
        }

        responder(['id' => $resultado['retirada_id']], 201);
        break;

    // ---------- ENVIAR (finalizar) ----------
    case 'PUT':
        if (!$id) {
            erro("Informe o id da retirada (?id=).");
        }
        if (($_GET['acao'] ?? '') !== 'enviar') {
            erro("Use ?id=X&acao=enviar para finalizar a retirada.");
        }

        $resultado = enviarRetirada($conexao, $id);
        if (!$resultado['ok']) {
            erro($resultado['erro'], 409);
        }

        responder(['protocolo' => $resultado['protocolo']]);
        break;

    default:
        erro("Método não suportado.", 405);
}

mysqli_close($conexao);
