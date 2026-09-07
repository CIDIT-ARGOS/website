<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';

$conexao = conectarBanco();
$escopo = escopoEsquadrao();

$dataInicio = $_GET['data_inicio'] ?? date('Y-m-01');
$dataFim = $_GET['data_fim'] ?? date('Y-m-d');
$tipo = $_GET['tipo'] ?? '';
$esquadraoFiltro = $_GET['esquadrao'] ?? '';

$filtros = ["r.data_hora BETWEEN ? AND ?"];
$params = [$dataInicio . " 00:00:00", $dataFim . " 23:59:59"];
$tipos = "ss";

if ($escopo !== null) {
    $filtros[] = "a.esquadrao = ?";
    $params[] = $escopo;
    $tipos .= "s";
} elseif ($esquadraoFiltro !== '') {
    $filtros[] = "a.esquadrao = ?";
    $params[] = $esquadraoFiltro;
    $tipos .= "s";
}

if ($tipo !== '') {
    $filtros[] = "r.tipo = ?";
    $params[] = $tipo;
    $tipos .= "s";
}

$where = "WHERE " . implode(" AND ", $filtros);

// ---------- Resumo geral ----------
$sqlResumo = "
    SELECT
        COUNT(*) as total_itens,
        SUM(ri.presente) as total_presentes,
        SUM(1 - ri.presente) as total_faltas
    FROM retirada_itens ri
    JOIN retiradas r ON r.id = ri.retirada_id
    JOIN alunos a ON a.id = ri.aluno_id
    $where
";
$stmt = mysqli_prepare($conexao, $sqlResumo);
mysqli_stmt_bind_param($stmt, $tipos, ...$params);
mysqli_stmt_execute($stmt);
$resumo = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

$totalItens = (int)($resumo['total_itens'] ?? 0);
$totalPresentes = (int)($resumo['total_presentes'] ?? 0);
$totalFaltas = (int)($resumo['total_faltas'] ?? 0);
$percentualPresenca = $totalItens > 0 ? round($totalPresentes / $totalItens * 100, 1) : null;

// ---------- Faltas por motivo ----------
$sqlMotivos = "
    SELECT COALESCE(m.nome, 'Sem motivo') as motivo, COUNT(*) as total
    FROM retirada_itens ri
    JOIN retiradas r ON r.id = ri.retirada_id
    JOIN alunos a ON a.id = ri.aluno_id
    LEFT JOIN motivos_falta m ON m.id = ri.motivo_falta_id
    $where AND ri.presente = 0
    GROUP BY motivo
    ORDER BY total DESC
";
$stmt = mysqli_prepare($conexao, $sqlMotivos);
mysqli_stmt_bind_param($stmt, $tipos, ...$params);
mysqli_stmt_execute($stmt);
$porMotivo = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

// ---------- Retiradas no período ----------
$sqlRetiradas = "
    SELECT
        r.id, r.tipo, r.agrupamento_tipo, r.agrupamento_valor, r.status, r.protocolo, r.data_hora,
        COUNT(ri.id) as total_itens,
        SUM(ri.presente) as presentes,
        SUM(1 - ri.presente) as faltas
    FROM retiradas r
    JOIN retirada_itens ri ON ri.retirada_id = r.id
    JOIN alunos a ON a.id = ri.aluno_id
    $where
    GROUP BY r.id
    ORDER BY r.data_hora DESC
";
$stmt = mysqli_prepare($conexao, $sqlRetiradas);
mysqli_stmt_bind_param($stmt, $tipos, ...$params);
mysqli_stmt_execute($stmt);
$retiradas = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

$esquadroesDisponiveis = [];
if ($escopo === null) {
    $r = mysqli_query($conexao, "SELECT DISTINCT esquadrao FROM alunos WHERE ativo = 1 ORDER BY esquadrao");
    while ($l = mysqli_fetch_assoc($r)) $esquadroesDisponiveis[] = $l['esquadrao'];
}

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
    :root { --bg: #0d1117; --bg-card: #161b22; --border: #30363d; --text: #e6edf3; --text-muted: #8b949e; --accent: #4f8cff; --ok: #4caf7d; --danger: #ff5f5f; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); overflow-x: hidden; }
    .topbar { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid var(--border); }
    .topbar a { color: var(--text-muted); text-decoration: none; font-size: 13px; margin-left: 16px; }
    .container { padding: 24px; max-width: 1200px; margin: 0 auto; }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
    input, select { padding: 8px 10px; background: #0d1117; border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 13px; }
    button { padding: 8px 14px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; }
    .filtros { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
    .kpi { background: #0d1117; border: 1px solid var(--border); border-radius: 8px; padding: 14px; text-align: center; }
    .kpi .valor { font-size: 26px; font-weight: 700; }
    .kpi .rotulo { color: var(--text-muted); font-size: 12px; margin-top: 4px; }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 6px 10px; text-align: left; }
    th { background: #1c2128; color: var(--text-muted); }
    .scroll-x { overflow-x: auto; min-width: 0; }
    .vazio { color: var(--text-muted); font-size: 13px; padding: 20px 0; text-align: center; }
</style>
</head>
<body>

<div class="topbar">
    <div><strong>ARGOS</strong> <a href="index.php">← painel</a></div>
    <div><a href="index.php?logout=1">sair</a></div>
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
            <button type="submit">Filtrar</button>
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
                <tr><th>Data/Hora</th><th>Tipo</th><th>Agrupamento</th><th>Status</th><th>Protocolo</th><th>Presentes</th><th>Faltas</th></tr>
                <?php foreach ($retiradas as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['data_hora']) ?></td>
                        <td><?= htmlspecialchars($tiposRetirada[$r['tipo']] ?? $r['tipo']) ?></td>
                        <td><?= htmlspecialchars($r['agrupamento_tipo']) ?>: <?= htmlspecialchars($r['agrupamento_valor']) ?></td>
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
