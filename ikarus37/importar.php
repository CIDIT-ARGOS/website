<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';

$conexao = conectarBanco();

$tabelasValidas = [];
$r = mysqli_query($conexao, "SHOW TABLES");
while ($linha = mysqli_fetch_array($r)) {
    $tabelasValidas[] = $linha[0];
}

$tabela = $_GET['tabela'] ?? ($_POST['tabela'] ?? '');
$mensagem = null;
$erro = null;
$previa = [];

if ($tabela !== '' && !in_array($tabela, $tabelasValidas)) {
    $erro = "Tabela inválida.";
    $tabela = '';
}

$colunasTabela = [];
if ($tabela) {
    $resCols = mysqli_query($conexao, "SHOW COLUMNS FROM `$tabela`");
    while ($c = mysqli_fetch_assoc($resCols)) {
        $colunasTabela[] = $c['Field'];
    }
}

// ---------- Processar upload ----------
if ($tabela && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivo']) && $_FILES['arquivo']['error'] === UPLOAD_ERR_OK) {
    $handle = fopen($_FILES['arquivo']['tmp_name'], 'r');
    $cabecalho = fgetcsv($handle);

    if (!$cabecalho) {
        $erro = "Arquivo CSV vazio ou inválido.";
    } else {
        $cabecalho = array_map('trim', $cabecalho);
        $colunasInvalidas = array_diff($cabecalho, $colunasTabela);

        if (!empty($colunasInvalidas)) {
            $erro = "Colunas do CSV não existem na tabela: " . implode(', ', $colunasInvalidas);
        } else {
            $placeholders = implode(', ', array_fill(0, count($cabecalho), '?'));
            $colunasSql = implode(', ', array_map(fn($c) => "`$c`", $cabecalho));
            $updateSql = implode(', ', array_map(fn($c) => "`$c` = VALUES(`$c`)", $cabecalho));

            $sql = "INSERT INTO `$tabela` ($colunasSql) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updateSql";
            $stmt = mysqli_prepare($conexao, $sql);
            $tipos = str_repeat('s', count($cabecalho));

            $totalLinhas = 0;
            $totalErros = 0;
            $errosDetalhe = [];

            while (($linhaCsv = fgetcsv($handle)) !== false) {
                if (count($linhaCsv) !== count($cabecalho)) {
                    continue;
                }
                $valores = array_map(fn($v) => $v === '' ? null : $v, $linhaCsv);

                mysqli_stmt_bind_param($stmt, $tipos, ...$valores);
                if (mysqli_stmt_execute($stmt)) {
                    $totalLinhas++;
                } else {
                    $totalErros++;
                    $errosDetalhe[] = mysqli_stmt_error($stmt);
                }
            }

            $mensagem = "$totalLinhas linha(s) importada(s)/atualizada(s).";
            if ($totalErros > 0) {
                $erro = "$totalErros linha(s) com erro: " . implode(' | ', array_slice($errosDetalhe, 0, 5));
            }
        }
    }

    fclose($handle);
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Ikarus37 — Importar dados</title>
<style>
    :root { --bg: #0f1115; --bg-card: #171a21; --border: #2a2e37; --text: #e6e8eb; --text-muted: #9aa1ac; --accent: #4f8cff; --danger: #ff5f5f; --ok: #4caf7d; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); }
    .topbar { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid var(--border); }
    .topbar .brand { font-weight: 600; }
    .topbar a { color: var(--text-muted); text-decoration: none; font-size: 13px; margin-left: 16px; }
    .container { padding: 24px; max-width: 700px; margin: 0 auto; }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
    select, input[type=file] { padding: 8px 10px; background: #0f1115; border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 13px; width: 100%; margin-bottom: 12px; }
    button { padding: 9px 18px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; }
    .erro { color: var(--danger); font-size: 13px; }
    .ok { color: var(--ok); font-size: 13px; }
    code { background: #0f1115; padding: 1px 5px; border-radius: 4px; }
</style>
</head>
<body>

<div class="topbar">
    <div class="brand">IKARUS37 <a href="banco.php">← banco de dados</a></div>
    <div><a href="index.php?logout=1">sair</a></div>
</div>

<div class="container">
    <h2>Importar dados (CSV)</h2>

    <div class="card">
        <form method="get">
            <label>Tabela de destino</label>
            <select name="tabela" onchange="this.form.submit()">
                <option value="">Selecione...</option>
                <?php foreach ($tabelasValidas as $t): ?>
                    <option value="<?= htmlspecialchars($t) ?>" <?= $t === $tabela ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if ($tabela): ?>
            <p style="font-size:13px; color: var(--text-muted);">
                Colunas de <code><?= htmlspecialchars($tabela) ?></code>: <?= implode(', ', $colunasTabela) ?>
            </p>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="tabela" value="<?= htmlspecialchars($tabela) ?>">
                <input type="file" name="arquivo" accept=".csv" required>
                <button type="submit">Importar</button>
            </form>

            <p style="font-size:12px; color: var(--text-muted); margin-top: 10px;">
                O CSV precisa ter cabeçalho com nomes de coluna exatamente iguais aos da tabela (não precisa incluir todas).
                Se o registro já existir (mesma chave única), os dados são <strong>atualizados</strong>; senão, é <strong>criado</strong>.
            </p>
        <?php endif; ?>

        <?php if ($erro): ?><p class="erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
        <?php if ($mensagem): ?><p class="ok"><?= htmlspecialchars($mensagem) ?></p><?php endif; ?>
    </div>
</div>

</body>
</html>
<?php mysqli_close($conexao); ?>
