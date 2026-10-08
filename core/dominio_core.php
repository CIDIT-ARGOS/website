<?php

// Controle do Domínio de Negócio: motor genérico de CRUD dirigido por
// configuração, pra não precisar escrever uma tela nova toda vez que surge um
// objeto do domínio pra administrar (unidades, grupos de acesso, e futuramente
// motivos de falta, postos de graduação etc.) — objetivo explícito é reduzir a
// dependência do console SQL cru do Ikarus37, que fica só pra emergência técnica.
//
// Cada entidade registrada aqui descreve sua própria tabela e campos; as funções
// genéricas abaixo montam prepared statements a partir dessa config — nunca a
// partir de nome de coluna vindo de fora (só o array fixo, escrito em código).

require_once __DIR__ . '/unidades_core.php';
require_once __DIR__ . '/validacao_core.php';

function dominioEntidades() {
    return [
        'unidades' => [
            'tabela' => 'unidades',
            'rotulo' => 'Unidades',
            'rotulo_singular' => 'Unidade',
            'permissao' => 'gerenciar_unidades',
            'ordem_por' => 'tipo, nome',
            'campos' => [
                ['nome' => 'nome', 'rotulo' => 'Nome', 'tipo' => 'texto', 'obrigatorio' => true, 'max' => 100],
                ['nome' => 'tipo', 'rotulo' => 'Tipo', 'tipo' => 'select', 'obrigatorio' => true, 'opcoes' => [
                    'escola' => 'Escola', 'divisao' => 'Divisão', 'galpao' => 'Galpão',
                    'ca' => 'Corpo de Alunos', 'esquadrao' => 'Esquadrão', 'doutrina' => 'Doutrina',
                ]],
                ['nome' => 'unidade_pai_id', 'rotulo' => 'Unidade pai', 'tipo' => 'unidade_pai'],
                ['nome' => 'ativo', 'rotulo' => 'Ativo', 'tipo' => 'checkbox'],
            ],
        ],
        'grupos_acesso' => [
            'tabela' => 'grupos_acesso',
            'rotulo' => 'Grupos de acesso',
            'rotulo_singular' => 'Grupo de acesso',
            'permissao' => 'gerenciar_grupos_acesso',
            'ordem_por' => 'nome',
            'unicos' => [['nome']],
            'campos' => [
                ['nome' => 'nome', 'rotulo' => 'Nome', 'tipo' => 'texto', 'obrigatorio' => true, 'max' => 100],
                ['nome' => 'descricao', 'rotulo' => 'Descrição', 'tipo' => 'texto', 'max' => 255],
                ['nome' => 'ativo', 'rotulo' => 'Ativo', 'tipo' => 'checkbox'],
            ],
        ],
        'cargos' => [
            'tabela' => 'cargos',
            'rotulo' => 'Cargos',
            'rotulo_singular' => 'Cargo',
            'permissao' => 'gerenciar_cargos',
            'ordem_por' => 'sistema, escopo, nome',
            'unicos' => [['sistema', 'chave']],
            'campos' => [
                ['nome' => 'sistema', 'rotulo' => 'Sistema', 'tipo' => 'select', 'obrigatorio' => true, 'opcoes' => [
                    'painel' => 'Painel', 'ikarus37' => 'Ikarus37',
                ]],
                ['nome' => 'chave', 'rotulo' => 'Chave (usada no código — evite renomear)', 'tipo' => 'texto', 'obrigatorio' => true, 'max' => 30],
                ['nome' => 'nome', 'rotulo' => 'Nome de exibição', 'tipo' => 'texto', 'obrigatorio' => true, 'max' => 100],
                ['nome' => 'escopo', 'rotulo' => 'Escopo (só painel)', 'tipo' => 'select', 'opcoes' => [
                    '' => '— nenhum —', 'ca' => 'CA (não exige esquadrão)', 'esquadrao' => 'Esquadrão (exige esquadrão)',
                ]],
                ['nome' => 'ativo', 'rotulo' => 'Ativo', 'tipo' => 'checkbox'],
            ],
        ],
        'motivos_falta' => [
            'tabela' => 'motivos_falta',
            'rotulo' => 'Motivos de falta',
            'rotulo_singular' => 'Motivo',
            'permissao' => 'gerenciar_motivos',
            'ordem_por' => 'ordem',
            'unicos' => [['nome'], ['ordem']],
            'campos' => [
                ['nome' => 'codigo', 'rotulo' => 'Código', 'tipo' => 'texto', 'obrigatorio' => true, 'max' => 10],
                ['nome' => 'nome', 'rotulo' => 'Nome de exibição', 'tipo' => 'texto', 'obrigatorio' => true, 'max' => 150],
                ['nome' => 'classificacao', 'rotulo' => 'Classificação', 'tipo' => 'select', 'obrigatorio' => true, 'opcoes' => [
                    'presente' => 'Presente',
                    'ausente_nao_falta' => 'Ausente (não é falta)',
                    'falta' => 'Falta',
                ]],
                ['nome' => 'requer_observacao', 'rotulo' => 'Exige observação', 'tipo' => 'checkbox'],
                ['nome' => 'ordem', 'rotulo' => 'Ordem', 'tipo' => 'numero'],
                ['nome' => 'ativo', 'rotulo' => 'Ativo', 'tipo' => 'checkbox'],
            ],
        ],
        'dispensa_tipos' => [
            'tabela' => 'dispensa_tipos',
            'rotulo' => 'Tipos de dispensa',
            'rotulo_singular' => 'Tipo de dispensa',
            'permissao' => 'gerenciar_motivos',
            'ordem_por' => 'ordem',
            'unicos' => [['nome'], ['ordem']],
            'campos' => [
                ['nome' => 'nome', 'rotulo' => 'Nome', 'tipo' => 'texto', 'obrigatorio' => true, 'max' => 50],
                ['nome' => 'ordem', 'rotulo' => 'Ordem', 'tipo' => 'numero'],
                ['nome' => 'ativo', 'rotulo' => 'Ativo', 'tipo' => 'checkbox'],
            ],
        ],
    ];
}

