<?php

// Tramitação do Livro do Dia, em três níveis:
//
//   Aluno de Dia à Esquadrilha  → confere o livro da esquadrilha e envia
//   Aluno de Dia ao Esquadrão   → valida (ou devolve) os das esquadrilhas,
//                                  reúne e envia o livro do esquadrão
//   Aluno de Dia ao CA          → valida (ou devolve) os dos esquadrões,
//                                  reúne e envia o Livro do Dia ao Corpo de Alunos
//
// O corpo de cada livro continua vindo das chamadas enviadas e das dispensas
// (core/livro_core.php). A tabela `livros` guarda o que é da tramitação: a
// situação, as alterações escritas à mão, quem enviou, quem validou e o texto
// como foi enviado — o que foi validado não muda se alguém mexer numa chamada
// depois.
//
// Situações: rascunho → enviado → validado, ou enviado → devolvido → enviado…
// Só o dono mexe no livro, e só em rascunho ou devolvido. O livro do CA não
// tem quem valide: enviado é final, mas o Aluno de Dia ao CA pode reabrir.

require_once __DIR__ . '/livro_core.php';

const LIVRO_NIVEIS = [
    'esquadrilha' => 'Aluno de Dia à Esquadrilha',
    'esquadrao' => 'Aluno de Dia ao Esquadrão',
    'ca' => 'Aluno de Dia ao Corpo de Alunos',
];
const LIVRO_STATUS = [
    'rascunho' => 'Não enviado',
    'enviado' => 'Enviado',
    'validado' => 'Validado',
    'devolvido' => 'Devolvido',
];
// Quem recebe o livro de cada nível (e, portanto, quem valida).
const LIVRO_NIVEL_ACIMA = ['esquadrilha' => 'esquadrao', 'esquadrao' => 'ca'];
const LIVRO_LIMITE_OBSERVACOES = 4000;
const LIVRO_LIMITE_DEVOLUCAO = 500;

/** Nível abaixo (de quem este nível recebe livros), ou null pra esquadrilha. */
function nivelAbaixoDoLivro($nivel) {
    return array_search($nivel, LIVRO_NIVEL_ACIMA, true) ?: null;
}

/**
 * Deixa esquadrão/esquadrilha no formato da chave do livro: vazios quando não
 * se aplicam ao nível. Devolve [$esquadrao, $esquadrilha].
 */
function chaveDoLivro($nivel, $esquadrao, $esquadrilha) {
    if ($nivel === 'ca') {
        return ['', ''];
    }
    return [(string) $esquadrao, $nivel === 'esquadrilha' ? (string) $esquadrilha : ''];
}

/** O livro que é do aluno nesta sessão: [$nivel, $esquadrao, $esquadrilha]. */
function livroDaSessao($alunoSessao) {
    $nivel = $alunoSessao['funcao_servico'] ?? 'esquadrilha';
    return array_merge([$nivel], chaveDoLivro($nivel, $alunoSessao['esquadrao_servico'], $alunoSessao['esquadrilha_servico']));
}

/** "Esquadrilha A", "Esquadrão Prata" ou "Corpo de Alunos". */
function rotuloDoLivro($nivel, $esquadrao, $esquadrilha) {
    if ($nivel === 'ca') {
        return 'Corpo de Alunos';
    }
    return $nivel === 'esquadrao' ? rotuloEsquadrao($esquadrao) : "Esquadrilha $esquadrilha";
}

