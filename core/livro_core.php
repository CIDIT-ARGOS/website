<?php

// Livro do Dia (Livro de Serviço do Aluno de Dia): o resumo padronizado de um
// esquadrão numa data, montado a partir das chamadas enviadas e das dispensas
// médicas — o que antes era digitado à mão e passado por WhatsApp/e-mail.
//
// Usado pelo Painel (web/painel/livro_do_dia.php) e pela API (/api/livro.php,
// que alimenta a Área Funcional). A fonte é uma só, então o livro que o aluno
// de serviço envia é igual ao que o comando vê.

require_once __DIR__ . '/retiradas_core.php';
require_once __DIR__ . '/dispensas_core.php';
require_once __DIR__ . '/alunos_core.php';

// Ordem das seções no livro.
const LIVRO_TIPOS_RETIRADA = [
    'pernoite' => 'PERNOITE',
    '1_jornada' => '1ª JORNADA',
    '2_jornada' => '2ª JORNADA',
    'educacao_fisica' => 'EDUCAÇÃO FÍSICA',
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
 * ("Dispensa médica (atrás da tropa)"), nunca a sigla ("DMED"), seguido da
 * observação quando houver.
 */
function descricaoSituacaoLivro($item) {
    $descricao = trim((string) ($item['motivo_nome'] ?? '')) ?: 'Sem motivo informado';
    $observacao = trim((string) ($item['observacao'] ?? ''));
    return $observacao !== '' ? "$descricao — $observacao" : $descricao;
}

/**
 * Monta os dados de um esquadrão pro Livro do Dia: por tipo de retirada, as
 * ausências de todas as chamadas enviadas naquele dia (ex: todas as
 * esquadrilhas da 1ª Jornada) e quantas chamadas foram enviadas — pra
 * distinguir "não há alteração" de "a chamada ainda não foi enviada" — mais
 * as dispensas médicas ativas na data.
 */
function montarLivroEsquadrao($conexao, $esquadrao, $data) {
    $porTipo = array_fill_keys(array_keys(LIVRO_TIPOS_RETIRADA), []);
    $chamadasEnviadas = array_fill_keys(array_keys(LIVRO_TIPOS_RETIRADA), 0);

    foreach (listarRetiradasDoDia($conexao, $esquadrao, $data) as $r) {
        if (!isset($porTipo[$r['tipo']])) {
            continue;
        }
        $chamadasEnviadas[$r['tipo']]++;
        foreach (listarItensRetirada($conexao, $r['id']) as $item) {
            if ((int) $item['presente'] === 0) {
                $porTipo[$r['tipo']][] = $item;
            }
        }
    }

    return [
        'esquadrao' => $esquadrao,
        'por_tipo' => $porTipo,
        'chamadas_enviadas' => $chamadasEnviadas,
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

    foreach (LIVRO_TIPOS_RETIRADA as $tipo => $rotulo) {
        $linhas[] = '';
        $linhas[] = $rotulo;
        if ($livro['chamadas_enviadas'][$tipo] === 0) {
            $linhas[] = 'Chamada não enviada.';
        } elseif (empty($livro['por_tipo'][$tipo])) {
            $linhas[] = 'Não há.';
        } else {
            foreach ($livro['por_tipo'][$tipo] as $item) {
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
    foreach (LIVRO_TIPOS_RETIRADA as $tipo => $rotulo) {
        $secoes[] = [
            'tipo' => $tipo,
            'rotulo' => $rotulo,
            'chamadas_enviadas' => $livro['chamadas_enviadas'][$tipo],
            'ausencias' => array_map(fn($item) => [
                'aluno_id' => (int) $item['aluno_id'],
                'identificacao' => identificacaoAluno($item),
                'situacao' => descricaoSituacaoLivro($item),
            ], $livro['por_tipo'][$tipo]),
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
            'dispensado_de' => _livroDispensadoDe($d),
        ], $livro['dispensas']),
        'texto' => livroComoTexto($livro, $data),
    ];
}
