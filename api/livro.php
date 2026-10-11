<?php

// Livro do Dia do aluno de serviço, conforme a função dele na sessão:
//   Aluno de Dia à Esquadrilha → o livro da esquadrilha
//   Aluno de Dia ao Esquadrão  → o do esquadrão + os das esquadrilhas, pra validar
//   Aluno de Dia ao CA         → o do Corpo de Alunos + os dos esquadrões, pra validar
// Regras da tramitação em core/livro_fluxo_core.php. Exige sessão de aluno.
//
//   GET  ?data=AAAA-MM-DD → o livro (seções, dispensas, texto), a situação e os recebidos
//   PUT  ?data=…  { "observacoes": "..." }  → grava as alterações do próprio livro
//   POST ?data=…  { "acao": "enviar" }      → envia o próprio livro
//                 { "acao": "reabrir" }     → (só CA) reabre o livro já enviado
//                 { "acao": "validar" | "devolver", "esquadrao": "...", "esquadrilha": "...", "motivo": "..." }
//                                            → valida ou devolve um livro recebido

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/livro_fluxo_core.php';

$alunoSessao = exigirSessaoAluno($conexao);
$alunoId = (int) $alunoSessao['id'];

$data = $_GET['data'] ?? date('Y-m-d');
$dataValida = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
if (!$dataValida || $dataValida->format('Y-m-d') !== $data) {
    erro("Data inválida. Use o formato AAAA-MM-DD.");
}

[$nivel, $esquadrao, $esquadrilha] = livroDaSessao($alunoSessao);
$nivelRecebido = nivelAbaixoDoLivro($nivel);

$concluir = function ($resultado) {
    if (!$resultado['ok']) {
        erro($resultado['erro'], $resultado['status']);
    }
};

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET':
        break;

    case 'PUT':
        $dados = corpoJson();
        if (!is_string($dados['observacoes'] ?? null)) {
            erro("Informe observacoes (texto).");
        }
        $concluir(salvarObservacoesDoLivro($conexao, $data, $nivel, $esquadrao, $esquadrilha, $dados['observacoes'], $alunoId));
        break;

    case 'POST':
        $dados = corpoJson();
        $acao = $dados['acao'] ?? null;

        if ($acao === 'enviar') {
            $concluir(enviarLivro($conexao, $data, $nivel, $esquadrao, $esquadrilha, $alunoId));
        } elseif ($acao === 'reabrir') {
            if ($nivel !== 'ca') {
                erro("Só o Aluno de Dia ao Corpo de Alunos reabre o livro. Peça a devolução a quem valida.", 403);
            }
            $concluir(reabrirLivroDoCa($conexao, $data, $alunoId));
        } elseif ($acao === 'validar' || $acao === 'devolver') {
            if ($nivelRecebido === null) {
                erro("O Aluno de Dia à Esquadrilha não valida livros.", 403);
            }
            // O alvo é sempre um livro que este aluno recebe: esquadrilha do
            // esquadrão em que ele está de serviço, ou um esquadrão (no CA).
            $alvoEsquadrao = $nivel === 'ca' ? ($dados['esquadrao'] ?? null) : $esquadrao;
            $alvoEsquadrilha = $nivelRecebido === 'esquadrilha' ? ($dados['esquadrilha'] ?? null) : '';
            if (!is_string($alvoEsquadrao) || !is_string($alvoEsquadrilha)) {
                erro("Informe de qual " . ($nivelRecebido === 'esquadrilha' ? 'esquadrilha' : 'esquadrão') . " é o livro.");
            }
            $existe = array_filter(
                livrosRecebidos($conexao, $data, $nivel, $esquadrao),
                fn($l) => $l['esquadrao'] === $alvoEsquadrao && $l['esquadrilha'] === $alvoEsquadrilha
            );
            if (!$existe) {
                erro("Livro não encontrado entre os que você recebe.", 404);
            }
            $motivo = is_string($dados['motivo'] ?? null) ? $dados['motivo'] : '';
            $concluir(julgarLivro($conexao, $data, $nivelRecebido, $alvoEsquadrao, $alvoEsquadrilha, $acao === 'validar', $motivo, $alunoId));
        } else {
            erro("Ação inválida. Use enviar, reabrir, validar ou devolver.");
        }
        break;

    default:
        erro("Método não suportado.", 405);
}

// Toda resposta de sucesso é o livro como ficou.
$livro = montarLivroDoNivel($conexao, $data, $nivel, $esquadrao, $esquadrilha);
$registro = $livro['registro'];
// Depois de enviado, o texto do próprio livro é o que foi enviado.
if (in_array($registro['status'], ['enviado', 'validado'], true) && $registro['conteudo'] !== null) {
    $livro['texto'] = $registro['conteudo'];
}
$donoPodeMexer = in_array($registro['status'], ['rascunho', 'devolvido'], true);

// Seções e dispensas no formato de sempre; o CA só reúne, então vêm vazias.
$corpo = $livro['base'] !== null
    ? livroParaApi($livro['base'], $data)
    : ['data' => $data, 'data_extenso' => dataEstiloLivro($data), 'esquadrao' => '', 'secoes' => [], 'dispensas' => []];

responder(array_merge($corpo, [
    'esquadrao' => $nivel === 'ca' ? '' : $esquadrao,
    'esquadrilha' => $esquadrilha,
    'funcao' => $nivel,
    'funcao_rotulo' => LIVRO_NIVEIS[$nivel],
    'texto' => $livro['texto'],
    'livro' => registroDoLivroParaApi($registro) + [
        'pode_editar' => $donoPodeMexer,
        'pode_enviar' => $donoPodeMexer,
        'pode_reabrir' => $nivel === 'ca' && $registro['status'] === 'enviado',
        'destino' => isset(LIVRO_NIVEL_ACIMA[$nivel]) ? LIVRO_NIVEIS[LIVRO_NIVEL_ACIMA[$nivel]] : null,
    ],
    'recebidos' => array_map(fn($r) => registroDoLivroParaApi($r) + [
        'texto' => $r['texto'],
        'pode_validar' => $r['status'] === 'enviado',
    ], $livro['recebidos']),
]));

mysqli_close($conexao);
