<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';

$conexao = conectarBanco();

// ---------- Executar SQL livre ----------
$sql = isset($_POST['sql']) ? trim($_POST['sql']) : '';
$mensagem = null;
$erro = null;
$resultados = [];

if ($sql !== '') {
    if (mysqli_multi_query($conexao, $sql)) {
        do {
            $resultadoAtual = mysqli_store_result($conexao);

            if ($resultadoAtual instanceof mysqli_result) {
                $linhas = [];
                while ($linha = mysqli_fetch_assoc($resultadoAtual)) {
                    $linhas[] = $linha;
                }
                $resultados[] = ['tipo' => 'select', 'linhas' => $linhas];
                mysqli_free_result($resultadoAtual);
            } else if (mysqli_field_count($conexao) === 0) {
                $resultados[] = ['tipo' => 'exec', 'linhasAfetadas' => mysqli_affected_rows($conexao)];
            }

            $temMais = mysqli_more_results($conexao) && mysqli_next_result($conexao);
        } while ($temMais);

        if (mysqli_errno($conexao)) {
            $erro = "Erro durante a execução: " . mysqli_error($conexao);
        } else {
            $mensagem = "Executado com sucesso.";
        }
    } else {
        $erro = "Erro: " . mysqli_error($conexao);
    }
}

// ---------- Listar tabelas com contagem ----------
$tabelas = [];
$resultTabelas = mysqli_query($conexao, "SHOW TABLES");
if ($resultTabelas) {
    while ($linha = mysqli_fetch_array($resultTabelas)) {
        $nomeTabela = $linha[0];
        $count = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT COUNT(*) as total FROM `$nomeTabela`"))['total'];
        $tabelas[] = ['nome' => $nomeTabela, 'total' => $count];
    }
}

// ---------- Ver estrutura/dados de uma tabela específica ----------
$tabelaSelecionada = $_GET['tabela'] ?? null;
$estrutura = [];
$dados = [];
$colunas = [];
$chavePrimaria = null;

