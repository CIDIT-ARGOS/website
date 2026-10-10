<?php

// Livro do Dia (Livro de Serviço do Aluno de Dia): o resumo padronizado de um
// esquadrão num dia de serviço, montado a partir das chamadas enviadas e das
// dispensas médicas — o que antes era digitado à mão e passado por
// WhatsApp/e-mail.
//
// O dia de serviço segue a ordem dos lançamentos: almoço, 2ª Jornada e
// pernoite do próprio dia, e fecha com a 1ª Jornada do DIA SEGUINTE.
//
// Usado pelo Painel (web/painel/livro_do_dia.php) e pela API (/api/livro.php,
// que alimenta a Área Funcional). A fonte é uma só, então o livro que o aluno
// de serviço envia é igual ao que o comando vê.

require_once __DIR__ . '/retiradas_core.php';
require_once __DIR__ . '/dispensas_core.php';
require_once __DIR__ . '/alunos_core.php';

// Seções do livro, na ordem do serviço. `dia` é o deslocamento em relação à
// data do livro: a 1ª Jornada que entra é a do dia seguinte.
const LIVRO_SECOES = [
    'almoco' => ['rotulo' => 'ALMOÇO', 'dia' => 0],
    '2_jornada' => ['rotulo' => '2ª JORNADA', 'dia' => 0],
    'pernoite' => ['rotulo' => 'PERNOITE', 'dia' => 0],
    '1_jornada' => ['rotulo' => '1ª JORNADA', 'dia' => 1],
];

// Tipos que saíram de uso: só viram seção se houver chamada enviada no dia.
const LIVRO_SECOES_LEGADAS = [
    'educacao_fisica' => ['rotulo' => 'EDUCAÇÃO FÍSICA', 'dia' => 0],
];

// Formato "19 AGO 2026" do documento de referência — sem depender de locale
// instalado no servidor (evita quebrar se o pt_BR.utf8 não existir no PHP).
function dataEstiloLivro($dataIso) {
    // MAIO não é abreviado pra MAI — vai por extenso mesmo (regra do padrão militar).
    $meses = ['01' => 'JAN', '02' => 'FEV', '03' => 'MAR', '04' => 'ABR', '05' => 'MAIO', '06' => 'JUN',
              '07' => 'JUL', '08' => 'AGO', '09' => 'SET', '10' => 'OUT', '11' => 'NOV', '12' => 'DEZ'];
    [$ano, $mes, $dia] = explode('-', $dataIso);
    return "$dia {$meses[$mes]} $ano";
}

// "Esquadrão Prata" tanto se o cadastro guarda "Prata" quanto "Esquadrão Prata".
function rotuloEsquadrao($esquadrao) {
    return preg_match('/^esquadr[ãa]o\b/iu', $esquadrao) ? $esquadrao : "Esquadrão $esquadrao";
}

/**
 * Situação do aluno por extenso, como vai escrita no livro: o nome do motivo
 * ("Dispensa médica (atrás da tropa)"), nunca a sigla ("DMED"); o posto,
 * quando o motivo é serviço; e a observação, quando houver.
 */
function descricaoSituacaoLivro($item) {
    $partes = [trim((string) ($item['motivo_nome'] ?? '')) ?: 'Sem motivo informado'];
    foreach (['servico_nome', 'observacao'] as $campo) {
        $valor = trim((string) ($item[$campo] ?? ''));
        if ($valor !== '') {
            $partes[] = $valor;
        }
    }
    return implode(' — ', $partes);
}

/**
 * Monta o livro de um esquadrão pra um dia de serviço: por seção, as ausências
 * de todas as chamadas enviadas daquele tipo (ex: todas as esquadrilhas do
 * pernoite) e quantas chamadas foram enviadas — pra distinguir "não há
 * alteração" de "a chamada ainda não foi enviada" — mais as dispensas médicas
 * ativas na data.
 */
function montarLivroEsquadrao($conexao, $esquadrao, $data) {
    $secoes = [];
    foreach (LIVRO_SECOES + LIVRO_SECOES_LEGADAS as $tipo => $secao) {
        $dataSecao = date('Y-m-d', strtotime("$data +{$secao['dia']} day"));
        $secoes[$tipo] = [
            'tipo' => $tipo,
            'rotulo' => $secao['dia'] > 0 ? "{$secao['rotulo']} — " . dataEstiloLivro($dataSecao) : $secao['rotulo'],
            'data' => $dataSecao,
            'chamadas_enviadas' => 0,
            'ausencias' => [],
        ];
    }

    $retiradas = array_merge(
        listarRetiradasDoDia($conexao, $esquadrao, $data),
        listarRetiradasDoDia($conexao, $esquadrao, date('Y-m-d', strtotime("$data +1 day")))
    );
    foreach ($retiradas as $r) {
        $tipo = $r['tipo'];
        if (!isset($secoes[$tipo]) || substr($r['data_hora'], 0, 10) !== $secoes[$tipo]['data']) {
            continue;
        }
        $secoes[$tipo]['chamadas_enviadas']++;
        foreach (listarItensRetirada($conexao, $r['id']) as $item) {
            if ((int) $item['presente'] === 0) {
                $secoes[$tipo]['ausencias'][] = $item;
            }
        }
    }

    foreach (array_keys(LIVRO_SECOES_LEGADAS) as $tipo) {
        if ($secoes[$tipo]['chamadas_enviadas'] === 0) {
            unset($secoes[$tipo]);
        }
    }

    return [
        'esquadrao' => $esquadrao,
        'secoes' => $secoes,
        'dispensas' => listarDispensas($conexao, ['esquadrao' => $esquadrao, 'ativas_em' => $data]),
    ];
}

