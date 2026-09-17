<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../core/alunos_core.php';

$conexao = conectarBanco();
$escopo = escopoEsquadrao(); // null = CA inteiro, senão string do esquadrão

$filtros = [
    'esquadrao' => $escopo ?? ($_GET['esquadrao'] ?? null),
    'esquadrilha' => $_GET['esquadrilha'] ?? null,
    'especialidade' => $_GET['especialidade'] ?? null,
    'busca' => $_GET['busca'] ?? null,
    'com_posto_exibicao' => true,
    'order_by' => 'a.esquadrilha, a.nome_guerra',
];
$alunos = listarAlunos($conexao, $filtros);
$totalAtivos = contarAlunosAtivos($conexao, $escopo);
$quantitativoEsquadrao = quantitativoPorEsquadraoEsquadrilha($conexao, $escopo);
$quantitativoEspecialidade = quantitativoPorEspecialidade($conexao, $escopo);

// listas para os filtros (dentro do escopo)
$esquadroesDisponiveis = $escopo === null ? listarEsquadroesDistintos($conexao) : [];

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Painel — Efetivo</title>
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
    input, select { padding: 8px 10px; background: #ffffff; border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 13px; }
    button { padding: 8px 14px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 6px 10px; text-align: left; }
    th { background: var(--azul-eear); color: #ffffff; }
    .scroll-x { overflow-x: auto; min-width: 0; }
    .filtros { display: flex; gap: 10px; flex-wrap: wrap; }
    .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
    .kpi { background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 14px; text-align: center; }
    .kpi .valor { font-size: 26px; font-weight: 700; }
    .kpi .rotulo { color: var(--text-muted); font-size: 12px; margin-top: 4px; }
    .link-btn { color: var(--accent); text-decoration: none; font-size: 12px; }
    h3.subtitulo { font-size: 13px; text-transform: uppercase; letter-spacing: .03em; color: var(--text-muted); margin: 0 0 4px; }
    .badge-esq { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; border: 1px solid transparent; }
    .badge-esq.amarelo { background: #fdf1c8; color: #8a6100; border-color: #f0d98a; }
    .badge-esq.azul { background: #dbe8fd; color: #1d4ed8; border-color: #b6d0fa; }
    .badge-esq.branco { background: #eef1f6; color: #33404f; border-color: var(--border); }
    .badge-esq.prata { background: #e4e7ec; color: #33404f; border-color: #cfd4dc; }
    .badge-esq.verde { background: #d9f2e3; color: var(--ok); border-color: #b3e2c3; }
    .badge-esq.outro { background: #eef1f6; color: var(--text-muted); border-color: var(--border); }
    .badge-mf { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; margin-right: 4px; }
    .badge-mf.m { background: #dbe8fd; color: #1d4ed8; }
    .badge-mf.f { background: #fbe0ef; color: #be185d; }
    td.num, th.num { text-align: center; }
    tr.linha-total { background: var(--bg); font-weight: 600; }
    .i { display: inline-block; width: 13px; height: 13px; vertical-align: -2px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 4px; }
</style>
</head>
<body>

<div class="topbar">
    <div><strong>ARGOS</strong> <a href="index.php"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span>painel</a></div>
    <div><a href="index.php?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a></div>
</div>

<div class="container">
    <h2>Efetivo <?= $escopo ? '— Esquadrão ' . htmlspecialchars($escopo) : '(todos os esquadrões)' ?></h2>

    <div class="card">
        <div class="kpis">
            <div class="kpi"><div class="valor"><?= $totalAtivos ?></div><div class="rotulo">Total de alunos na ativa<?= $escopo ? '' : ' (todos os esquadrões)' ?></div></div>
        </div>
    </div>

    <div class="card">
        <h3 class="subtitulo">Quantitativo por esquadrão / esquadrilha</h3>
        <div class="scroll-x">
        <table>
            <tr>
                <th>Esquadrão</th>
                <?php foreach (ESQUADRILHAS_PADRAO as $letra): ?><th class="num"><?= $letra ?></th><?php endforeach; ?>
                <th class="num">Total</th>
                <th>M / F</th>
            </tr>
            <?php
                $totalGeralEsquadrilhas = array_fill_keys(ESQUADRILHAS_PADRAO, 0);
                $totalGeralM = 0;
                $totalGeralF = 0;
            ?>
            <?php foreach ($quantitativoEsquadrao as $esq => $linha): ?>
                <?php
                    $classeCor = ['AMARELO' => 'amarelo', 'AZUL' => 'azul', 'BRANCO' => 'branco', 'PRATA' => 'prata', 'VERDE' => 'verde'][$esq] ?? 'outro';
                    foreach (ESQUADRILHAS_PADRAO as $letra) { $totalGeralEsquadrilhas[$letra] += $linha['esquadrilhas'][$letra]; }
                    $totalGeralM += $linha['m'];
                    $totalGeralF += $linha['f'];
                ?>
                <tr>
                    <td><span class="badge-esq <?= $classeCor ?>"><?= htmlspecialchars($esq) ?></span></td>
                    <?php foreach (ESQUADRILHAS_PADRAO as $letra): ?><td class="num"><?= $linha['esquadrilhas'][$letra] ?></td><?php endforeach; ?>
                    <td class="num"><strong><?= $linha['total'] ?></strong></td>
                    <td><span class="badge-mf m">M <?= $linha['m'] ?></span><span class="badge-mf f">F <?= $linha['f'] ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($quantitativoEsquadrao)): ?>
                <tr><td colspan="7" style="color: var(--text-muted);">Nenhum aluno ativo.</td></tr>
            <?php else: ?>
                <tr class="linha-total">
                    <td>Total</td>
                    <?php foreach (ESQUADRILHAS_PADRAO as $letra): ?><td class="num"><?= $totalGeralEsquadrilhas[$letra] ?></td><?php endforeach; ?>
                    <td class="num"><?= $totalAtivos ?></td>
                    <td><span class="badge-mf m">M <?= $totalGeralM ?></span><span class="badge-mf f">F <?= $totalGeralF ?></span></td>
                </tr>
            <?php endif; ?>
        </table>
        </div>

        <h3 class="subtitulo" style="margin-top:18px;">Quantitativo por especialidade</h3>
        <div class="scroll-x">
        <table>
            <tr><th>Especialidade</th><th class="num">Total</th></tr>
            <?php foreach ($quantitativoEspecialidade as $e): ?>
                <tr><td><?= htmlspecialchars($e['especialidade']) ?></td><td class="num"><?= $e['total'] ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($quantitativoEspecialidade)): ?>
                <tr><td colspan="2" style="color: var(--text-muted);">Nenhum aluno ativo.</td></tr>
            <?php endif; ?>
        </table>
        </div>
    </div>

    <div class="card">
        <form method="get" class="filtros">
            <?php if ($escopo === null): ?>
                <select name="esquadrao">
                    <option value="">Todos os esquadrões</option>
                    <?php foreach ($esquadroesDisponiveis as $e): ?>
                        <option value="<?= htmlspecialchars($e) ?>" <?= ($_GET['esquadrao'] ?? '') === $e ? 'selected' : '' ?>><?= htmlspecialchars($e) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <input type="text" name="esquadrilha" placeholder="Esquadrilha (A/B/C/D)" value="<?= htmlspecialchars($_GET['esquadrilha'] ?? '') ?>">
            <input type="text" name="especialidade" placeholder="Especialidade (SIN, BET...)" value="<?= htmlspecialchars($_GET['especialidade'] ?? '') ?>">
            <input type="text" name="busca" placeholder="Nome ou milhão" value="<?= htmlspecialchars($_GET['busca'] ?? '') ?>">
            <button type="submit"><span class="i" style="--icon-url:url('../images/icons/filter.svg')"></span>Filtrar</button>
        </form>
    </div>

    <div class="card">
        <p style="color: var(--text-muted); font-size: 13px;"><?= count($alunos) ?> aluno(s) encontrado(s).</p>
        <div class="scroll-x">
        <table>
            <tr>
                <th>Posto</th><th>Nome de Guerra</th><th>Sexo</th><th>Milhão</th>
                <th>Esquadrão</th><th>Esquadrilha</th><th>Especialidade</th><th>Curso/Série</th>
                <?php if (podeEditarEfetivo()): ?><th>Ações</th><?php endif; ?>
            </tr>
            <?php foreach ($alunos as $a): ?>
                <tr>
                    <td><?= htmlspecialchars($a['posto_exibicao']) ?></td>
                    <td><?= htmlspecialchars($a['nome_guerra']) ?></td>
                    <td><?= htmlspecialchars($a['sexo']) ?></td>
                    <td><?= htmlspecialchars($a['milhao']) ?></td>
                    <td><?= htmlspecialchars($a['esquadrao']) ?></td>
                    <td><?= htmlspecialchars($a['esquadrilha']) ?></td>
                    <td><?= htmlspecialchars($a['especialidade'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($a['curso']) ?> / <?= htmlspecialchars($a['serie']) ?></td>
                    <?php if (podeEditarEfetivo()): ?>
                        <td><a href="efetivo_editar.php?id=<?= $a['id'] ?>" class="link-btn"><span class="i" style="--icon-url:url('../images/icons/pencil.svg')"></span>editar</a></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../core/rodape_versao.php'; ?>
</body>
</html>
<?php mysqli_close($conexao); ?>