function _livroNomeDoAluno($conexao, $alunoId) {
    static $cache = [];
    if (!$alunoId) {
        return null;
    }
    if (!array_key_exists($alunoId, $cache)) {
        $stmt = mysqli_prepare($conexao, "
            SELECT a.posto_graduacao, COALESCE(p.exibicao, a.posto_graduacao) AS posto_exibicao, a.especialidade, a.nome_guerra, a.milhao
            FROM alunos a LEFT JOIN postos_graduacao p ON p.codigo = a.posto_graduacao WHERE a.id = ?
        ");
        mysqli_stmt_bind_param($stmt, "i", $alunoId);
        mysqli_stmt_execute($stmt);
        $aluno = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        $cache[$alunoId] = $aluno ? identificacaoAluno($aluno) : null;
    }
    return $cache[$alunoId];
}

/**
 * O registro de tramitação de um livro. Livro que ninguém tocou ainda não tem
 * linha no banco: volta como rascunho vazio (id null).
 */
function buscarLivro($conexao, $data, $nivel, $esquadrao = '', $esquadrilha = '') {
    [$esquadrao, $esquadrilha] = chaveDoLivro($nivel, $esquadrao, $esquadrilha);

    $stmt = mysqli_prepare($conexao, "SELECT * FROM livros WHERE data = ? AND nivel = ? AND esquadrao = ? AND esquadrilha = ?");
    mysqli_stmt_bind_param($stmt, "ssss", $data, $nivel, $esquadrao, $esquadrilha);
    mysqli_stmt_execute($stmt);
    $livro = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: [
        'id' => null, 'data' => $data, 'nivel' => $nivel, 'esquadrao' => $esquadrao, 'esquadrilha' => $esquadrilha,
        'status' => 'rascunho', 'observacoes' => null, 'conteudo' => null,
        'enviado_por_aluno_id' => null, 'enviado_em' => null,
        'validado_por_aluno_id' => null, 'validado_em' => null, 'devolucao_motivo' => null,
    ];

    $livro['rotulo'] = rotuloDoLivro($nivel, $esquadrao, $esquadrilha);
    $livro['enviado_por'] = _livroNomeDoAluno($conexao, $livro['enviado_por_aluno_id']);
    $livro['validado_por'] = _livroNomeDoAluno($conexao, $livro['validado_por_aluno_id']);
    return $livro;
}

/** Os livros que este nível recebe: das esquadrilhas do esquadrão, ou dos esquadrões. */
function livrosRecebidos($conexao, $data, $nivel, $esquadrao = '') {
    $abaixo = nivelAbaixoDoLivro($nivel);
    if ($abaixo === 'esquadrao') {
        return array_map(fn($esq) => buscarLivro($conexao, $data, 'esquadrao', $esq), listarEsquadroesDistintos($conexao));
    }
    if ($abaixo === 'esquadrilha') {
        $stmt = mysqli_prepare($conexao, "SELECT DISTINCT esquadrilha FROM alunos WHERE ativo = 1 AND esquadrao = ? ORDER BY esquadrilha");
        mysqli_stmt_bind_param($stmt, "s", $esquadrao);
        mysqli_stmt_execute($stmt);
        $livros = [];
        foreach (mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC) as $linha) {
            $livros[] = buscarLivro($conexao, $data, 'esquadrilha', $esquadrao, $linha['esquadrilha']);
        }
        return $livros;
    }
    return [];
}

/** "Validado — enviado por X, validado por Y", pra linha de resumo de um livro recebido. */
function situacaoDoLivroPorExtenso($livro) {
    $partes = [LIVRO_STATUS[$livro['status']]];
    if ($livro['status'] !== 'rascunho' && $livro['enviado_por']) {
        $partes[] = "enviado por {$livro['enviado_por']}";
    }
    if ($livro['status'] === 'validado' && $livro['validado_por']) {
        $partes[] = "validado por {$livro['validado_por']}";
    }
    if ($livro['status'] === 'devolvido' && $livro['validado_por']) {
        $partes[] = "devolvido por {$livro['validado_por']}";
    }
    return implode(' — ', array_filter([array_shift($partes), implode(', ', $partes)]));
}

function _livroBlocoObservacoes($observacoes) {
    $texto = trim((string) $observacoes);
    return ['', 'ALTERAÇÕES E OBSERVAÇÕES', $texto !== '' ? $texto : 'Não há.'];
}

/**
 * Monta um livro inteiro: o registro de tramitação, o corpo (seções e
 * dispensas — null no nível do CA, que só reúne), os livros recebidos (cada
 * um já com o seu `texto`) e o texto completo.
 *
 * Livro recebido que já foi enviado entra como foi enviado; o que ainda não
 * foi entra com os dados do sistema, avisando que não foi enviado.
 */
