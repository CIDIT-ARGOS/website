<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/acesso_negado.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../core/dispensas_core.php';
require_once __DIR__ . '/../../core/alunos_core.php';

if (!podeGerenciarDispensas()) {
    exibirAcessoNegado("Seu cargo não tem a permissão 'gerenciar_dispensas'.");
}

$conexao = conectarBanco();
$escopo = escopoEsquadrao();
$usuarioId = idUsuarioPainel();

$mensagem = null;
$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'criar') {
        $dados = $_POST;
        $dados['painel_usuario_id'] = $usuarioId;

        $aluno = buscarAlunoPorMilhao($conexao, trim($_POST['milhao_aluno'] ?? ''));
        if (!$aluno) {
            $erro = "Aluno não encontrado — escolha um nome da lista de sugestões.";
        } elseif ($escopo !== null && $aluno['esquadrao'] !== $escopo) {
            $erro = "Você só pode cadastrar dispensa pra alunos do Esquadrão $escopo.";
        } else {
            $dados['aluno_id'] = $aluno['id'];
            $resultado = criarDispensa($conexao, $dados);
            $mensagem = $resultado['ok'] ? "Dispensa cadastrada." : null;
            $erro = $resultado['ok'] ? null : $resultado['erro'];
        }
    }

    if ($acao === 'atualizar') {
        $id = (int) $_POST['id'];
        $existente = buscarDispensaPorId($conexao, $id);
        if (!$existente || ($escopo !== null && $existente['esquadrao'] !== $escopo)) {
            $erro = "Dispensa não encontrada.";
        } else {
            $resultado = atualizarDispensa($conexao, $id, $_POST);
            $mensagem = $resultado['ok'] ? "Dispensa atualizada." : null;
            $erro = $resultado['ok'] ? null : $resultado['erro'];
        }
    }

    if ($acao === 'excluir') {
        $id = (int) $_POST['id'];
        $existente = buscarDispensaPorId($conexao, $id);
        if (!$existente || ($escopo !== null && $existente['esquadrao'] !== $escopo)) {
            $erro = "Dispensa não encontrada.";
        } else {
            $resultado = excluirDispensa($conexao, $id);
            $mensagem = $resultado['ok'] ? "Dispensa excluída." : null;
            $erro = $resultado['ok'] ? null : $resultado['erro'];
        }
    }
}

