<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/retiradas_core.php';

$metodo = $_SERVER['REQUEST_METHOD'];

switch ($metodo) {

    // ---------- LISTAR ITENS DE UMA RETIRADA ----------
    case 'GET':
        $retiradaId = isset($_GET['retirada_id']) ? (int) $_GET['retirada_id'] : null;
        if (!$retiradaId) {
            erro("Informe retirada_id (?retirada_id=).");
        }

        responder(listarItensRetirada($conexao, $retiradaId));
        break;

    // ---------- MARCAR PRESENÇA/FALTA DE UM ALUNO ----------
    case 'PUT':
        $retiradaId = isset($_GET['retirada_id']) ? (int) $_GET['retirada_id'] : null;
        $alunoId = isset($_GET['aluno_id']) ? (int) $_GET['aluno_id'] : null;

        if (!$retiradaId || !$alunoId) {
            erro("Informe retirada_id e aluno_id na query string.");
        }

        $dados = corpoJson();
        if (!isset($dados['presente'])) {
            erro("Campo obrigatório ausente: presente (1 ou 0).");
        }

        $presente = (int)$dados['presente'];
        $motivoFaltaId = !empty($dados['motivo_falta_id']) ? (int)$dados['motivo_falta_id'] : null;
        $observacao = $dados['observacao'] ?? null;

        if ($presente === 0 && !$motivoFaltaId) {
            erro("Para marcar falta, informe motivo_falta_id.");
        }

        $ok = marcarItem($conexao, $retiradaId, $alunoId, $presente, $motivoFaltaId, $observacao);
        if (!$ok) {
            erro("Erro ao marcar item: " . mysqli_error($conexao), 500);
        }

        responder(['atualizado' => true]);
        break;

    default:
        erro("Método não suportado.", 405);
}

mysqli_close($conexao);
