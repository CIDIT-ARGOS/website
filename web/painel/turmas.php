<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/acesso_negado.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../core/turmas_core.php';

if (!podeGerenciarTurmas()) {
    exibirAcessoNegado("Seu cargo não tem a permissão 'gerenciar_turmas'.");
}

$conexao = conectarBanco();

$mensagem = null;
$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'criar') {
        $resultado = criarTurma($conexao, $_POST);
        $mensagem = $resultado['ok'] ? "Turma cadastrada." : null;
        $erro = $resultado['ok'] ? null : $resultado['erro'];
    }

    if ($acao === 'atualizar') {
        $id = (int) $_POST['id'];
        $resultado = atualizarTurma($conexao, $id, $_POST);
        $mensagem = $resultado['ok'] ? "Turma atualizada." : null;
        $erro = $resultado['ok'] ? null : $resultado['erro'];
    }

    if ($acao === 'formar') {
        $id = (int) $_POST['id'];
        $resultado = formarTurma($conexao, $id);
        if ($resultado['ok']) {
            $mensagem = "Turma formada — " . $resultado['alunos_desativados'] . " aluno(s) desativado(s) junto.";
        } else {
            $erro = $resultado['erro'];
        }
    }
}

$mostrarInativas = isset($_GET['mostrar_inativas']);
$turmas = listarTurmas($conexao, $mostrarInativas);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Painel — Turmas</title>
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
    button.danger[disabled] { background: var(--border); color: var(--text-muted); cursor: not-allowed; }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 8px 10px; text-align: left; vertical-align: top; }
    th { background: var(--azul-eear); color: #ffffff; }
    .erro { color: var(--danger); font-size: 13px; }
    .ok { color: var(--ok); font-size: 13px; }
    .form-linha { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .scroll-x { overflow-x: auto; min-width: 0; }
    .badge { font-size: 11px; padding: 2px 8px; border-radius: 999px; }
    .badge.ativa { background: #d9f2e3; color: var(--ok); }
    .badge.formada { background: #eef1f6; color: var(--text-muted); }
    tr.inativa { opacity: 0.55; }
    .i { display: inline-block; width: 13px; height: 13px; vertical-align: -2px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 4px; }
</style>
</head>
<body>

<div class="topbar">
    <div><strong>ARGOS</strong> <a href="index.php"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span>painel</a></div>
    <div><a href="index.php?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a></div>
</div>

<div class="container">
    <h2>Turmas</h2>
    <p style="color: var(--text-muted); font-size: 13px; margin-top: -8px;">
        Cada leva de alunos que entra e vai se formar junto. Cadastre a turma vazia aqui e depois vincule os alunos a ela
        (pelo Efetivo, um a um, ou pela importação em lote). Quando a turma sai da escola, "Formar turma" desativa a turma
        e todos os alunos ainda ativos vinculados a ela, de uma vez só — sem apagar nada, como no resto do sistema.
    </p>

    <?php if ($erro): ?><p class="erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($mensagem): ?><p class="ok"><?= htmlspecialchars($mensagem) ?></p><?php endif; ?>

    <div class="card">
        <h4>Nova turma</h4>
        <form method="post" class="form-linha">
            <input type="hidden" name="acao" value="criar">
            <label style="font-size:12px; color:var(--text-muted); display:flex; flex-direction:column; gap:4px;">
                Nome (apelido)
                <input type="text" name="nome" placeholder="Ex: Fera Azul" required style="min-width:160px;">
            </label>
            <label style="font-size:12px; color:var(--text-muted); display:flex; flex-direction:column; gap:4px;">
                Curso
                <select name="curso">
                    <option value="CFS">CFS</option>
                    <option value="EAGS">EAGS</option>
                </select>
            </label>
            <label style="font-size:12px; color:var(--text-muted); display:flex; flex-direction:column; gap:4px;">
                Série (só CFS)
                <input type="text" name="serie" placeholder="1 a 4" style="width:70px;">
            </label>
            <label style="font-size:12px; color:var(--text-muted); display:flex; flex-direction:column; gap:4px;">
                Ingresso
                <span class="form-linha" style="gap:4px;">
                    <input type="number" name="ano_ingresso" placeholder="Ano" required style="width:80px;">
                    <select name="semestre_ingresso">
                        <option value="1">1º sem.</option>
                        <option value="2">2º sem.</option>
                    </select>
                </span>
            </label>
            <label style="font-size:12px; color:var(--text-muted); display:flex; flex-direction:column; gap:4px;">
                Formatura prevista
                <span class="form-linha" style="gap:4px;">
                    <input type="number" name="ano_formatura_previsto" placeholder="Ano" style="width:80px;">
                    <select name="semestre_formatura_previsto">
                        <option value="">—</option>
                        <option value="1">1º sem.</option>
                        <option value="2">2º sem.</option>
                    </select>
                </span>
            </label>
            <label style="font-size:12px; color:var(--text-muted); display:flex; flex-direction:column; gap:4px;">
                Esquadrão atual (opcional)
                <input type="text" name="esquadrao_atual" placeholder="Ex: Esquadrão Azul" style="min-width:140px;">
            </label>
            <button type="submit">Cadastrar</button>
        </form>
    </div>

    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
            <h4 style="margin:0;">Turmas (<?= count($turmas) ?>)</h4>
            <a href="?<?= $mostrarInativas ? '' : 'mostrar_inativas=1' ?>" style="font-size:12px; color:var(--accent);">
                <?= $mostrarInativas ? 'Ocultar formadas' : 'Mostrar formadas' ?>
            </a>
        </div>
        <div class="scroll-x">
        <table>
            <tr>
                <th>Nome</th><th>Curso/Série</th><th>Ingresso</th><th>Formatura prevista</th>
                <th>Esquadrão atual</th><th>Alunos ativos</th><th>Status</th><th>Ações</th>
            </tr>
            <?php foreach ($turmas as $t): ?>
                <?php
                    $formId = 'form_' . $t['id'];
                    $inativa = empty($t['ativo']);
                    $confirmacao = "Formar a turma \"{$t['nome']}\"? Isso desativa a turma e os {$t['alunos_ativos']} aluno(s) ativo(s) vinculados a ela de uma vez. Nada é apagado, mas todos saem do efetivo ativo imediatamente.";
                ?>
                <tr class="<?= $inativa ? 'inativa' : '' ?>">
                    <td><?= htmlspecialchars($t['nome']) ?></td>
                    <td><?= htmlspecialchars($t['curso']) ?><?= $t['serie'] ? ' / ' . htmlspecialchars($t['serie']) : '' ?></td>
                    <td><?= (int) $t['ano_ingresso'] ?>.<?= (int) $t['semestre_ingresso'] ?></td>
                    <td><?= $t['ano_formatura_previsto'] ? (int) $t['ano_formatura_previsto'] . '.' . (int) $t['semestre_formatura_previsto'] : '—' ?></td>
                    <td><?= htmlspecialchars($t['esquadrao_atual'] ?? '—') ?></td>
                    <td><?= (int) $t['alunos_ativos'] ?></td>
                    <td><span class="badge <?= $inativa ? 'formada' : 'ativa' ?>"><?= $inativa ? 'formada' : 'ativa' ?></span></td>
                    <td style="white-space:nowrap;">
                        <button type="button" onclick="alternarEdicaoTurma('<?= $formId ?>')">Editar</button>
                        <button type="button" class="danger" <?= $inativa ? 'disabled' : '' ?>
                            onclick="if(confirm(<?= htmlspecialchars(json_encode($confirmacao), ENT_QUOTES) ?>)) document.getElementById('form_formar_<?= $t['id'] ?>').submit();">
                            Formar turma
                        </button>
                    </td>
                </tr>
                <tr id="<?= $formId ?>_linha" hidden>
                    <td colspan="8">
                        <form id="<?= $formId ?>" method="post" class="form-linha" style="padding:10px 0;">
                            <input type="hidden" name="acao" value="atualizar">
                            <input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <input type="text" name="nome" value="<?= htmlspecialchars($t['nome']) ?>" required style="min-width:140px;">
                            <select name="curso">
                                <option value="CFS" <?= $t['curso'] === 'CFS' ? 'selected' : '' ?>>CFS</option>
                                <option value="EAGS" <?= $t['curso'] === 'EAGS' ? 'selected' : '' ?>>EAGS</option>
                            </select>
                            <input type="text" name="serie" value="<?= htmlspecialchars($t['serie'] ?? '') ?>" placeholder="Série" style="width:70px;">
                            <input type="number" name="ano_ingresso" value="<?= (int) $t['ano_ingresso'] ?>" style="width:80px;">
                            <select name="semestre_ingresso">
                                <option value="1" <?= (int) $t['semestre_ingresso'] === 1 ? 'selected' : '' ?>>1º sem.</option>
                                <option value="2" <?= (int) $t['semestre_ingresso'] === 2 ? 'selected' : '' ?>>2º sem.</option>
                            </select>
                            <input type="number" name="ano_formatura_previsto" value="<?= htmlspecialchars($t['ano_formatura_previsto'] ?? '') ?>" placeholder="Formatura" style="width:80px;">
                            <select name="semestre_formatura_previsto">
                                <option value="">—</option>
                                <option value="1" <?= (int) ($t['semestre_formatura_previsto'] ?? 0) === 1 ? 'selected' : '' ?>>1º sem.</option>
                                <option value="2" <?= (int) ($t['semestre_formatura_previsto'] ?? 0) === 2 ? 'selected' : '' ?>>2º sem.</option>
                            </select>
                            <input type="text" name="esquadrao_atual" value="<?= htmlspecialchars($t['esquadrao_atual'] ?? '') ?>" placeholder="Esquadrão atual" style="min-width:140px;">
                            <button type="submit">Salvar</button>
                        </form>
                    </td>
                </tr>
                <form id="form_formar_<?= $t['id'] ?>" method="post">
                    <input type="hidden" name="acao" value="formar">
                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                </form>
            <?php endforeach; ?>
            <?php if (empty($turmas)): ?>
                <tr><td colspan="8" style="color:var(--text-muted);">Nenhuma turma cadastrada ainda.</td></tr>
            <?php endif; ?>
        </table>
        </div>
        <script>
            function alternarEdicaoTurma(formId) {
                const linha = document.getElementById(formId + '_linha');
                linha.hidden = !linha.hidden;
            }
        </script>
    </div>
</div>

<?php include __DIR__ . '/../../core/rodape_versao.php'; ?>
</body>
</html>
<?php mysqli_close($conexao); ?>
