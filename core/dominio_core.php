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

function dominioEntidades() {
    return [
        'unidades' => [
            'tabela' => 'unidades',
            'rotulo' => 'Unidades',
            'rotulo_singular' => 'Unidade',
            'permissao' => 'gerenciar_unidades',
            'ordem_por' => 'tipo, nome',
            'campos' => [
                ['nome' => 'nome', 'rotulo' => 'Nome', 'tipo' => 'texto', 'obrigatorio' => true],
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
            'campos' => [
                ['nome' => 'nome', 'rotulo' => 'Nome', 'tipo' => 'texto', 'obrigatorio' => true],
                ['nome' => 'descricao', 'rotulo' => 'Descrição', 'tipo' => 'texto'],
                ['nome' => 'ativo', 'rotulo' => 'Ativo', 'tipo' => 'checkbox'],
            ],
        ],
        'cargos' => [
            'tabela' => 'cargos',
            'rotulo' => 'Cargos',
            'rotulo_singular' => 'Cargo',
            'permissao' => 'gerenciar_cargos',
            'ordem_por' => 'sistema, escopo, nome',
            'campos' => [
                ['nome' => 'sistema', 'rotulo' => 'Sistema', 'tipo' => 'select', 'obrigatorio' => true, 'opcoes' => [
                    'painel' => 'Painel', 'ikarus37' => 'Ikarus37',
                ]],
                ['nome' => 'chave', 'rotulo' => 'Chave (usada no código — evite renomear)', 'tipo' => 'texto', 'obrigatorio' => true],
                ['nome' => 'nome', 'rotulo' => 'Nome de exibição', 'tipo' => 'texto', 'obrigatorio' => true],
                ['nome' => 'escopo', 'rotulo' => 'Escopo (só painel)', 'tipo' => 'select', 'opcoes' => [
                    '' => '— nenhum —', 'ca' => 'CA (não exige esquadrão)', 'esquadrao' => 'Esquadrão (exige esquadrão)',
                ]],
                ['nome' => 'ativo', 'rotulo' => 'Ativo', 'tipo' => 'checkbox'],
            ],
        ],
    ];
}

function dominioEntidade($chave) {
    return dominioEntidades()[$chave] ?? null;
}

function dominioListar($conexao, $entidade) {
    $tabela = $entidade['tabela'];
    $ordem = $entidade['ordem_por'];
    $resultado = mysqli_query($conexao, "SELECT * FROM `$tabela` ORDER BY $ordem");
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
        return ['ok' => false, 'erro' => 'Erro ao criar: ' . mysqli_error($conexao)];
    }
    return ['ok' => true, 'id' => mysqli_insert_id($conexao)];
}

function dominioAtualizar($conexao, $entidade, $id, $dados) {
    $erro = _dominioValidar($entidade, $dados);
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
        return ['ok' => false, 'erro' => 'Erro ao atualizar: ' . mysqli_error($conexao)];
    }
    return ['ok' => true];
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
        return ['ok' => false, 'erro' => 'Erro ao excluir: ' . mysqli_error($conexao)];
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
        default:
            $valor = trim((string) ($valorBruto ?? ''));
            return [$valor === '' ? null : $valor, "s"];
    }
}
