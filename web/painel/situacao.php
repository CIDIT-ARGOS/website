<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../core/situacao_core.php';
require_once __DIR__ . '/../../core/alunos_core.php';

$conexao = conectarBanco();
$escopo = escopoEsquadrao();

$filtros = [
    'esquadrao' => $escopo ?? ($_GET['esquadrao'] ?? null),
    'esquadrilha' => $_GET['esquadrilha'] ?? null,
    'busca' => $_GET['busca'] ?? null,
    'somente_ausentes' => isset($_GET['somente_ausentes']),
];

$resumo = situacaoResumo($conexao, $filtros);
$alunos = situacaoAtualAlunos($conexao, $filtros);

$esquadroesDisponiveis = $escopo === null ? listarEsquadroesDistintos($conexao) : [];

$tiposRetirada = ['1_jornada' => '1ª Jornada', '2_jornada' => '2ª Jornada', 'educacao_fisica' => 'Educação Física', 'pernoite' => 'Pernoite'];

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Painel — Situação do efetivo</title>
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
    .filtros { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .filtros label.check { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-muted); }
    .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
    .kpi { background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 14px; text-align: center; }
    .kpi .valor { font-size: 26px; font-weight: 700; }
    .kpi .rotulo { color: var(--text-muted); font-size: 12px; margin-top: 4px; }
    .badge { font-size: 11px; padding: 2px 8px; border-radius: 999px; }
    .badge.presente { background: #d9f2e3; color: var(--ok); }
    .badge.ausente { background: #fbe0e0; color: var(--danger); }
    .badge.sem-registro { background: #eef1f6; color: var(--text-muted); }
    .i { display: inline-block; width: 13px; height: 13px; vertical-align: -2px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 4px; }
</style>
</head>
<body>

<div class="topbar">
    <div><strong>ARGOS</strong> <a href="index.php"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span>painel</a></div>
    <div><a href="index.php?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a></div>
</div>

<div class="container">
    <h2>Situação do efetivo <?= $escopo ? '— Esquadrão ' . htmlspecialchars($escopo) : '' ?></h2>
    <p style="color: var(--text-muted); font-size: 13px; margin-top: -8px;">
        Onde cada aluno está agora, a partir da última chamada enviada em que apareceu. Não é ida/volta em tempo real — é o retrato mais recente que o sistema tem.
    </p>

    <div class="card">
        <div class="kpis">
            <div class="kpi"><div class="valor"><?= $resumo['total_efetivo'] ?></div><div class="rotulo">Efetivo</div></div>
            <div class="kpi"><div class="valor" style="color:var(--ok)"><?= $resumo['presentes'] ?></div><div class="rotulo">Presentes</div></div>
            <div class="kpi"><div class="valor" style="color:var(--danger)"><?= $resumo['ausentes'] ?></div><div class="rotulo">Ausentes</div></div>
            <div class="kpi"><div class="valor" style="color:var(--text-muted)"><?= $resumo['sem_registro'] ?></div><div class="rotulo">Sem registro ainda</div></div>
        </div>
    </div>

    <?php if (!empty($resumo['por_motivo'])): ?>
    <div class="card">
        <h4 style="margin-top:0;">Ausências por motivo (agora)</h4>
        <div class="scroll-x">
        <table>
            <tr><th>Motivo</th><th>Quantidade</th></tr>
            <?php foreach ($resumo['por_motivo'] as $motivo => $qtd): ?>
                <tr><td><?= htmlspecialchars($motivo) ?></td><td><?= $qtd ?></td></tr>
            <?php endforeach; ?>
        </table>
        </div>
    </div>
    <?php endif; ?>

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
            <input type="text" name="busca" placeholder="Nome ou milhão" value="<?= htmlspecialchars($_GET['busca'] ?? '') ?>">
            <label class="check"><input type="checkbox" name="somente_ausentes" value="1" <?= isset($_GET['somente_ausentes']) ? 'checked' : '' ?>> Só ausentes</label>
            <button type="submit"><span class="i" style="--icon-url:url('../images/icons/filter.svg')"></span>Filtrar</button>
        </form>
    </div>

    <div class="card">
        <p style="color: var(--text-muted); font-size: 13px;"><?= count($alunos) ?> aluno(s) encontrado(s).</p>
        <div class="scroll-x">
        <table>
            <tr>
                <th>Identificação</th><th>Esquadrão/Esquadrilha</th>
                <th>Status</th><th>Motivo</th><th>Última chamada</th>
            </tr>
            <?php foreach ($alunos as $a): ?>
                <?php
                    if ($a['presente'] === null) {
                        $statusClasse = 'sem-registro';
                        $statusRotulo = 'sem registro';
                    } elseif ((int) $a['presente'] === 1) {
                        $statusClasse = 'presente';
                        $statusRotulo = 'presente';
                    } else {
                        $statusClasse = 'ausente';
                        $statusRotulo = 'ausente';
                    }
                ?>
                <tr>
                    <td><?= htmlspecialchars(identificacaoAluno($a)) ?></td>
                    <td><?= htmlspecialchars($a['esquadrao']) ?> / <?= htmlspecialchars($a['esquadrilha']) ?></td>
                    <td><span class="badge <?= $statusClasse ?>"><?= $statusRotulo ?></span></td>
                    <td><?= htmlspecialchars($a['motivo_codigo'] ?? $a['motivo_nome'] ?? '—') ?></td>
                    <td>
                        <?php if ($a['retirada_data_hora']): ?>
                            <?= htmlspecialchars($tiposRetirada[$a['retirada_tipo']] ?? $a['retirada_tipo']) ?> — <?= htmlspecialchars($a['retirada_data_hora']) ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
    </div>
</div>

</body>
</html>
<?php mysqli_close($conexao); ?>
