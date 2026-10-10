<?php

// Dispensas médicas pelo app do aluno de serviço. Exige sessão de aluno e é
// sempre restrito ao esquadrão da sessão: lista as dispensas do esquadrão e
// lança dispensa só pra aluno ativo do próprio esquadrão. Editar e excluir
// continuam só no Painel de Comando.

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/dispensas_core.php';
require_once __DIR__ . '/../core/alunos_core.php';

$alunoSessao = exigirSessaoAluno($conexao);

switch ($_SERVER['REQUEST_METHOD']) {

    // ---------- LISTAR ----------
    // Padrão: as ativas hoje. ?todas=1 traz também as encerradas e futuras;
    // ?tipos=1 devolve o catálogo de "dispensado de" pro formulário.
    case 'GET':
        if (isset($_GET['tipos'])) {
            responder(listarDispensaTipos($conexao));
        }

        $filtros = ['esquadrao' => $alunoSessao['esquadrao']];
        if (!isset($_GET['todas'])) {
            $filtros['ativas_em'] = date('Y-m-d');
        }
        responder(listarDispensas($conexao, $filtros));
        break;

    // ---------- LANÇAR ----------
    case 'POST':
        $dados = corpoJson();

        $aluno = buscarAlunoPorId($conexao, (int) ($dados['aluno_id'] ?? 0));
        if (!$aluno || (int) $aluno['ativo'] !== 1 || $aluno['esquadrao'] !== $alunoSessao['esquadrao']) {
            erro("Aluno não encontrado no seu esquadrão.", 404);
        }

        // Campo de texto que vier como lista/objeto no JSON vira vazio (e cai
        // na validação normal), em vez de estourar no trim() do core.
        $texto = fn($campo) => is_scalar($dados[$campo] ?? null) ? (string) $dados[$campo] : '';

        $resultado = criarDispensa($conexao, [
            'aluno_id' => $aluno['id'],
            'data_inicio' => $texto('data_inicio'),
            'data_termino' => $texto('data_termino'),
            'numero' => $texto('numero'),
            'motivo' => $texto('motivo'),
            'dispensado_de' => $texto('dispensado_de'),
            'dispensa_tipo_ids' => is_array($dados['dispensa_tipo_ids'] ?? null) ? $dados['dispensa_tipo_ids'] : [],
        ]);
        if (!$resultado['ok']) {
            erro($resultado['erro']);
        }
        responder(['id' => $resultado['id']], 201);
        break;

    default:
        erro("Método não suportado.", 405);
}

mysqli_close($conexao);
