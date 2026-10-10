<?php

// Posto de serviço da sessão: em qual esquadrão/esquadrilha o aluno está de
// serviço. É isso que limita o que o app mostra e lança — a retirada de faltas
// de um esquadrão quase sempre é feita por alguém de outro. Provisório, até
// existir integração com a escala de serviço: por enquanto o próprio aluno
// escolhe ao entrar (e a escolha fica registrada na sessão).
//
//   GET  → onde ele está agora, de onde ele é, e as opções
//   PUT  { "esquadrao": "...", "esquadrilha": "..." } → troca

require_once __DIR__ . '/bootstrap.php';

$alunoSessao = exigirSessaoAluno($conexao);

$atual = fn($aluno) => ['esquadrao' => $aluno['esquadrao_servico'], 'esquadrilha' => $aluno['esquadrilha_servico']];

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET':
        responder([
            'servico' => $atual($alunoSessao),
            'origem' => ['esquadrao' => $alunoSessao['esquadrao'], 'esquadrilha' => $alunoSessao['esquadrilha']],
            'opcoes' => listarPostosDeServicoSessao($conexao),
        ]);
        break;

    case 'PUT':
        $dados = corpoJson();
        $esquadrao = is_string($dados['esquadrao'] ?? null) ? trim($dados['esquadrao']) : '';
        $esquadrilha = is_string($dados['esquadrilha'] ?? null) ? trim($dados['esquadrilha']) : '';
        if ($esquadrao === '' || $esquadrilha === '') {
            erro("Informe esquadrao e esquadrilha.");
        }

        $resultado = definirPostoDeServicoSessao($conexao, (int) $alunoSessao['sessao_id'], $esquadrao, $esquadrilha);
        if (!$resultado['ok']) {
            erro($resultado['erro']);
        }
        responder(['servico' => ['esquadrao' => $esquadrao, 'esquadrilha' => $esquadrilha]]);
        break;

    default:
        erro("Método não suportado.", 405);
}

mysqli_close($conexao);
