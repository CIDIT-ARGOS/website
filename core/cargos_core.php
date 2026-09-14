<?php

// Catálogo de cargos (painel_usuarios.cargo e admin_usuarios.nivel), agora um
// objeto do domínio em vez de ENUM fixo — pra criar um cargo novo (ex: "Chefe
// de Galpão") não precisar mais de deploy, só de acesso ao Controle do Domínio
// de Negócio. `escopo` só é usado pelo sistema 'painel': 'ca' = cargo não exige
// esquadrão vinculado, 'esquadrao' = exige. No 'ikarus37' fica sempre NULL.

function listarCargos($conexao, $sistema = null, $incluirInativos = false) {
    $condicoes = [];
    $tipos = "";
    $valores = [];
    if ($sistema !== null) {
        $condicoes[] = "sistema = ?";
        $tipos .= "s";
        $valores[] = $sistema;
    }
    if (!$incluirInativos) {
        $condicoes[] = "ativo = 1";
    }
    $where = $condicoes ? "WHERE " . implode(" AND ", $condicoes) : "";

    if ($valores) {
        $stmt = mysqli_prepare($conexao, "SELECT * FROM cargos $where ORDER BY sistema, escopo, nome");
        mysqli_stmt_bind_param($stmt, $tipos, ...$valores);
        mysqli_stmt_execute($stmt);
        return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    }
    return mysqli_fetch_all(mysqli_query($conexao, "SELECT * FROM cargos $where ORDER BY sistema, escopo, nome"), MYSQLI_ASSOC);
}

function listarChavesCargos($conexao, $sistema, $escopo = null) {
    $cargos = listarCargos($conexao, $sistema);
    if ($escopo !== null) {
        $cargos = array_filter($cargos, function ($c) use ($escopo) {
            return $c['escopo'] === $escopo;
        });
    }
    return array_values(array_column($cargos, 'chave'));
}

function buscarCargoPorChave($conexao, $sistema, $chave) {
    $stmt = mysqli_prepare($conexao, "SELECT * FROM cargos WHERE sistema = ? AND chave = ?");
    mysqli_stmt_bind_param($stmt, "ss", $sistema, $chave);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

function cargoValido($conexao, $sistema, $chave) {
    return buscarCargoPorChave($conexao, $sistema, $chave) !== null;
}
