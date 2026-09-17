<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/retiradas_core.php';
require_once __DIR__ . '/../core/alunos_core.php';

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

    // ---------- ABRIR NOVA RETIRADA (exige sessão de aluno — issue #26) ----------
    case 'POST':
        $alunoSessao = exigirSessaoAluno($conexao);
        $dados = corpoJson();

        foreach (['tipo', 'agrupamento_tipo', 'agrupamento_valor'] as $campo) {
            if (empty($dados[$campo])) {
                erro("Campo obrigatório ausente: $campo");
            }
        }

        $esquadraoAlvo = $dados['esquadrao'] ?? null;
        if ($dados['agrupamento_tipo'] === 'esquadrilha' && $esquadraoAlvo !== $alunoSessao['esquadrao']) {
            erro("Você só pode abrir retirada do seu próprio esquadrão.", 403);
        }

        // responsavel_nome não vem mais do cliente — é sempre quem está de
        // fato logado na sessão, pra não dar pra abrir uma retirada e
        // atribuí-la ao nome de outra pessoa.
        $resultado = abrirRetirada(
            $conexao,
            $dados['tipo'],
            $dados['agrupamento_tipo'],
            $dados['agrupamento_valor'],
            $esquadraoAlvo,
            identificacaoAluno($alunoSessao),
            null,
            $alunoSessao['id']
        );

        if (!$resultado['ok']) {
            erro($resultado['erro']);
        }

        responder(['id' => $resultado['retirada_id']], 201);
        break;

    // ---------- ENVIAR (finalizar) — exige sessão de aluno ----------
    case 'PUT':
        $alunoSessao = exigirSessaoAluno($conexao);

        if (!$id) {
            erro("Informe o id da retirada (?id=).");
        }
        if (($_GET['acao'] ?? '') !== 'enviar') {
            erro("Use ?id=X&acao=enviar para finalizar a retirada.");
        }

        $retiradaAlvo = buscarRetiradaPorId($conexao, $id);
        if (!$retiradaAlvo) {
            erro("Retirada não encontrada.", 404);
        }
        if ($retiradaAlvo['esquadrao'] !== null && $retiradaAlvo['esquadrao'] !== $alunoSessao['esquadrao']) {
            erro("Você só pode enviar retiradas do seu próprio esquadrão.", 403);
        }

        $resultado = enviarRetirada($conexao, $id);
        if (!$resultado['ok']) {
            erro($resultado['erro'], 409);
        }

        responder(['protocolo' => $resultado['protocolo']]);
        break;

    // ---------- EXCLUIR ----------
    case 'DELETE':
        if (!$id) {
            erro("Informe o id da retirada (?id=).");
        }

        $resultado = excluirRetirada($conexao, $id);
        if (!$resultado['ok']) {
            erro($resultado['erro'], 404);
        }

        responder(['excluida' => true]);
        break;

    default:
        erro("Método não suportado.", 405);
}

mysqli_close($conexao);
