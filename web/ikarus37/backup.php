<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/acesso_negado.php';
require_once __DIR__ . '/auth.php';

$conexao = conectarBanco();

if (!temPermissao($conexao, 'ikarus37', $_SESSION['admin_nivel'], 'acesso_tecnico_avancado')) {
    exibirAcessoNegado("Sua conta não tem a permissão 'acesso_tecnico_avancado'.");
}

// ---------- Gerar e baixar o backup ----------
if (isset($_GET['baixar'])) {
    $nomeArquivo = "backup_argos_" . date('Y-m-d_His') . ".sql";

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');

    echo "-- Backup ARGOS gerado em " . date('Y-m-d H:i:s') . "\n";
    echo "-- Banco: " . DB_NOME_PARA_EXIBIR($conexao) . "\n\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    $tabelas = [];
    $resultTabelas = mysqli_query($conexao, "SHOW TABLES");
    while ($linha = mysqli_fetch_array($resultTabelas)) {
        $tabelas[] = $linha[0];
    }

    foreach ($tabelas as $tabela) {
        // ---------- Estrutura ----------
        $criacao = mysqli_fetch_assoc(mysqli_query($conexao, "SHOW CREATE TABLE `$tabela`"));
        $sqlCriacao = $criacao['Create Table'];

        echo "-- ----------------------------\n";
        echo "-- Tabela: $tabela\n";
        echo "-- ----------------------------\n";
        echo "DROP TABLE IF EXISTS `$tabela`;\n";
        echo $sqlCriacao . ";\n\n";

        // ---------- Dados ----------
        $resultDados = mysqli_query($conexao, "SELECT * FROM `$tabela`");
        $totalLinhas = mysqli_num_rows($resultDados);

        if ($totalLinhas > 0) {
            $colunas = array_map(fn($c) => $c->name, mysqli_fetch_fields($resultDados));
            $colunasSql = implode(', ', array_map(fn($c) => "`$c`", $colunas));

            $linhasValores = [];
            while ($linha = mysqli_fetch_assoc($resultDados)) {
                $valores = array_map(function ($v) use ($conexao) {
                    if ($v === null) return 'NULL';
                    return "'" . mysqli_real_escape_string($conexao, $v) . "'";
                }, array_values($linha));
                $linhasValores[] = "(" . implode(', ', $valores) . ")";
            }

            // Insere em blocos de 200 linhas por comando, pra não estourar limite de tamanho de query
            foreach (array_chunk($linhasValores, 200) as $bloco) {
                echo "INSERT INTO `$tabela` ($colunasSql) VALUES\n" . implode(",\n", $bloco) . ";\n\n";
            }
        }
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    mysqli_close($conexao);
    exit;
}

function DB_NOME_PARA_EXIBIR($conexao) {
    $r = mysqli_fetch_row(mysqli_query($conexao, "SELECT DATABASE()"));
    return $r[0];
}

// ---------- Listar tamanho aproximado (só informativo) ----------
$tabelas = [];
$resultTabelas = mysqli_query($conexao, "SHOW TABLES");
while ($linha = mysqli_fetch_array($resultTabelas)) {
    $nomeTabela = $linha[0];
    $count = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT COUNT(*) as total FROM `$nomeTabela`"))['total'];
    $tabelas[] = ['nome' => $nomeTabela, 'total' => $count];
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Ikarus37 — Backup</title>
<style>
    :root { --bg: #0f1115; --bg-card: #171a21; --border: #2a2e37; --text: #e6e8eb; --text-muted: #9aa1ac; --accent: #4f8cff; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); }
    .topbar { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid var(--border); }
    .topbar a { color: var(--text-muted); text-decoration: none; font-size: 13px; margin-left: 16px; }
    .container { padding: 24px; max-width: 700px; margin: 0 auto; }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 6px 10px; text-align: left; }
    th { background: #1d212a; color: var(--text-muted); }
    .scroll-x { overflow-x: auto; min-width: 0; }
    a.btn { display: inline-block; padding: 10px 20px; background: var(--accent); color: #fff; text-decoration: none; border-radius: 6px; font-size: 14px; margin-top: 10px; }
    .i { display: inline-block; width: 13px; height: 13px; vertical-align: -2px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 4px; }
</style>
</head>
<body>

<div class="topbar">
    <div><strong>IKARUS37</strong> <a href="index.php"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span>painel</a></div>
    <div><a href="index.php?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a></div>
</div>

<div class="container">
    <h2>Backup do banco de dados</h2>
    <p style="color: var(--text-muted); font-size: 13px;">
        Gera um arquivo <code>.sql</code> completo (estrutura + dados de todas as tabelas), pronto pra restaurar depois se precisar.
        Baixe e guarde antes de rodar qualquer script destrutivo (como o <code>init_db.sql</code>).
    </p>

    <div class="card">
        <a href="?baixar=1" class="btn">⬇ Baixar backup completo (.sql)</a>
    </div>

    <div class="card">
        <h4>Tabelas atuais</h4>
        <div class="scroll-x">
        <table>
            <tr><th>Tabela</th><th>Registros</th></tr>
            <?php foreach ($tabelas as $t): ?>
                <tr><td><?= htmlspecialchars($t['nome']) ?></td><td><?= $t['total'] ?></td></tr>
            <?php endforeach; ?>
        </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../core/rodape_versao.php'; ?>
</body>
</html>
<?php mysqli_close($conexao); ?>
