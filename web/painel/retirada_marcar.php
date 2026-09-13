<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../core/retiradas_core.php';
require_once __DIR__ . '/../../core/motivos_core.php';

$conexao = conectarBanco();

if (!temPermissao($conexao, 'painel', $_SESSION['painel_cargo'], 'registrar_retirada')) {
    die("Seu cargo não tem a permissão 'registrar_retirada'.");
}

$escopo = escopoEsquadrao();
$id = (int)($_GET['id'] ?? 0);

$retirada = buscarRetiradaPorId($conexao, $id);
if (!$retirada) {
    die("Retirada não encontrada.");
}
if ($escopo !== null && $retirada['esquadrao'] !== $escopo) {
    die("Você não tem permissão para ver retiradas fora do Esquadrão $escopo.");
}

$mensagem = null;
$erro = null;

// ---------- Salvar marcações ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['itens']) && $retirada['status'] === 'pendente') {
    foreach ($_POST['itens'] as $alunoId => $item) {
        $presente = isset($item['presente']) ? 1 : 0;
        $motivoFaltaId = !empty($item['motivo_falta_id']) ? (int)$item['motivo_falta_id'] : null;
        $observacao = trim($item['observacao'] ?? '') ?: null;
        marcarItem($conexao, $id, (int)$alunoId, $presente, $motivoFaltaId, $observacao);
    }
    $mensagem = "Marcações salvas.";

    if (isset($_POST['enviar'])) {
        $resultadoEnvio = enviarRetirada($conexao, $id);
        if ($resultadoEnvio['ok']) {
            $mensagem = "Retirada enviada. Protocolo: " . $resultadoEnvio['protocolo'];
        } else {
            $erro = $resultadoEnvio['erro'];
        }
    }

    $retirada = buscarRetiradaPorId($conexao, $id);
}

$itens = listarItensRetirada($conexao, $id);

$motivos = listarMotivos($conexao);

$tiposRetirada = ['1_jornada' => '1ª Jornada', '2_jornada' => '2ª Jornada', 'educacao_fisica' => 'Educação Física', 'pernoite' => 'Pernoite'];
$somenteLeitura = $retirada['status'] === 'enviada';

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Painel — Chamada</title>
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
    .container { padding: 24px; max-width: 900px; margin: 0 auto; }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 6px 10px; text-align: left; vertical-align: middle; }
    th { background: var(--azul-eear); color: #ffffff; }
    .scroll-x { overflow-x: auto; min-width: 0; }
    select, input[type=text] { padding: 6px 8px; background: #ffffff; border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 12px; }
    button { padding: 9px 18px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; margin-top: 14px; margin-right: 8px; }
    button.enviar { background: var(--ok); }
    .erro { color: var(--danger); font-size: 13px; }
    .ok { color: var(--ok); font-size: 13px; }
    .falta-row { background: #fdeaea; }
    .badge { font-size: 11px; padding: 2px 8px; border-radius: 999px; }
    .badge.pendente { background: #fff3cd; color: #8a6100; }
    .badge.enviada { background: #d9f2e3; color: var(--ok); }
    .i { display: inline-block; width: 13px; height: 13px; vertical-align: -2px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 4px; }
</style>
<script>
    function alternarMotivo(alunoId, presenteCheckbox) {
        const linha = document.getElementById('linha_' + alunoId);
        const motivoSel = document.getElementById('motivo_' + alunoId);
        const falta = !presenteCheckbox.checked;
        motivoSel.style.display = falta ? 'inline-block' : 'none';
        linha.classList.toggle('falta-row', falta);
    }
</script>
</head>
<body>

<div class="topbar">
    <div><strong>ARGOS</strong> <a href="retiradas.php"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span>retiradas</a></div>
    <div><a href="index.php?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a></div>
</div>

<div class="container">
    <h2>
        Chamada — <?= htmlspecialchars($tiposRetirada[$retirada['tipo']] ?? $retirada['tipo']) ?>
        (<?= htmlspecialchars($retirada['agrupamento_tipo']) ?>: <?= htmlspecialchars($retirada['agrupamento_valor']) ?>)
        <span class="badge <?= $retirada['status'] ?>"><?= htmlspecialchars($retirada['status']) ?></span>
    </h2>
    <?php if ($retirada['protocolo']): ?>
        <p style="color: var(--text-muted); font-size: 13px;">Protocolo: <strong><?= htmlspecialchars($retirada['protocolo']) ?></strong></p>
    <?php endif; ?>

    <?php if ($erro): ?><p class="erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($mensagem): ?><p class="ok"><?= htmlspecialchars($mensagem) ?></p><?php endif; ?>

    <div class="card">
        <form method="post">
            <div class="scroll-x">
            <table>
                <tr><th>Presente</th><th>Nome de Guerra</th><th>Milhão</th><th>Motivo (se faltou)</th><th>Observação</th></tr>
                <?php foreach ($itens as $item): ?>
                    <tr id="linha_<?= $item['aluno_id'] ?>" class="<?= !$item['presente'] ? 'falta-row' : '' ?>">
                        <td>
                            <input type="checkbox" name="itens[<?= $item['aluno_id'] ?>][presente]"
                                <?= $item['presente'] ? 'checked' : '' ?>
                                <?= $somenteLeitura ? 'disabled' : '' ?>
                                onchange="alternarMotivo(<?= $item['aluno_id'] ?>, this)">
                        </td>
                        <td><?= htmlspecialchars($item['nome_guerra']) ?></td>
                        <td><?= htmlspecialchars($item['milhao']) ?></td>
                        <td>
                            <select id="motivo_<?= $item['aluno_id'] ?>" name="itens[<?= $item['aluno_id'] ?>][motivo_falta_id]"
                                style="<?= $item['presente'] ? 'display:none;' : '' ?>" <?= $somenteLeitura ? 'disabled' : '' ?>>
                                <option value="">—</option>
                                <?php foreach ($motivos as $m): ?>
                                    <option value="<?= $m['id'] ?>" <?= $item['motivo_falta_id'] == $m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type="text" name="itens[<?= $item['aluno_id'] ?>][observacao]"
                                value="<?= htmlspecialchars($item['observacao'] ?? '') ?>" <?= $somenteLeitura ? 'disabled' : '' ?>>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
            </div>

            <?php if (!$somenteLeitura): ?>
                <button type="submit">Salvar</button>
                <button type="submit" name="enviar" value="1" class="enviar" onclick="return confirm('Enviar a chamada? Depois de enviada não dá mais pra editar.');">Salvar e enviar chamada</button>
            <?php endif; ?>
        </form>
    </div>
</div>

</body>
</html>
<?php mysqli_close($conexao); ?>
