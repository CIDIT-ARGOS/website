<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../core/retiradas_core.php';

$conexao = conectarBanco();

if (!temPermissao($conexao, 'painel', $_SESSION['painel_cargo'], 'registrar_retirada', idUsuarioPainel())) {
    die("Seu cargo não tem a permissão 'registrar_retirada'.");
}

$escopo = escopoEsquadrao();
$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir') {
    $resultado = excluirRetirada($conexao, (int) $_POST['id']);
    if (!$resultado['ok']) {
        $erro = $resultado['erro'];
    }
}

$retiradas = listarRetiradas($conexao, ['esquadrao' => $escopo, 'limite' => 100]);

$tiposRetirada = ['1_jornada' => '1ª Jornada', '2_jornada' => '2ª Jornada', 'educacao_fisica' => 'Educação Física', 'pernoite' => 'Pernoite'];

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Painel — Retiradas</title>
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
    .container { padding: 24px; max-width: 1100px; margin: 0 auto; }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
    button, a.btn { padding: 8px 14px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; text-decoration: none; display: inline-block; }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 6px 10px; text-align: left; }
    th { background: var(--azul-eear); color: #ffffff; }
    .scroll-x { overflow-x: auto; min-width: 0; }
    .badge { font-size: 11px; padding: 2px 8px; border-radius: 999px; }
    .badge.pendente { background: #fff3cd; color: #8a6100; }
    .badge.enviada { background: #d9f2e3; color: var(--ok); }
    .link-btn { color: var(--accent); text-decoration: none; font-size: 12px; }
    .i { display: inline-block; width: 13px; height: 13px; vertical-align: -2px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 4px; }
</style>
</head>
<body>

<div class="topbar">
    <div><strong>ARGOS</strong> <a href="index.php"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span>painel</a></div>
    <div><a href="index.php?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a></div>
</div>

<div class="container">
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <h2>Retiradas <?= $escopo ? '— Esquadrão ' . htmlspecialchars($escopo) : '' ?></h2>
        <a href="retirada_nova.php" class="btn"><span class="i" style="--icon-url:url('../images/icons/plus.svg')"></span>Nova retirada</a>
    </div>

    <?php if ($erro): ?><p class="erro" style="color: var(--danger, #c0392b);"><?= htmlspecialchars($erro) ?></p><?php endif; ?>

    <div class="card">
        <?php if (empty($retiradas)): ?>
            <p style="color: var(--text-muted); font-size: 13px;">Nenhuma retirada registrada ainda.</p>
        <?php else: ?>
        <div class="scroll-x">
        <table>
            <tr><th>Data/Hora</th><th>Tipo</th><th>Agrupamento</th><th>Esquadrão</th><th>Responsável</th><th>Status</th><th>Protocolo</th><th>Ações</th></tr>
            <?php foreach ($retiradas as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['data_hora']) ?></td>
                    <td><?= htmlspecialchars($tiposRetirada[$r['tipo']] ?? $r['tipo']) ?></td>
                    <td><?= htmlspecialchars($r['agrupamento_tipo']) ?>: <?= htmlspecialchars($r['agrupamento_valor']) ?></td>
                    <td><?= htmlspecialchars($r['esquadrao'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($r['responsavel_nome'] ?? '—') ?></td>
                    <td><span class="badge <?= $r['status'] ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                    <td><?= htmlspecialchars($r['protocolo'] ?? '—') ?></td>
                    <td style="white-space:nowrap;">
                        <?php if ($r['status'] === 'pendente'): ?>
                            <a href="retirada_marcar.php?id=<?= $r['id'] ?>" class="link-btn"><span class="i" style="--icon-url:url('../images/icons/clipboard-check.svg')"></span>continuar chamada</a>
                        <?php else: ?>
                            <a href="retirada_marcar.php?id=<?= $r['id'] ?>" class="link-btn"><span class="i" style="--icon-url:url('../images/icons/eye.svg')"></span>ver</a>
                        <?php endif; ?>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Excluir esta retirada e todos os itens marcados nela? Não dá pra desfazer.');">
                            <input type="hidden" name="acao" value="excluir">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button type="submit" class="link-btn" style="background:none; border:none; padding:0; margin-left:10px; cursor:pointer; font:inherit; color: var(--danger, #c0392b);"><span class="i" style="--icon-url:url('../images/icons/trash.svg')"></span>excluir</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../core/rodape_versao.php'; ?>
</body>
</html>
<?php mysqli_close($conexao); ?>
