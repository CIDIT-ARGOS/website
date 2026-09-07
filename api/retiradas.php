<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../retiradas_core.php';

$metodo = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;

switch ($metodo) {

    // ---------- LISTAR / BUSCAR ----------
    case 'GET':
        if ($id) {
            $retirada = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT * FROM retiradas WHERE id = " . $id));
            if (!$retirada) {
                erro("Retirada não encontrada.", 404);
            }
            responder($retirada);
        }

        $filtros = [];
        $params = [];
        $tipos = "";

        if (!empty($_GET['esquadrao'])) {
            $filtros[] = "esquadrao = ?";
            $params[] = $_GET['esquadrao'];
            $tipos .= "s";
        }
        if (!empty($_GET['status'])) {
            $filtros[] = "status = ?";
            $params[] = $_GET['status'];
            $tipos .= "s";
        }
        if (!empty($_GET['tipo'])) {
            $filtros[] = "tipo = ?";
            $params[] = $_GET['tipo'];
            $tipos .= "s";
        }

        $where = $filtros ? "WHERE " . implode(" AND ", $filtros) : "";
        $stmt = mysqli_prepare($conexao, "SELECT * FROM retiradas $where ORDER BY data_hora DESC");
        if ($params) {
            mysqli_stmt_bind_param($stmt, $tipos, ...$params);
        }
        mysqli_stmt_execute($stmt);
        $resultado = mysqli_stmt_get_result($stmt);

        $retiradas = [];
        while ($linha = mysqli_fetch_assoc($resultado)) {
            $retiradas[] = $linha;
        }
        responder($retiradas);
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
