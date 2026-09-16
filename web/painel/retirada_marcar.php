<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../core/retiradas_core.php';
require_once __DIR__ . '/../../core/motivos_core.php';
require_once __DIR__ . '/../../core/alunos_core.php';
require_once __DIR__ . '/../../core/dispensas_core.php';

$conexao = conectarBanco();

if (!temPermissao($conexao, 'painel', $_SESSION['painel_cargo'], 'registrar_retirada', idUsuarioPainel())) {
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
$podeLancarDispensa = podeGerenciarDispensas();

// ---------- Lançar dispensa médica direto na chamada ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'lancar_dispensa' && $retirada['status'] === 'pendente' && $podeLancarDispensa) {
    $alunoId = (int) ($_POST['aluno_id'] ?? 0);
    $dados = $_POST;
    $dados['painel_usuario_id'] = idUsuarioPainel();

    $resultado = criarDispensa($conexao, $dados);
    if ($resultado['ok']) {
        $motivoDispensa = buscarMotivoPorCodigo($conexao, 'DMED');
        if ($motivoDispensa) {
            marcarItem($conexao, $id, $alunoId, 0, $motivoDispensa['id'], null);
        }
        $mensagem = "Dispensa lançada e chamada atualizada.";
    } else {
        $erro = $resultado['erro'];
    }
}

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
$tiposDispensa = $podeLancarDispensa ? listarDispensaTipos($conexao) : [];

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
    .tag-check { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; background: #eef1f6; border: 1px solid var(--border); border-radius: 999px; padding: 4px 10px; cursor: pointer; }
    .tag-check input { margin: 0; }
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

    function alternarDispensa(alunoId) {
        const linha = document.getElementById('dispensa_' + alunoId);
        linha.hidden = !linha.hidden;
    }

    function lancarDispensa(alunoId) {
        const campos = {
            acao: 'lancar_dispensa',
            aluno_id: alunoId,
            data_inicio: document.getElementById('disp_inicio_' + alunoId).value,
            data_termino: document.getElementById('disp_termino_' + alunoId).value,
            numero: document.getElementById('disp_numero_' + alunoId).value,
            motivo: document.getElementById('disp_motivo_' + alunoId).value,
            dispensado_de: document.getElementById('disp_dispensado_de_' + alunoId).value,
        };
        if (!campos.motivo.trim()) {
            alert('Informe o motivo da dispensa.');
            return;
        }
        const form = document.createElement('form');
        form.method = 'post';
        for (const [nome, valor] of Object.entries(campos)) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = nome;
            input.value = valor;
            form.appendChild(input);
        }
        document.querySelectorAll('.disp_tipo_' + alunoId + ':checked').forEach(chk => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'dispensa_tipo_ids[]';
            input.value = chk.value;
            form.appendChild(input);
        });
        document.body.appendChild(form);
        form.submit();
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
    <p style="color: var(--text-muted); font-size: 13px;">Responsável: <strong><?= htmlspecialchars($retirada['responsavel_nome'] ?? '—') ?></strong></p>
    <?php if ($retirada['protocolo']): ?>
        <p style="color: var(--text-muted); font-size: 13px;">Protocolo: <strong><?= htmlspecialchars($retirada['protocolo']) ?></strong></p>
    <?php endif; ?>

    <?php if ($erro): ?><p class="erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($mensagem): ?><p class="ok"><?= htmlspecialchars($mensagem) ?></p><?php endif; ?>

    <div class="card">
        <form method="post">
            <div class="scroll-x">
            <table>
                <tr><th>Presente</th><th>Identificação</th><th>Motivo (se faltou)</th><th>Observação</th><?php if (!$somenteLeitura && $podeLancarDispensa): ?><th>Dispensa</th><?php endif; ?></tr>
                <?php foreach ($itens as $item): ?>
                    <tr id="linha_<?= $item['aluno_id'] ?>" class="<?= !$item['presente'] ? 'falta-row' : '' ?>">
                        <td>
                            <input type="checkbox" name="itens[<?= $item['aluno_id'] ?>][presente]"
                                <?= $item['presente'] ? 'checked' : '' ?>
                                <?= $somenteLeitura ? 'disabled' : '' ?>
                                onchange="alternarMotivo(<?= $item['aluno_id'] ?>, this)">
                        </td>
                        <td><?= htmlspecialchars(identificacaoAluno($item)) ?></td>
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
                        <?php if (!$somenteLeitura && $podeLancarDispensa): ?>
                        <td>
                            <button type="button" onclick="alternarDispensa(<?= $item['aluno_id'] ?>)">Lançar</button>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php if (!$somenteLeitura && $podeLancarDispensa): ?>
                    <tr id="dispensa_<?= $item['aluno_id'] ?>" hidden>
                        <td colspan="5">
                            <div class="form-linha" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; padding:8px 0;">
                                <label style="font-size:12px; color:var(--text-muted);">Início <input type="date" id="disp_inicio_<?= $item['aluno_id'] ?>" value="<?= date('Y-m-d') ?>"></label>
                                <label style="font-size:12px; color:var(--text-muted);">Término <input type="date" id="disp_termino_<?= $item['aluno_id'] ?>" value="<?= date('Y-m-d') ?>"></label>
                                <label style="font-size:12px; color:var(--text-muted);">Nº <input type="text" id="disp_numero_<?= $item['aluno_id'] ?>" style="width:70px;"></label>
                                <label style="font-size:12px; color:var(--text-muted);">Motivo <input type="text" id="disp_motivo_<?= $item['aluno_id'] ?>" style="min-width:160px;"></label>
                            </div>
                            <div class="form-linha" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; padding:0 0 8px;">
                                <span style="font-size:12px; color:var(--text-muted);">Dispensado de:</span>
                                <?php foreach ($tiposDispensa as $t): ?>
                                    <label class="tag-check"><input type="checkbox" class="disp_tipo_<?= $item['aluno_id'] ?>" value="<?= $t['id'] ?>"> <?= htmlspecialchars($t['nome']) ?></label>
                                <?php endforeach; ?>
                                <input type="text" id="disp_dispensado_de_<?= $item['aluno_id'] ?>" placeholder="Específico (opcional)" style="min-width:160px;">
                                <button type="button" onclick="lancarDispensa(<?= $item['aluno_id'] ?>)">Salvar dispensa</button>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
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

<?php include __DIR__ . '/../../core/rodape_versao.php'; ?>
</body>
</html>
<?php mysqli_close($conexao); ?>