function _livroDispensadoDe($dispensa) {
    return implode(', ', array_filter([$dispensa['tags_nomes'] ?? null, $dispensa['dispensado_de'] ?? null]));
}

/**
 * O livro em texto puro, no formato padrão — pronto pra colar numa mensagem
 * ou num e-mail pro Aluno de Dia ao CA.
 */
function livroComoTexto($livro, $data) {
    $linhas = [
        'COMANDO DA AERONÁUTICA',
        'ESCOLA DE ESPECIALISTAS DE AERONÁUTICA',
        'CORPO DE ALUNOS',
        mb_strtoupper(rotuloEsquadrao($livro['esquadrao'])) . ' — RESUMO DO DIA ' . dataEstiloLivro($data),
    ];

    foreach ($livro['secoes'] as $secao) {
        $linhas[] = '';
        $linhas[] = $secao['rotulo'];
        if ($secao['chamadas_enviadas'] === 0) {
            $linhas[] = 'Chamada não enviada.';
        } elseif (empty($secao['ausencias'])) {
            $linhas[] = 'Não há.';
        } else {
            foreach ($secao['ausencias'] as $item) {
                $linhas[] = '- ' . identificacaoAluno($item) . ' — ' . descricaoSituacaoLivro($item);
            }
        }
    }

    $linhas[] = '';
    $linhas[] = 'OCORRÊNCIAS MÉDICAS — DISPENSA MÉDICA';
    if (empty($livro['dispensas'])) {
        $linhas[] = 'Não há.';
    }
    foreach ($livro['dispensas'] as $d) {
        $linhas[] = '- ' . identificacaoAluno($d);
        $linhas[] = '  INÍCIO: ' . dataEstiloLivro($d['data_inicio']);
        $linhas[] = '  TÉRMINO: ' . dataEstiloLivro($d['data_termino']);
        if (!empty($d['numero'])) {
            $linhas[] = '  Nº DA DISPENSA: ' . $d['numero'];
        }
        $linhas[] = '  MOTIVO: ' . $d['motivo'];
        if (!empty($d['medico_responsavel'])) {
            $linhas[] = '  OFICIAL MÉDICO: ' . $d['medico_responsavel'];
        }
        if (_livroDispensadoDe($d) !== '') {
            $linhas[] = '  DISPENSADO DE: ' . _livroDispensadoDe($d);
        }
    }

    $linhas[] = '';
    $linhas[] = 'Gerado pelo Argos.';

    return implode("\n", $linhas);
}

/**
 * O livro no formato que a API devolve: as mesmas seções, já com a
 * identificação e a situação de cada aluno por extenso, mais o texto pronto.
 */
function livroParaApi($livro, $data) {
    $secoes = [];
    foreach ($livro['secoes'] as $secao) {
        $secoes[] = [
            'tipo' => $secao['tipo'],
            'rotulo' => $secao['rotulo'],
            'data' => $secao['data'],
            'chamadas_enviadas' => $secao['chamadas_enviadas'],
            'ausencias' => array_map(fn($item) => [
                'aluno_id' => (int) $item['aluno_id'],
                'identificacao' => identificacaoAluno($item),
                'situacao' => descricaoSituacaoLivro($item),
            ], $secao['ausencias']),
        ];
    }

    return [
        'data' => $data,
        'data_extenso' => dataEstiloLivro($data),
        'esquadrao' => $livro['esquadrao'],
        'secoes' => $secoes,
        'dispensas' => array_map(fn($d) => [
            'id' => (int) $d['id'],
            'identificacao' => identificacaoAluno($d),
            'data_inicio' => $d['data_inicio'],
            'data_termino' => $d['data_termino'],
            'numero' => $d['numero'],
            'motivo' => $d['motivo'],
            'medico_responsavel' => $d['medico_responsavel'] ?? null,
            'dispensado_de' => _livroDispensadoDe($d),
        ], $livro['dispensas']),
        'texto' => livroComoTexto($livro, $data),
    ];
}
