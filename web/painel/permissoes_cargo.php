<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/acesso_negado.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../core/cargos_core.php';
require_once __DIR__ . '/../../core/cargo_permissoes_core.php';

$usuarioId = idUsuarioPainel();
$conexao = conectarBanco();

if (!temPermissao($conexao, 'painel', $_SESSION['painel_cargo'], 'gerenciar_cargos', $usuarioId)) {
    exibirAcessoNegado("Seu cargo não tem a permissão 'gerenciar_cargos'.");
}

$mensagem = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar') {
    $cargos = listarChavesCargos($conexao, 'painel');
    foreach ($cargos as $cargo) {
        $idsMarcados = $_POST['perm'][$cargo] ?? [];
        definirPermissoesDoCargo($conexao, 'painel', $cargo, $idsMarcados);
    }
    $mensagem = "Permissões atualizadas.";
}

$permissoes = listarPermissoesCatalogo($conexao);
$cargos = listarCargos($conexao, 'painel');
$marcadas = [];
foreach ($cargos as $c) {
    $marcadas[$c['chave']] = listarPermissaoIdsDoCargo($conexao, 'painel', $c['chave']);
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Painel — Permissões por cargo</title>
<style>
    :root {
        --bg: #f4f7fc; --bg-card: #ffffff; --border: #dbe3ef;
        --text: #16202e; --text-muted: #55637a; --accent: #2f6fed;
        --azul-eear: #0a2e5c; --danger: #c0392b; --ok: #1f8a4c;
    }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); overflow-x: hidden; }
    .topbar { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid var(--border); }
    .topbar a { color: var(--text-muted); text-decoration: none; font-size: 13px; margin-left: 16px; }
    .container { padding: 24px; max-width: 1200px; margin: 0 auto; }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
    button { padding: 8px 14px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 8px 10px; text-align: left; vertical-align: top; }
    th { background: var(--azul-eear); color: #ffffff; }
    td.centro, th.centro { text-align: center; }
    .ok { color: var(--ok); font-size: 13px; }
    .scroll-x { overflow-x: auto; min-width: 0; }
    .descricao { color: var(--text-muted); font-size: 12px; }
    .i { display: inline-block; width: 16px; height: 16px; vertical-align: -3px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 5px; }
</style>
</head>
<body>

<div class="topbar">
    <div><strong>ARGOS</strong> <a href="dominio.php"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span>domínio</a></div>
    <div><a href="index.php?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a></div>
</div>

<div class="container">
    <h2>Permissões por cargo</h2>
    <p class="descricao">O que cada cargo pode fazer no sistema, sem precisar mexer direto no banco. Marque/desmarque e salve — vale pra todo mundo com aquele cargo.</p>

    <?php if ($mensagem): ?><p class="ok"><?= htmlspecialchars($mensagem) ?></p><?php endif; ?>

    <div class="card">
        <div class="scroll-x">
        <form method="post">
            <input type="hidden" name="acao" value="salvar">
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
