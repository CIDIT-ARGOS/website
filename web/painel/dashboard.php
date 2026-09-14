<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../core/retiradas_core.php';
require_once __DIR__ . '/../../core/situacao_core.php';
require_once __DIR__ . '/../../core/dispensas_core.php';
require_once __DIR__ . '/../../core/alunos_core.php';

$conexao = conectarBanco();
$escopo = escopoEsquadrao();

$dataInicio = $_GET['data_inicio'] ?? date('Y-m-d', strtotime('-30 days'));
$dataFim = $_GET['data_fim'] ?? date('Y-m-d');
$esquadraoFiltro = $escopo ?? ($_GET['esquadrao'] ?? '');

$filtros = [
    'data_inicio' => $dataInicio,
    'data_fim' => $dataFim,
    'esquadrao' => $esquadraoFiltro ?: null,
];

$totalAtivos = contarAlunosAtivos($conexao, $escopo);
$situacaoAgora = situacaoResumo($conexao, ['esquadrao' => $escopo]);
$dispensasAtivas = count(listarDispensas($conexao, array_filter([
    'esquadrao' => $escopo,
    'ativas_em' => date('Y-m-d'),
])));

$resumoPeriodo = relatorioResumo($conexao, $filtros);
$porClassificacao = relatorioPorClassificacao($conexao, $filtros);
$porMotivo = relatorioPorMotivo($conexao, $filtros);
$evolucao = evolucaoDiariaPresenca($conexao, $filtros);
$porAgrupamento = $escopo === null
    ? relatorioPorEsquadrao($conexao, $filtros)
    : relatorioPorEsquadrilha($conexao, $filtros);

$faltasReais = 0;
$ausenciasJustificadas = 0;
foreach ($porClassificacao as $c) {
    if ($c['classificacao'] === 'falta') {
        $faltasReais = (int) $c['total'];
    } else {
        $ausenciasJustificadas += (int) $c['total'];
    }
}