function dominioEntidade($chave) {
    return dominioEntidades()[$chave] ?? null;
}

function _dominioTemCampoAtivo($entidade) {
    foreach ($entidade['campos'] as $campo) {
        if ($campo['nome'] === 'ativo') {
            return true;
        }
    }
    return false;
}

/**
 * Por padrão só lista os registros ativos — "excluir" aqui é soft delete
 * (preserva o que já referencia o registro), então sem esse filtro o item
 * "excluído" continuava aparecendo na lista igual antes, e parecia que a
 * exclusão não tinha feito nada. $incluirInativos = true pra revisar/
 * reativar o que foi desativado.
 */
function dominioListar($conexao, $entidade, $incluirInativos = false) {
    $tabela = $entidade['tabela'];
    $ordem = $entidade['ordem_por'];
    $where = (!$incluirInativos && _dominioTemCampoAtivo($entidade)) ? "WHERE ativo = 1" : "";
    $resultado = mysqli_query($conexao, "SELECT * FROM `$tabela` $where ORDER BY $ordem");
    return mysqli_fetch_all($resultado, MYSQLI_ASSOC);
}

function dominioBuscarPorId($conexao, $entidade, $id) {
    $tabela = $entidade['tabela'];
    $stmt = mysqli_prepare($conexao, "SELECT * FROM `$tabela` WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

function _dominioValidar($entidade, $dados) {
    foreach ($entidade['campos'] as $campo) {
        if (!empty($campo['obrigatorio']) && trim($dados[$campo['nome']] ?? '') === '') {
            return "Campo obrigatório: {$campo['rotulo']}";
        }
        if ($campo['tipo'] === 'select' && isset($dados[$campo['nome']]) && $dados[$campo['nome']] !== '') {
            if (!array_key_exists($dados[$campo['nome']], $campo['opcoes'])) {
                return "Valor inválido para {$campo['rotulo']}.";
            }
        }
        if (isset($campo['max']) && isset($dados[$campo['nome']]) && mb_strlen((string) $dados[$campo['nome']]) > $campo['max']) {
            return "{$campo['rotulo']} excede o tamanho máximo permitido ({$campo['max']} caracteres).";
        }
    }
    return null;
}

/**
 * Checa, antes de gravar, se algum grupo de colunas marcado como único em
 * 'unicos' já existe em outro registro — evita depender do erro cru do banco
 * (mysqli_error) pra avisar o usuário sobre chave/nome/ordem duplicados.
 * $idAtual: id do próprio registro, pra ignorá-lo numa atualização.
 */
function _dominioValidarUnicos($conexao, $entidade, $dados, $idAtual = null) {
    if (empty($entidade['unicos'])) {
        return null;
    }

    $rotulosPorCampo = [];
    foreach ($entidade['campos'] as $campo) {
        $rotulosPorCampo[$campo['nome']] = $campo['rotulo'];
    }

    $tabela = $entidade['tabela'];
    foreach ($entidade['unicos'] as $colunas) {
        $condicoes = [];
        $valores = [];
        $tipos = "";
        foreach ($colunas as $coluna) {
            $condicoes[] = "`$coluna` = ?";
            $valores[] = trim((string) ($dados[$coluna] ?? ''));
            $tipos .= "s";
        }

        $sql = "SELECT id FROM `$tabela` WHERE " . implode(" AND ", $condicoes);
        if ($idAtual !== null) {
            $sql .= " AND id != ?";
            $valores[] = $idAtual;
            $tipos .= "i";
        }
        $sql .= " LIMIT 1";

        $stmt = mysqli_prepare($conexao, $sql);
        mysqli_stmt_bind_param($stmt, $tipos, ...$valores);
        mysqli_stmt_execute($stmt);
        $existe = mysqli_stmt_get_result($stmt)->fetch_row();

        if ($existe) {
            $rotulos = implode(' + ', array_map(fn($c) => $rotulosPorCampo[$c] ?? $c, $colunas));
            return "Já existe {$entidade['rotulo_singular']} com esse(a) {$rotulos}. Escolha outro valor.";
        }
    }
    return null;
}

/**
 * @return array{ok: bool, erro?: string, id?: int}
 */
function dominioCriar($conexao, $entidade, $dados) {
    $erro = _dominioValidar($entidade, $dados);
    if ($erro) {
        return ['ok' => false, 'erro' => $erro];
    }
    $erro = _dominioValidarUnicos($conexao, $entidade, $dados);
    if ($erro) {
        return ['ok' => false, 'erro' => $erro];
    }

    $colunas = [];
    $marcadores = [];
    $valores = [];
    $tipos = "";

    foreach ($entidade['campos'] as $campo) {
        $colunas[] = "`{$campo['nome']}`";
        $marcadores[] = "?";
        [$valor, $tipo] = _dominioValorETipo($campo, $dados[$campo['nome']] ?? null);
        $valores[] = $valor;
        $tipos .= $tipo;
    }

    $tabela = $entidade['tabela'];
    $sql = "INSERT INTO `$tabela` (" . implode(", ", $colunas) . ") VALUES (" . implode(", ", $marcadores) . ")";
    $stmt = mysqli_prepare($conexao, $sql);
    mysqli_stmt_bind_param($stmt, $tipos, ...$valores);

    if (!mysqli_stmt_execute($stmt)) {
        return ['ok' => false, 'erro' => _dominioErroAmigavel($conexao, $entidade)];
    }
    return ['ok' => true, 'id' => mysqli_insert_id($conexao)];
}

function dominioAtualizar($conexao, $entidade, $id, $dados) {
    $erro = _dominioValidar($entidade, $dados);
    if ($erro) {
        return ['ok' => false, 'erro' => $erro];
    }
    $erro = _dominioValidarUnicos($conexao, $entidade, $dados, $id);
    if ($erro) {
        return ['ok' => false, 'erro' => $erro];
    }

    $sets = [];
    $valores = [];
    $tipos = "";

    foreach ($entidade['campos'] as $campo) {
        $sets[] = "`{$campo['nome']}` = ?";
        [$valor, $tipo] = _dominioValorETipo($campo, $dados[$campo['nome']] ?? null);
        $valores[] = $valor;
        $tipos .= $tipo;
    }
    $valores[] = $id;
    $tipos .= "i";

    $tabela = $entidade['tabela'];
    $sql = "UPDATE `$tabela` SET " . implode(", ", $sets) . " WHERE id = ?";
    $stmt = mysqli_prepare($conexao, $sql);
    mysqli_stmt_bind_param($stmt, $tipos, ...$valores);

    if (!mysqli_stmt_execute($stmt)) {
        return ['ok' => false, 'erro' => _dominioErroAmigavel($conexao, $entidade)];
    }
    return ['ok' => true];
}

/**
 * Traduz o erro cru do mysqli pra uma mensagem amigável — rede de segurança
 * pra qualquer restrição do banco (ex: UNIQUE) que não esteja coberta por
 * 'unicos' na config da entidade. Erro 1062 = "Duplicate entry".
 */
function _dominioErroAmigavel($conexao, $entidade) {
    if (mysqli_errno($conexao) === 1062) {
        return "Já existe {$entidade['rotulo_singular']} com esses dados. Verifique os campos que precisam ser únicos (nome, chave, ordem etc.).";
    }
    return "Não foi possível salvar {$entidade['rotulo_singular']}. Tente novamente ou avise o suporte técnico.";
}

function dominioExcluir($conexao, $entidade, $id) {
    $tabela = $entidade['tabela'];

    // Soft delete quando a tabela tem coluna `ativo`; hard delete caso contrário.
    $temAtivo = false;
    foreach ($entidade['campos'] as $campo) {
        if ($campo['nome'] === 'ativo') {
            $temAtivo = true;
            break;
        }
    }

    if ($temAtivo) {
        $stmt = mysqli_prepare($conexao, "UPDATE `$tabela` SET ativo = 0 WHERE id = ?");
    } else {
        $stmt = mysqli_prepare($conexao, "DELETE FROM `$tabela` WHERE id = ?");
    }
    mysqli_stmt_bind_param($stmt, "i", $id);

    if (!mysqli_stmt_execute($stmt)) {
        return ['ok' => false, 'erro' => "Não foi possível excluir {$entidade['rotulo_singular']}: ele ainda está em uso por outro registro do sistema."];
    }
    return ['ok' => true];
}

function _dominioValorETipo($campo, $valorBruto) {
    switch ($campo['tipo']) {
        case 'checkbox':
            return [!empty($valorBruto) ? 1 : 0, "i"];
        case 'unidade_pai':
            $valor = ($valorBruto === '' || $valorBruto === null) ? null : (int) $valorBruto;
            return [$valor, "i"];
        case 'numero':
            $valor = ($valorBruto === '' || $valorBruto === null) ? 0 : (int) $valorBruto;
            return [$valor, "i"];
        default:
            $valor = trim((string) ($valorBruto ?? ''));
            return [$valor === '' ? null : $valor, "s"];
    }
}