function montarLivroDoNivel($conexao, $data, $nivel, $esquadrao = '', $esquadrilha = '') {
    [$esquadrao, $esquadrilha] = chaveDoLivro($nivel, $esquadrao, $esquadrilha);
    $registro = buscarLivro($conexao, $data, $nivel, $esquadrao, $esquadrilha);

    $recebidos = [];
    foreach (livrosRecebidos($conexao, $data, $nivel, $esquadrao) as $recebido) {
        $recebido['texto'] = in_array($recebido['status'], ['enviado', 'validado'], true) && $recebido['conteudo'] !== null
            ? $recebido['conteudo']
            : montarLivroDoNivel($conexao, $data, $recebido['nivel'], $recebido['esquadrao'], $recebido['esquadrilha'])['texto'];
        $recebidos[] = $recebido;
    }

    if ($nivel === 'ca') {
        $linhas = [
            'COMANDO DA AERONÁUTICA',
            'ESCOLA DE ESPECIALISTAS DE AERONÁUTICA',
            'CORPO DE ALUNOS',
            'LIVRO DO DIA AO CORPO DE ALUNOS — ' . dataEstiloLivro($data),
            '',
            'LIVROS DOS ESQUADRÕES',
        ];
        foreach ($recebidos as $r) {
            $linhas[] = "- {$r['rotulo']}: " . situacaoDoLivroPorExtenso($r);
        }
        foreach ($recebidos as $r) {
            $linhas[] = '';
            $linhas[] = str_repeat('=', 40);
            if (!in_array($r['status'], ['enviado', 'validado'], true)) {
                $linhas[] = '(' . mb_strtoupper($r['rotulo']) . ': livro não enviado — dados do sistema)';
            }
            // O livro do esquadrão já traz o próprio cabeçalho; aqui só o corpo dele.
            $linhas[] = preg_replace('/\n\nGerado pelo Argos\.$/u', '', $r['texto']);
        }
        $linhas[] = '';
        $linhas[] = str_repeat('=', 40);
        $linhas = array_merge($linhas, array_slice(_livroBlocoObservacoes($registro['observacoes']), 1), ['', 'Gerado pelo Argos.']);

        return ['registro' => $registro, 'base' => null, 'recebidos' => $recebidos, 'texto' => implode("\n", $linhas)];
    }

    $base = montarLivroEsquadrao($conexao, $esquadrao, $data, $nivel === 'esquadrilha' ? $esquadrilha : null);

    $extras = [];
    if ($recebidos) {
        $extras = ['', 'LIVROS DAS ESQUADRILHAS'];
        foreach ($recebidos as $r) {
            $extras[] = "- {$r['rotulo']}: " . situacaoDoLivroPorExtenso($r);
            $observacoes = trim((string) $r['observacoes']);
            if ($observacoes !== '' && $r['status'] !== 'rascunho') {
                $extras[] = '  Alterações: ' . str_replace("\n", "\n  ", $observacoes);
            }
        }
    }
    $extras = array_merge($extras, _livroBlocoObservacoes($registro['observacoes']));

    return ['registro' => $registro, 'base' => $base, 'recebidos' => $recebidos, 'texto' => livroComoTexto($base, $data, $extras)];
}

function _livroGravar($conexao, $data, $nivel, $esquadrao, $esquadrilha, array $campos) {
    [$esquadrao, $esquadrilha] = chaveDoLivro($nivel, $esquadrao, $esquadrilha);

    $colunas = array_keys($campos);
    $sql = "INSERT INTO livros (data, nivel, esquadrao, esquadrilha, " . implode(', ', $colunas) . ")
            VALUES (?, ?, ?, ?" . str_repeat(', ?', count($colunas)) . ")
            ON DUPLICATE KEY UPDATE " . implode(', ', array_map(fn($c) => "$c = VALUES($c)", $colunas));
    $stmt = mysqli_prepare($conexao, $sql);
    $valores = array_merge([$data, $nivel, $esquadrao, $esquadrilha], array_values($campos));
    mysqli_stmt_bind_param($stmt, str_repeat('s', count($valores)), ...$valores);
    return mysqli_stmt_execute($stmt);
}

function _livroErro($mensagem, $status) {
    return ['ok' => false, 'erro' => $mensagem, 'status' => $status];
}

function _livroDonoPodeMexer($livro) {
    return in_array($livro['status'], ['rascunho', 'devolvido'], true);
}

/**
 * Grava as alterações e observações escritas pelo dono do livro. Só em
 * rascunho ou devolvido — depois de enviado, o livro fica como foi enviado.
 *
 * @return array{ok: bool, erro?: string, status?: int}
 */
