<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/acesso_negado.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../core/cargos_core.php';
require_once __DIR__ . '/../../core/cargo_permissoes_core.php';

$conexao = conectarBanco();

if (!temPermissao($conexao, 'ikarus37', $_SESSION['admin_nivel'], 'gerenciar_cargos')) {
    exibirAcessoNegado("Sua conta não tem a permissão 'gerenciar_cargos'.");
}

$mensagem = null;

$sistema = ($_GET['sistema'] ?? 'painel') === 'ikarus37' ? 'ikarus37' : 'painel';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar') {
    $sistema = ($_POST['sistema'] ?? 'painel') === 'ikarus37' ? 'ikarus37' : 'painel';
    $cargos = listarChavesCargos($conexao, $sistema);
    foreach ($cargos as $cargo) {
        $idsMarcados = $_POST['perm'][$cargo] ?? [];
        definirPermissoesDoCargo($conexao, $sistema, $cargo, $idsMarcados);
    }
    $mensagem = "Permissões atualizadas.";
}

$permissoes = listarPermissoesCatalogo($conexao);
$cargos = listarCargos($conexao, $sistema);
$marcadas = [];
foreach ($cargos as $c) {
    $marcadas[$c['chave']] = listarPermissaoIdsDoCargo($conexao, $sistema, $c['chave']);
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Ikarus37 — Permissões por cargo</title>
<style>
    :root { --bg: #0f1115; --bg-card: #171a21; --border: #2a2e37; --text: #e6e8eb; --text-muted: #9aa1ac; --accent: #4f8cff; --danger: #ff5f5f; --ok: #4caf7d; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); overflow-x: hidden; }
    .topbar { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid var(--border); }
    .topbar a { color: var(--text-muted); text-decoration: none; font-size: 13px; margin-left: 16px; }
    .container { padding: 24px; max-width: 1200px; margin: 0 auto; }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
    button { padding: 8px 14px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 8px 10px; text-align: left; vertical-align: top; }
    th { background: #1d212a; color: var(--text-muted); }
    td.centro, th.centro { text-align: center; }
    .ok { color: var(--ok); font-size: 13px; }
    .scroll-x { overflow-x: auto; min-width: 0; }
    .descricao { color: var(--text-muted); font-size: 12px; }
    .tabs { display: flex; gap: 4px; margin-bottom: 16px; }
    .tabs a { padding: 8px 14px; border-radius: 6px; color: var(--text-muted); text-decoration: none; font-size: 13px; border: 1px solid var(--border); }
    .tabs a.ativo { background: var(--accent); color: #fff; border-color: var(--accent); }
    .i { display: inline-block; width: 16px; height: 16px; vertical-align: -3px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 5px; }
</style>
</head>
<body>

<div class="topbar">
    <div class="brand">IKARUS37 <a href="dominio.php"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span>domínio</a></div>
    <div><a href="index.php?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a></div>
</div>

<div class="container">
    <h2>Permissões por cargo</h2>
    <p class="descricao">O que cada cargo pode fazer no sistema, sem precisar mexer direto no banco.</p>

    <div class="tabs">
        <a href="?sistema=painel" class="<?= $sistema === 'painel' ? 'ativo' : '' ?>">Painel</a>
        <a href="?sistema=ikarus37" class="<?= $sistema === 'ikarus37' ? 'ativo' : '' ?>">Ikarus37</a>
    </div>

    <?php if ($mensagem): ?><p class="ok"><?= htmlspecialchars($mensagem) ?></p><?php endif; ?>

    <div class="card">
        <div class="scroll-x">
        <form method="post">
            <input type="hidden" name="acao" value="salvar">
            <input type="hidden" name="sistema" value="<?= htmlspecialchars($sistema) ?>">
            <table>
                <tr>
                    <th>Permissão</th>
                    <?php foreach ($cargos as $c): ?>
                        <th class="centro"><?= htmlspecialchars($c['nome']) ?></th>
                    <?php endforeach; ?>
                </tr>
                <?php foreach ($permissoes as $p): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($p['chave']) ?></strong><br>
                            <span class="descricao"><?= htmlspecialchars($p['descricao']) ?></span>
                        </td>
                        <?php foreach ($cargos as $c): ?>
                            <td class="centro">
                                <input type="checkbox" name="perm[<?= htmlspecialchars($c['chave']) ?>][]" value="<?= $p['id'] ?>"
                                    <?= in_array((int) $p['id'], $marcadas[$c['chave']]) ? 'checked' : '' ?>>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </table>
            <div style="margin-top:14px;">
                <button type="submit"><span class="i" style="--icon-url:url('../images/icons/device-floppy.svg')"></span>Salvar permissões</button>
            </div>
        </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../core/rodape_versao.php'; ?>
</body>
</html>
<?php mysqli_close($conexao); ?>