$esquadroesDisponiveis = $escopo === null ? listarEsquadroesDistintos($conexao) : [];

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Painel Argos</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<style>
    :root {
        --bg: #f4f7fc; --bg-card: #ffffff; --border: #dbe3ef;
        --text: #16202e; --text-muted: #55637a; --accent: #2f6fed;
        --azul-eear: #0a2e5c; --danger: #c0392b; --ok: #1f8a4c; --alerta: #b8860b;
    }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); overflow-x: hidden; }
    .topbar { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid var(--border); }
    .topbar a { color: var(--text-muted); text-decoration: none; font-size: 13px; margin-left: 16px; }
    .container { padding: 24px; max-width: 1200px; margin: 0 auto; }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
    input, select { padding: 8px 10px; background: #ffffff; border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 13px; }
    button { padding: 9px 16px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; }
    .filtros { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
    .kpi { background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 14px; text-align: center; }
    .kpi .valor { font-size: 26px; font-weight: 700; }
    .kpi .rotulo { color: var(--text-muted); font-size: 12px; margin-top: 4px; }
    .graficos { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 16px; }
    .grafico-wrap { position: relative; height: 260px; }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 6px 10px; text-align: left; }
    th { background: var(--azul-eear); color: #ffffff; }
    .scroll-x { overflow-x: auto; min-width: 0; }
    .vazio { color: var(--text-muted); font-size: 13px; padding: 20px 0; text-align: center; }
    .i { display: inline-block; width: 13px; height: 13px; vertical-align: -2px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 4px; }

    @media print {
        body { background: #fff; }
        .topbar, .filtros-card { display: none !important; }
        .card { border: none; box-shadow: none; page-break-inside: avoid; }
        .container { max-width: 100%; padding: 0; }
    }
</style>
</head>
<body>

<div class="topbar">
    <div><strong>ARGOS</strong> <a href="index.php"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span>painel</a></div>
    <div>
        <button onclick="window.print()">Baixar PDF</button>
        <a href="index.php?logout=1">sair</a>
    </div>
</div>

<div class="container">
    <h2>Painel Argos <?= $escopo ? '— Esquadrão ' . htmlspecialchars($escopo) : '' ?></h2>
    <p style="color: var(--text-muted); font-size: 13px; margin-top: -8px;">
        Visão geral do Corpo de Alunos — quantidades, tendências e distribuição, com dados reais do período selecionado.
    </p>

    <div class="card filtros-card">
        <form method="get" class="filtros">
            <label style="font-size:12px; color:var(--text-muted);">De <input type="date" name="data_inicio" value="<?= htmlspecialchars($dataInicio) ?>"></label>
            <label style="font-size:12px; color:var(--text-muted);">Até <input type="date" name="data_fim" value="<?= htmlspecialchars($dataFim) ?>"></label>
            <?php if ($escopo === null): ?>
                <select name="esquadrao">
                    <option value="">Todos os esquadrões</option>
                    <?php foreach ($esquadroesDisponiveis as $e): ?>
                        <option value="<?= htmlspecialchars($e) ?>" <?= $esquadraoFiltro === $e ? 'selected' : '' ?>><?= htmlspecialchars($e) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <button type="submit"><span class="i" style="--icon-url:url('../images/icons/filter.svg')"></span>Filtrar</button>
        </form>
    </div>

    <div class="card">
        <div class="kpis">
            <div class="kpi"><div class="valor"><?= $totalAtivos ?></div><div class="rotulo">Efetivo total</div></div>
            <div class="kpi"><div class="valor" style="color:var(--ok)"><?= $situacaoAgora['presentes'] ?></div><div class="rotulo">Presentes agora</div></div>
            <div class="kpi"><div class="valor" style="color:var(--danger)"><?= $faltasReais ?></div><div class="rotulo">Faltas reais no período</div></div>
            <div class="kpi"><div class="valor" style="color:var(--alerta)"><?= $ausenciasJustificadas ?></div><div class="rotulo">Ausências justificadas no período</div></div>
            <div class="kpi"><div class="valor"><?= $dispensasAtivas ?></div><div class="rotulo">Dispensas médicas ativas agora</div></div>
            <div class="kpi"><div class="valor"><?= $resumoPeriodo['percentual_presenca'] !== null ? $resumoPeriodo['percentual_presenca'] . '%' : '—' ?></div><div class="rotulo">Taxa de presença no período</div></div>
        </div>
    </div>

    <div class="graficos">
        <div class="card">
            <h4 style="margin-top:0;">Ausências no período: falta x justificada</h4>
            <?php if ($faltasReais + $ausenciasJustificadas === 0): ?>
                <p class="vazio">Nenhuma ausência registrada no período.</p>
            <?php else: ?>
                <div class="grafico-wrap"><canvas id="graficoClassificacao"></canvas></div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h4 style="margin-top:0;">Faltas por <?= $escopo === null ? 'esquadrão' : 'esquadrilha' ?></h4>
            <?php if (empty($porAgrupamento)): ?>
                <p class="vazio">Sem registros no período.</p>
            <?php else: ?>
                <div class="grafico-wrap"><canvas id="graficoAgrupamento"></canvas></div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h4 style="margin-top:0;">Taxa de presença por dia</h4>
            <?php if (empty($evolucao)): ?>
                <p class="vazio">Sem registros no período.</p>
            <?php else: ?>
                <div class="grafico-wrap"><canvas id="graficoEvolucao"></canvas></div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h4 style="margin-top:0;">Faltas por motivo</h4>
            <?php if (empty($porMotivo)): ?>
                <p class="vazio">Nenhuma falta registrada no período.</p>
            <?php else: ?>
                <div class="grafico-wrap"><canvas id="graficoMotivo"></canvas></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h4 style="margin-top:0;">Dados por trás dos gráficos</h4>
        <div class="scroll-x">
        <table>
            <tr><th>Motivo</th><th>Quantidade</th></tr>
            <?php foreach ($porMotivo as $m): ?>
                <tr><td><?= htmlspecialchars($m['motivo']) ?></td><td><?= $m['total'] ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($porMotivo)): ?>
                <tr><td colspan="2" style="color:var(--text-muted);">Nenhuma falta registrada no período.</td></tr>
            <?php endif; ?>
        </table>
        </div>
    </div>
</div>

<script>
    Chart.defaults.font.family = "'Segoe UI', system-ui, sans-serif";
    Chart.defaults.font.size = 12;

    <?php if ($faltasReais + $ausenciasJustificadas > 0): ?>
    new Chart(document.getElementById('graficoClassificacao'), {
        type: 'doughnut',
        data: {
            labels: ['Falta', 'Ausência justificada'],
            datasets: [{
                data: [<?= $faltasReais ?>, <?= $ausenciasJustificadas ?>],
                backgroundColor: ['#c0392b', '#e0b84c'],
            }],
        },
        options: { responsive: true, maintainAspectRatio: false },
    });
    <?php endif; ?>

    <?php if (!empty($porAgrupamento)): ?>
    new Chart(document.getElementById('graficoAgrupamento'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_map(fn($p) => $p[$escopo === null ? 'esquadrao' : 'esquadrilha'], $porAgrupamento), JSON_UNESCAPED_UNICODE) ?>,
            datasets: [{
                label: 'Faltas',
                data: <?= json_encode(array_map(fn($p) => (int) $p['faltas'], $porAgrupamento)) ?>,
                backgroundColor: '#2f6fed',
            }],
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
    });
    <?php endif; ?>

    <?php if (!empty($evolucao)): ?>
    new Chart(document.getElementById('graficoEvolucao'), {
        type: 'line',
        data: {
            labels: <?= json_encode(array_map(fn($e) => date('d/m', strtotime($e['dia'])), $evolucao)) ?>,
            datasets: [{
                label: 'Taxa de presença (%)',
                data: <?= json_encode(array_map(fn($e) => $e['total_itens'] > 0 ? round($e['presentes'] / $e['total_itens'] * 100, 1) : 0, $evolucao)) ?>,
                borderColor: '#1f8a4c',
                backgroundColor: 'rgba(31,138,76,0.15)',
                fill: true,
                tension: 0.3,
            }],
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, max: 100 } } },
    });
    <?php endif; ?>

    <?php if (!empty($porMotivo)): ?>
    new Chart(document.getElementById('graficoMotivo'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_map(fn($m) => $m['motivo'], $porMotivo), JSON_UNESCAPED_UNICODE) ?>,
            datasets: [{
                label: 'Faltas',
                data: <?= json_encode(array_map(fn($m) => (int) $m['total'], $porMotivo)) ?>,
                backgroundColor: '#4f8cff',
            }],
        },
        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } },
    });
    <?php endif; ?>
</script>

</body>
</html>
<?php mysqli_close($conexao); ?>