function salvarObservacoesDoLivro($conexao, $data, $nivel, $esquadrao, $esquadrilha, $observacoes, $alunoId) {
    $livro = buscarLivro($conexao, $data, $nivel, $esquadrao, $esquadrilha);
    if (!_livroDonoPodeMexer($livro)) {
        return _livroErro('Este livro já foi enviado e não pode mais ser alterado.', 409);
    }

    $observacoes = mb_substr(trim((string) $observacoes), 0, LIVRO_LIMITE_OBSERVACOES);
    _livroGravar($conexao, $data, $nivel, $esquadrao, $esquadrilha, [
        'observacoes' => $observacoes !== '' ? $observacoes : null,
        'editado_por_aluno_id' => $alunoId,
    ]);
    return ['ok' => true];
}

/**
 * Envia o livro pro nível de cima (ou, no CA, fecha o Livro do Dia). Guarda o
 * texto como está agora: é esse que vai ser validado.
 */
function enviarLivro($conexao, $data, $nivel, $esquadrao, $esquadrilha, $alunoId) {
    $livro = buscarLivro($conexao, $data, $nivel, $esquadrao, $esquadrilha);
    if (!_livroDonoPodeMexer($livro)) {
        return _livroErro('Este livro já foi enviado.', 409);
    }

    $texto = montarLivroDoNivel($conexao, $data, $nivel, $esquadrao, $esquadrilha)['texto'];
    _livroGravar($conexao, $data, $nivel, $esquadrao, $esquadrilha, [
        'status' => 'enviado',
        'conteudo' => $texto,
        'enviado_por_aluno_id' => $alunoId,
        'enviado_em' => date('Y-m-d H:i:s'),
        'validado_por_aluno_id' => null,
        'validado_em' => null,
        'devolucao_motivo' => null,
    ]);
    return ['ok' => true];
}

/**
 * Valida ou devolve um livro recebido. Só vale pra livro na situação
 * "enviado"; devolver exige o motivo, e o livro volta pro dono corrigir.
 */
function julgarLivro($conexao, $data, $nivel, $esquadrao, $esquadrilha, $validar, $motivo, $alunoId) {
    $livro = buscarLivro($conexao, $data, $nivel, $esquadrao, $esquadrilha);
    if ($livro['status'] !== 'enviado') {
        return _livroErro("Só dá pra validar ou devolver livro enviado — este está: " . LIVRO_STATUS[$livro['status']] . '.', 409);
    }

    $motivo = mb_substr(trim((string) $motivo), 0, LIVRO_LIMITE_DEVOLUCAO);
    if (!$validar && $motivo === '') {
        return _livroErro('Informe o motivo da devolução.', 400);
    }

    _livroGravar($conexao, $data, $nivel, $esquadrao, $esquadrilha, [
        'status' => $validar ? 'validado' : 'devolvido',
        'validado_por_aluno_id' => $alunoId,
        'validado_em' => date('Y-m-d H:i:s'),
        'devolucao_motivo' => $validar ? null : $motivo,
    ]);
    return ['ok' => true];
}

/**
 * Reabre o Livro do Dia ao Corpo de Alunos já enviado, pra corrigir e enviar
 * de novo. Só o do CA: os outros voltam pelo "devolver" de quem valida.
 */
function reabrirLivroDoCa($conexao, $data, $alunoId) {
    $livro = buscarLivro($conexao, $data, 'ca');
    if ($livro['status'] !== 'enviado') {
        return _livroErro('O livro do Corpo de Alunos não está enviado.', 409);
    }
    _livroGravar($conexao, $data, 'ca', '', '', ['status' => 'rascunho', 'editado_por_aluno_id' => $alunoId]);
    return ['ok' => true];
}

/** Um registro de livro no formato que a API devolve (sem o que é interno). */
function registroDoLivroParaApi($livro) {
    return [
        'nivel' => $livro['nivel'],
        'esquadrao' => $livro['esquadrao'],
        'esquadrilha' => $livro['esquadrilha'],
        'rotulo' => $livro['rotulo'],
        'status' => $livro['status'],
        'status_rotulo' => LIVRO_STATUS[$livro['status']],
        'observacoes' => $livro['observacoes'],
        'enviado_por' => $livro['enviado_por'],
        'enviado_em' => $livro['enviado_em'],
        'validado_por' => $livro['validado_por'],
        'validado_em' => $livro['validado_em'],
        'devolucao_motivo' => $livro['devolucao_motivo'],
    ];
}
