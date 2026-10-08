<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/acesso_negado.php';
require_once __DIR__ . '/auth.php';

$conexao = conectarBanco();

if (!temPermissao($conexao, 'ikarus37', $_SESSION['admin_nivel'], 'acesso_tecnico_avancado')) {
    exibirAcessoNegado("Sua conta não tem a permissão 'acesso_tecnico_avancado'.");
}

$tabelasValidas = [];
$r = mysqli_query($conexao, "SHOW TABLES");
while ($linha = mysqli_fetch_array($r)) {
    $tabelasValidas[] = $linha[0];
}

$tabela = $_GET['tabela'] ?? '';
if (!in_array($tabela, $tabelasValidas)) {
    die("Tabela inválida.");
}

$estrutura = [];
$chavePrimaria = null;
$resCols = mysqli_query($conexao, "SHOW COLUMNS FROM `$tabela`");
while ($c = mysqli_fetch_assoc($resCols)) {
    $estrutura[] = $c;
    if ($c['Key'] === 'PRI' && !$chavePrimaria) {
        $chavePrimaria = $c['Field'];
    }
}

if (!$chavePrimaria) {
    die("Tabela sem chave primária — edição não suportada.");
}

$id = $_GET['id'] ?? ($_POST['id'] ?? null);
$mensagem = null;
$erro = null;

// ---------- Salvar ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sets = [];
    $valores = [];
    $tipos = "";

    foreach ($estrutura as $c) {
        $campo = $c['Field'];
        if ($campo === $chavePrimaria) continue;
        if (!array_key_exists($campo, $_POST)) continue;

        $sets[] = "`$campo` = ?";
        $valor = $_POST[$campo];
        $valores[] = $valor === '' ? null : $valor;
        $tipos .= "s";
    }

    $valores[] = $id;
    $tipos .= "s";

    $sql = "UPDATE `$tabela` SET " . implode(", ", $sets) . " WHERE `$chavePrimaria` = ?";
    $stmt = mysqli_prepare($conexao, $sql);
    mysqli_stmt_bind_param($stmt, $tipos, ...$valores);

    if (mysqli_stmt_execute($stmt)) {
        $mensagem = "Registro atualizado com sucesso.";
    } else {
        $erro = "Erro ao atualizar: " . mysqli_stmt_error($stmt);
    }
}

// ---------- Carregar registro atual ----------
$stmt = mysqli_prepare($conexao, "SELECT * FROM `$tabela` WHERE `$chavePrimaria` = ?");
mysqli_stmt_bind_param($stmt, "s", $id);
mysqli_stmt_execute($stmt);
$registro = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$registro) {
    die("Registro não encontrado.");
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Ikarus37 — Editar registro</title>
<style>
    :root { --bg: #0f1115; --bg-card: #171a21; --border: #2a2e37; --text: #e6e8eb; --text-muted: #9aa1ac; --accent: #4f8cff; --danger: #ff5f5f; --ok: #4caf7d; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); }
    .topbar { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid var(--border); }
    .topbar .brand { font-weight: 600; }
    .topbar a { color: var(--text-muted); text-decoration: none; font-size: 13px; margin-left: 16px; }
    .container { padding: 24px; max-width: 600px; margin: 0 auto; }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; }
    label { display: block; font-size: 12px; color: var(--text-muted); margin-top: 12px; margin-bottom: 4px; }
    input { width: 100%; padding: 8px 10px; background: #0f1115; border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 13px; }
    input[readonly] { color: var(--text-muted); }
    button { padding: 9px 18px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; margin-top: 18px; }
    .erro { color: var(--danger); font-size: 13px; }
    .ok { color: var(--ok); font-size: 13px; }
    .i { display: inline-block; width: 16px; height: 16px; vertical-align: -3px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 5px; }
</style>
</head>
<body>

<div class="topbar">
    <div class="brand">IKARUS37 <a href="banco.php?tabela=<?= urlencode($tabela) ?>"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span><?= htmlspecialchars($tabela) ?></a></div>
    <div><a href="index.php?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a></div>
</div>

<div class="container">
    <h2>Editar registro — <?= htmlspecialchars($tabela) ?></h2>

    <?php if ($erro): ?><p class="erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($mensagem): ?><p class="ok"><?= htmlspecialchars($mensagem) ?></p><?php endif; ?>

    <div class="card">
        <form method="post">
            <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
            <?php foreach ($estrutura as $c): ?>
                <?php $campo = $c['Field']; ?>
                <label><?= htmlspecialchars($campo) ?><?= $campo === $chavePrimaria ? ' (chave primária)' : '' ?></label>
                <input
                    type="text"
                    name="<?= htmlspecialchars($campo) ?>"
                    value="<?= htmlspecialchars((string)($registro[$campo] ?? '')) ?>"
                    <?= $campo === $chavePrimaria ? 'readonly' : '' ?>
                >
            <?php endforeach; ?>
            <button type="submit">Salvar</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../core/rodape_versao.php'; ?>
</body>
</html>
<?php mysqli_close($conexao); ?>
