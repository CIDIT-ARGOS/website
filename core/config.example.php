<?php
// Copie este arquivo para "config.php" e preencha com as credenciais reais do seu banco.
// config.php é ignorado pelo git (.gitignore) — nunca commite credenciais reais.

$DB_HOST = "localhost";
$DB_NOME = "";
$DB_USUARIO = "";
$DB_SENHA = "";

function conectarBanco() {
    global $DB_HOST, $DB_NOME, $DB_USUARIO, $DB_SENHA;

    $conexao = mysqli_connect($DB_HOST, $DB_USUARIO, $DB_SENHA, $DB_NOME);

    if (!$conexao) {
        die("Falha na conexão com o banco: " . mysqli_connect_error());
    }

    mysqli_set_charset($conexao, "utf8mb4");

    return $conexao;
}

// Verifica se um cargo/nível, num dos dois sistemas de login (ikarus37 ou painel),
// tem determinada permissão. Resultado cacheado por requisição.
function temPermissao($conexao, $sistema, $cargo, $chave) {
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

    return in_array($chave, $cache[$chaveCache]);
}