$dispensas = listarDispensas($conexao, $escopo !== null ? ['esquadrao' => $escopo] : []);
$filtrosAlunos = ['com_posto_exibicao' => true];
if ($escopo !== null) {
    $filtrosAlunos['esquadrao'] = $escopo;
}
$alunos = listarAlunos($conexao, $filtrosAlunos);
$tiposDispensa = listarDispensaTipos($conexao);
$dataInicioMin = date('Y-m-d', strtotime('-' . DISPENSA_TOLERANCIA_DIAS_PASSADO . ' days'));
$dataInicioMax = date('Y-m-d', strtotime('+' . DISPENSA_TOLERANCIA_DIAS_FUTURO . ' days'));

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Painel — Dispensas médicas</title>
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
    input, select { padding: 8px 10px; background: #ffffff; border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 13px; }
    select { max-width: 100%; }
    button { padding: 8px 14px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; }
    button.danger { background: var(--danger); }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 8px 10px; text-align: left; vertical-align: top; }
    th { background: var(--azul-eear); color: #ffffff; }
    .erro { color: var(--danger); font-size: 13px; }
    .ok { color: var(--ok); font-size: 13px; }
    .form-linha { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .scroll-x { overflow-x: auto; min-width: 0; }
    .badge { font-size: 11px; padding: 2px 8px; border-radius: 999px; }
    .badge.ativa { background: #d9f2e3; color: var(--ok); }
    .badge.encerrada { background: #eef1f6; color: var(--text-muted); }
    .tag-check { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; background: #eef1f6; border: 1px solid var(--border); border-radius: 999px; padding: 4px 10px; cursor: pointer; }
    .tag-check input { margin: 0; }
    .i { display: inline-block; width: 13px; height: 13px; vertical-align: -2px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 4px; }
</style>
</head>
<body>

<div class="topbar">
    <div><strong>ARGOS</strong> <a href="index.php"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span>painel</a></div>
    <div><a href="index.php?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a></div>
</div>

<div class="container">
    <h2>Dispensas médicas <?= $escopo ? '— Esquadrão ' . htmlspecialchars($escopo) : '' ?></h2>
    <p style="color: var(--text-muted); font-size: 13px; margin-top: -8px;">
        Enquanto a dispensa estiver no período, ela é sugerida automaticamente (motivo DMED) ao abrir uma nova chamada — quem faz a chamada ainda pode sobrepor.
    </p>

    <?php if ($erro): ?><p class="erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($mensagem): ?><p class="ok"><?= htmlspecialchars($mensagem) ?></p><?php endif; ?>

    <div class="card">
        <h4>Nova dispensa</h4>
        <form method="post" class="form-linha">
            <input type="hidden" name="acao" value="criar">
            <input type="text" name="milhao_aluno" list="lista_alunos" placeholder="Digite o nome ou milhão do aluno" required autocomplete="off" style="min-width:260px;">
            <datalist id="lista_alunos">
                <?php foreach ($alunos as $a): ?>
                    <option value="<?= htmlspecialchars($a['milhao']) ?>"><?= htmlspecialchars(identificacaoAluno($a)) ?> — <?= htmlspecialchars($a['esquadrao']) ?>/<?= htmlspecialchars($a['esquadrilha']) ?></option>
                <?php endforeach; ?>
            </datalist>
            <label style="font-size:12px; color:var(--text-muted);">Início <input type="date" name="data_inicio" min="<?= $dataInicioMin ?>" max="<?= $dataInicioMax ?>" required></label>
            <label style="font-size:12px; color:var(--text-muted);">Término <input type="date" name="data_termino" required></label>
            <input type="text" name="numero" placeholder="Nº da dispensa">
            <input type="text" name="motivo" placeholder="Motivo" required style="min-width:180px;">
            <button type="submit">Cadastrar</button>
            <div style="width:100%; display:flex; gap:14px; flex-wrap:wrap; align-items:center; margin-top:4px;">
                <span style="font-size:12px; color:var(--text-muted);">Dispensado de:</span>
                <?php foreach ($tiposDispensa as $t): ?>
                    <label class="tag-check"><input type="checkbox" name="dispensa_tipo_ids[]" value="<?= $t['id'] ?>"> <?= htmlspecialchars($t['nome']) ?></label>
                <?php endforeach; ?>
                <input type="text" name="dispensado_de" placeholder="Específico (opcional)" style="min-width:180px;">
            </div>
        </form>
    </div>

    <div class="card">
        <h4>Dispensas (<?= count($dispensas) ?>)</h4>
        <div class="scroll-x">
        <table>
            <tr>
                <th>Aluno</th><th>Status</th><th>Ações</th>
            </tr>
            <?php $hoje = date('Y-m-d'); ?>
            <?php foreach ($dispensas as $d): ?>
                <?php $formId = 'form_' . $d['id']; ?>
                <?php $detalheId = 'detalhe_' . $d['id']; ?>
                <?php $ativa = $d['data_inicio'] <= $hoje && $d['data_termino'] >= $hoje; ?>
                <?php $tagsIdsAtuais = array_column(listarTagsDispensa($conexao, $d['id']), 'id'); ?>
                <tr>
                    <td><?= htmlspecialchars(identificacaoAluno($d)) ?></td>
                    <td><span class="badge <?= $ativa ? 'ativa' : 'encerrada' ?>"><?= $ativa ? 'ativa' : 'encerrada' ?></span></td>
                    <td style="white-space:nowrap;">
                        <button type="button" onclick="alternarDetalheDispensa('<?= $detalheId ?>', this)"><span class="i" style="--icon-url:url('../images/icons/eye.svg')"></span><span class="rotulo-abrir">Abrir</span></button>
                        <button type="button" class="danger" onclick="if(confirm('Excluir esta dispensa?')) document.getElementById('form_excluir_<?= $d['id'] ?>').submit();"><span class="i" style="--icon-url:url('../images/icons/trash.svg')"></span>Excluir</button>
                    </td>
                </tr>
                <tr id="<?= $detalheId ?>" hidden>
                    <td colspan="3">
                        <div class="form-linha" style="padding:10px 0;">
                            <label style="font-size:12px; color:var(--text-muted);">Início <input form="<?= $formId ?>" type="date" name="data_inicio" value="<?= htmlspecialchars($d['data_inicio']) ?>" min="<?= $dataInicioMin ?>" max="<?= $dataInicioMax ?>"></label>
                            <label style="font-size:12px; color:var(--text-muted);">Término <input form="<?= $formId ?>" type="date" name="data_termino" value="<?= htmlspecialchars($d['data_termino']) ?>"></label>
                            <label style="font-size:12px; color:var(--text-muted);">Nº <input form="<?= $formId ?>" type="text" name="numero" value="<?= htmlspecialchars($d['numero'] ?? '') ?>" style="width:70px;"></label>
                            <label style="font-size:12px; color:var(--text-muted);">Motivo <input form="<?= $formId ?>" type="text" name="motivo" value="<?= htmlspecialchars($d['motivo']) ?>" style="min-width:180px;"></label>
                        </div>
                        <div class="form-linha" style="padding:0 0 10px;">
                            <span style="font-size:12px; color:var(--text-muted);">Dispensado de:</span>
                            <?php foreach ($tiposDispensa as $t): ?>
                                <label class="tag-check"><input form="<?= $formId ?>" type="checkbox" name="dispensa_tipo_ids[]" value="<?= $t['id'] ?>" <?= in_array($t['id'], $tagsIdsAtuais) ? 'checked' : '' ?>> <?= htmlspecialchars($t['nome']) ?></label>
                            <?php endforeach; ?>
                            <input form="<?= $formId ?>" type="text" name="dispensado_de" value="<?= htmlspecialchars($d['dispensado_de'] ?? '') ?>" placeholder="Específico (opcional)" style="min-width:180px;">
                            <button type="submit" form="<?= $formId ?>">Salvar</button>
                        </div>
                    </td>
                </tr>
                <form id="<?= $formId ?>" method="post">
                    <input type="hidden" name="acao" value="atualizar">
                    <input type="hidden" name="id" value="<?= $d['id'] ?>">
                </form>
                <form id="form_excluir_<?= $d['id'] ?>" method="post">
                    <input type="hidden" name="acao" value="excluir">
                    <input type="hidden" name="id" value="<?= $d['id'] ?>">
                </form>
            <?php endforeach; ?>
            <?php if (empty($dispensas)): ?>
                <tr><td colspan="3" style="color:var(--text-muted);">Nenhuma dispensa cadastrada ainda.</td></tr>
            <?php endif; ?>
        </table>
        </div>
        <script>
            function alternarDetalheDispensa(id, botao) {
                const linha = document.getElementById(id);
                linha.hidden = !linha.hidden;
                botao.querySelector('.rotulo-abrir').textContent = linha.hidden ? 'Abrir' : 'Fechar';
            }
        </script>
    </div>
</div>

<?php include __DIR__ . '/../../core/rodape_versao.php'; ?>
</body>
</html>
<?php mysqli_close($conexao); ?>
