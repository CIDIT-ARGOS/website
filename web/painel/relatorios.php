<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../core/retiradas_core.php';
require_once __DIR__ . '/../../core/alunos_core.php';

$conexao = conectarBanco();
$escopo = escopoEsquadrao();

$dataInicio = $_GET['data_inicio'] ?? date('Y-m-01');
$dataFim = $_GET['data_fim'] ?? date('Y-m-d');
$tipo = $_GET['tipo'] ?? '';
$esquadraoFiltro = $_GET['esquadrao'] ?? '';

$filtrosRelatorio = [
    'data_inicio' => $dataInicio,
    'data_fim' => $dataFim,
    'tipo' => $tipo ?: null,
    'esquadrao' => $escopo ?? ($esquadraoFiltro ?: null),
];

$resumo = relatorioResumo($conexao, $filtrosRelatorio);
$totalItens = $resumo['total_itens'];
$totalPresentes = $resumo['total_presentes'];
$totalFaltas = $resumo['total_faltas'];
$percentualPresenca = $resumo['percentual_presenca'];

$porMotivo = relatorioPorMotivo($conexao, $filtrosRelatorio);
$retiradas = relatorioRetiradas($conexao, $filtrosRelatorio);

$esquadroesDisponiveis = $escopo === null ? listarEsquadroesDistintos($conexao) : [];

$tiposRetirada = ['1_jornada' => '1ª Jornada', '2_jornada' => '2ª Jornada', 'educacao_fisica' => 'Educação Física', 'pernoite' => 'Pernoite'];

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Painel — Relatório de retiradas</title>
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
    .filtros { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
    .kpi { background: #ffffff; border: 1px solid var(--border); border-radius: 8px; padding: 14px; text-align: center; }
    .kpi .valor { font-size: 26px; font-weight: 700; }
    .kpi .rotulo { color: var(--text-muted); font-size: 12px; margin-top: 4px; }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 6px 10px; text-align: left; }
    th { background: var(--azul-eear); color: #ffffff; }
    .scroll-x { overflow-x: auto; min-width: 0; }
    .vazio { color: var(--text-muted); font-size: 13px; padding: 20px 0; text-align: center; }
    .i { display: inline-block; width: 13px; height: 13px; vertical-align: -2px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 4px; }
</style>
</head>
<body>

<div class="topbar">
    <div><strong>ARGOS</strong> <a href="index.php"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span>painel</a></div>
    <div><a href="index.php?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a></div>
</div>

<div class="container">
    <h2>Relatório de retiradas <?= $escopo ? '— Esquadrão ' . htmlspecialchars($escopo) : '' ?></h2>

    <div class="card">
        <form method="get" class="filtros">
            <label style="font-size:12px; color:var(--text-muted);">De <input type="date" name="data_inicio" value="<?= htmlspecialchars($dataInicio) ?>"></label>
            <label style="font-size:12px; color:var(--text-muted);">Até <input type="date" name="data_fim" value="<?= htmlspecialchars($dataFim) ?>"></label>
            <select name="tipo">
                <option value="">Todos os tipos</option>
                <?php foreach ($tiposRetirada as $valor => $rotulo): ?>
                    <option value="<?= $valor ?>" <?= $tipo === $valor ? 'selected' : '' ?>><?= $rotulo ?></option>
                <?php endforeach; ?>
            </select>
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
            <div class="kpi"><div class="valor"><?= $totalItens ?></div><div class="rotulo">Registros no período</div></div>
            <div class="kpi"><div class="valor" style="color:var(--ok)"><?= $totalPresentes ?></div><div class="rotulo">Presenças</div></div>
            <div class="kpi"><div class="valor" style="color:var(--danger)"><?= $totalFaltas ?></div><div class="rotulo">Faltas</div></div>
            <div class="kpi"><div class="valor"><?= $percentualPresenca !== null ? $percentualPresenca . '%' : '—' ?></div><div class="rotulo">Taxa de presença</div></div>
        </div>
    </div>

    <div class="card">
        <h4>Faltas por motivo</h4>
        <?php if (empty($porMotivo)): ?>
            <p class="vazio">Nenhuma falta registrada no período selecionado.</p>
        <?php else: ?>
            <div class="scroll-x">
            <table>
                <tr><th>Motivo</th><th>Quantidade</th></tr>
                <?php foreach ($porMotivo as $m): ?>
                    <tr><td><?= htmlspecialchars($m['motivo']) ?></td><td><?= $m['total'] ?></td></tr>
                <?php endforeach; ?>
            </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h4>Retiradas (chamadas) no período</h4>
        <?php if (empty($retiradas)): ?>
            <p class="vazio">Nenhuma retirada registrada ainda neste período. Assim que o módulo de chamada estiver em uso, os registros aparecem aqui automaticamente.</p>
        <?php else: ?>
            <div class="scroll-x">
            <table>
                <tr><th>Data/Hora</th><th>Tipo</th><th>Agrupamento</th><th>Responsável</th><th>Status</th><th>Protocolo</th><th>Presentes</th><th>Faltas</th></tr>
                <?php foreach ($retiradas as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['data_hora']) ?></td>
                        <td><?= htmlspecialchars($tiposRetirada[$r['tipo']] ?? $r['tipo']) ?></td>
                        <td><?= htmlspecialchars($r['agrupamento_tipo']) ?>: <?= htmlspecialchars($r['agrupamento_valor']) ?></td>
                        <td><?= htmlspecialchars($r['responsavel_nome'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($r['status']) ?></td>
                        <td><?= htmlspecialchars($r['protocolo'] ?? '—') ?></td>
                        <td><?= $r['presentes'] ?></td>
                        <td><?= $r['faltas'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
<?php mysqli_close($conexao); ?>
