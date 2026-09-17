<?php
// Credenciais do MySQL do docker-compose local — nunca aponta pra produção.
// Este arquivo é montado por cima de config.php só dentro do container web
// (veja docker-compose.yml), sem tocar no config.php real do host.

$DB_HOST = "db";
$DB_NOME = "argos";
$DB_USUARIO = "argos";
$DB_SENHA = "argos";

function conectarBanco() {
    global $DB_HOST, $DB_NOME, $DB_USUARIO, $DB_SENHA;

    // Desliga o modo de exceção do mysqli (padrão desde PHP 8.1): sem isso, um
    // erro de banco (ex: chave duplicada) lança mysqli_sql_exception não tratada
    // e derruba a página com um erro cru, em vez de deixar o código verificar
    // o retorno e mostrar uma mensagem amigável.
    mysqli_report(MYSQLI_REPORT_OFF);

    $conexao = mysqli_connect($DB_HOST, $DB_USUARIO, $DB_SENHA, $DB_NOME);

    if (!$conexao) {
        die("Falha na conexão com o banco: " . mysqli_connect_error());
    }

    mysqli_set_charset($conexao, "utf8mb4");

    return $conexao;
}

// Verifica se um cargo/nível, num dos dois sistemas de login (ikarus37 ou painel),
// tem determinada permissão. Resultado cacheado por requisição.
//
// $usuarioId (opcional, só existe pra 'painel'): quando informado, a checagem
// também considera os grupos_acesso de que esse painel_usuario faz parte — uma
// SEGUNDA fonte de permissão, somada por OR à do cargo, igual o UNIX combina
// permissão do dono do arquivo com permissão de grupo. $unidadeId (opcional)
// restringe a checagem via grupo a uma unidade organizacional específica; sem
// ele, vale qualquer concessão do grupo que não tenha unidade fixada.
function temPermissao($conexao, $sistema, $cargo, $chave, $usuarioId = null, $unidadeId = null) {
    static $cache = [];
    $chaveCache = "$sistema:$cargo";

    if (!isset($cache[$chaveCache])) {
        $stmt = mysqli_prepare($conexao, "
            SELECT p.chave
            FROM cargo_permissoes cp
            JOIN permissoes p ON p.id = cp.permissao_id
            WHERE cp.sistema = ? AND cp.cargo = ?
        ");
        mysqli_stmt_bind_param($stmt, "ss", $sistema, $cargo);
        mysqli_stmt_execute($stmt);
        $resultado = mysqli_stmt_get_result($stmt);

        $permissoes = [];
        while ($linha = mysqli_fetch_assoc($resultado)) {
            $permissoes[] = $linha['chave'];
        }
        $cache[$chaveCache] = $permissoes;
    }

    if (in_array($chave, $cache[$chaveCache])) {
        return true;
    }

    if ($sistema === 'painel' && $usuarioId !== null && is_numeric($usuarioId)) {
        return _temPermissaoViaGrupoAcesso($conexao, (int) $usuarioId, $chave, $unidadeId);
    }

    return false;
}

function _temPermissaoViaGrupoAcesso($conexao, $usuarioId, $chave, $unidadeId = null) {
    static $cache = [];
    $chaveCache = "$usuarioId:" . ($unidadeId ?? '');

    if (!isset($cache[$chaveCache])) {
        $stmt = mysqli_prepare($conexao, "
            SELECT DISTINCT p.chave
            FROM grupo_acesso_membros gm
            JOIN grupo_acesso_permissoes gp ON gp.grupo_acesso_id = gm.grupo_acesso_id
            JOIN permissoes p ON p.id = gp.permissao_id
            JOIN grupos_acesso g ON g.id = gm.grupo_acesso_id AND g.ativo = 1
            WHERE gm.painel_usuario_id = ?
              AND (gp.unidade_id IS NULL OR gp.unidade_id = ?)
              AND (gm.unidade_id IS NULL OR gp.unidade_id IS NULL OR gm.unidade_id = gp.unidade_id)
        ");
        mysqli_stmt_bind_param($stmt, "ii", $usuarioId, $unidadeId);
        mysqli_stmt_execute($stmt);
        $resultado = mysqli_stmt_get_result($stmt);

        $permissoes = [];
        while ($linha = mysqli_fetch_assoc($resultado)) {
            $permissoes[] = $linha['chave'];
        }
        $cache[$chaveCache] = $permissoes;
    }

    return in_array($chave, $cache[$chaveCache]);
}