if ($tabelaSelecionada && in_array($tabelaSelecionada, array_column($tabelas, 'nome'))) {
    $resultEstrutura = mysqli_query($conexao, "SHOW COLUMNS FROM `$tabelaSelecionada`");
    while ($coluna = mysqli_fetch_assoc($resultEstrutura)) {
        $estrutura[] = $coluna;
        if ($coluna['Key'] === 'PRI' && !$chavePrimaria) {
            $chavePrimaria = $coluna['Field'];
        }
    }

    // ---------- Excluir registro ----------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir_registro' && $chavePrimaria) {
        $valorId = $_POST['registro_id'];
        $stmt = mysqli_prepare($conexao, "DELETE FROM `$tabelaSelecionada` WHERE `$chavePrimaria` = ?");
        mysqli_stmt_bind_param($stmt, "s", $valorId);
        if (mysqli_stmt_execute($stmt)) {
            $mensagem = "Registro excluído.";
        } else {
            $erro = "Erro ao excluir: " . mysqli_stmt_error($stmt);
        }
    }

    $resultDados = mysqli_query($conexao, "SELECT * FROM `$tabelaSelecionada` LIMIT 50");
    if ($resultDados) {
        $colunas = array_map(fn($c) => $c->name, mysqli_fetch_fields($resultDados));
        while ($linha = mysqli_fetch_assoc($resultDados)) {
            $dados[] = $linha;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Ikarus37 — Banco de Dados</title>
<style>
    :root {
        --bg: #0f1115; --bg-card: #171a21; --border: #2a2e37;
        --text: #e6e8eb; --text-muted: #9aa1ac; --accent: #4f8cff; --danger: #ff5f5f; --ok: #4caf7d;
    }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); overflow-x: hidden; }
    .topbar { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid var(--border); }
    .topbar .brand { font-weight: 600; }
    .topbar a { color: var(--text-muted); text-decoration: none; font-size: 13px; margin-left: 16px; }
    .topbar a:hover { color: var(--text); }
    .container { padding: 24px; max-width: 1200px; margin: 0 auto; }
    .layout { display: grid; grid-template-columns: 220px minmax(0, 1fr); gap: 20px; align-items: start; }
    .sidebar { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 12px; }
    .sidebar a { display: flex; justify-content: space-between; padding: 8px 10px; border-radius: 6px; color: var(--text); text-decoration: none; font-size: 13px; }
    .sidebar a:hover, .sidebar a.active { background: #21252f; }
    .sidebar small { color: var(--text-muted); }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
    textarea { width: 100%; height: 120px; font-family: monospace; font-size: 13px; padding: 10px; background: #0f1115; color: #cde8ff; border: 1px solid var(--border); border-radius: 6px; }
    button { padding: 9px 18px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; margin-top: 10px; }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 6px 10px; text-align: left; }
    th { background: #1d212a; color: var(--text-muted); }
    .erro { color: var(--danger); font-size: 13px; }
    .ok { color: var(--ok); font-size: 13px; }
    h2, h4 { margin-top: 0; }
    .scroll-x { overflow-x: auto; }
    .link-btn { color: var(--accent); text-decoration: none; font-size: 12px; margin-left: 10px; }
    .link-btn.danger-link { color: var(--danger); }
</style>
</head>
<body>

<div class="topbar">
    <div class="brand">IKARUS37 <a href="index.php">← painel</a></div>
    <div><a href="index.php?logout=1">sair</a></div>
</div>

<div class="container">
    <h2>Banco de Dados</h2>

    <div class="layout">
        <div class="sidebar">
            <?php foreach ($tabelas as $t): ?>
                <a href="?tabela=<?= urlencode($t['nome']) ?>" class="<?= $t['nome'] === $tabelaSelecionada ? 'active' : '' ?>">
                    <?= htmlspecialchars($t['nome']) ?> <small><?= $t['total'] ?></small>
                </a>
            <?php endforeach; ?>
            <?php if (empty($tabelas)): ?>
                <p style="color: var(--text-muted); font-size: 13px;">Nenhuma tabela ainda.</p>
            <?php endif; ?>
        </div>

        <div>
            <div class="card">
                <h4>Executar SQL</h4>
                <form method="post">
                    <textarea name="sql" placeholder="SELECT * FROM admin_usuarios; ..."><?= htmlspecialchars($sql) ?></textarea>
                    <button type="submit">Executar</button>
                </form>
                <?php if ($erro): ?><p class="erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
                <?php if ($mensagem): ?><p class="ok"><?= htmlspecialchars($mensagem) ?></p><?php endif; ?>

                <?php foreach ($resultados as $i => $res): ?>
                    <?php if ($res['tipo'] === 'exec'): ?>
                        <p>Comando <?= $i + 1 ?>: <?= $res['linhasAfetadas'] ?> linha(s) afetada(s).</p>
                    <?php elseif ($res['tipo'] === 'select' && count($res['linhas']) > 0): ?>
                        <div class="scroll-x">
                        <table>
                            <tr><?php foreach (array_keys($res['linhas'][0]) as $c): ?><th><?= htmlspecialchars($c) ?></th><?php endforeach; ?></tr>
                            <?php foreach ($res['linhas'] as $linha): ?>
                                <tr><?php foreach ($linha as $v): ?><td><?= htmlspecialchars((string)$v) ?></td><?php endforeach; ?></tr>
                            <?php endforeach; ?>
                        </table>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <?php if ($tabelaSelecionada && !empty($estrutura)): ?>
                <div class="card">
                    <h4>Estrutura — <?= htmlspecialchars($tabelaSelecionada) ?></h4>
                    <div class="scroll-x">
                    <table>
                        <tr><th>Campo</th><th>Tipo</th><th>Nulo</th><th>Chave</th><th>Padrão</th><th>Extra</th></tr>
                        <?php foreach ($estrutura as $c): ?>
                            <tr>
                                <td><?= htmlspecialchars($c['Field']) ?></td>
                                <td><?= htmlspecialchars($c['Type']) ?></td>
                                <td><?= htmlspecialchars($c['Null']) ?></td>
                                <td><?= htmlspecialchars($c['Key']) ?></td>
                                <td><?= htmlspecialchars((string)$c['Default']) ?></td>
                                <td><?= htmlspecialchars($c['Extra']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                    </div>
                </div>

                <div class="card">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <h4>Dados (até 50 linhas)</h4>
                        <div>
                            <a href="exportar.php?tabela=<?= urlencode($tabelaSelecionada) ?>" class="link-btn">Exportar CSV</a>
                            <a href="importar.php?tabela=<?= urlencode($tabelaSelecionada) ?>" class="link-btn">Importar CSV</a>
                        </div>
                    </div>
                    <div class="scroll-x">
                    <table>
                        <tr>
                            <?php foreach ($colunas as $c): ?><th><?= htmlspecialchars($c) ?></th><?php endforeach; ?>
                            <?php if ($chavePrimaria): ?><th>Ações</th><?php endif; ?>
                        </tr>
                        <?php foreach ($dados as $linha): ?>
                            <tr>
                                <?php foreach ($linha as $v): ?><td><?= htmlspecialchars((string)$v) ?></td><?php endforeach; ?>
                                <?php if ($chavePrimaria): ?>
                                <td style="white-space:nowrap;">
                                    <a href="registro_editar.php?tabela=<?= urlencode($tabelaSelecionada) ?>&id=<?= urlencode($linha[$chavePrimaria]) ?>" class="link-btn">editar</a>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Excluir este registro?');">
                                        <input type="hidden" name="acao" value="excluir_registro">
                                        <input type="hidden" name="registro_id" value="<?= htmlspecialchars($linha[$chavePrimaria]) ?>">
                                        <button type="submit" class="link-btn danger-link" style="background:none; border:none; padding:0; margin:0; cursor:pointer; font:inherit;">excluir</button>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>
<?php mysqli_close($conexao); ?>
