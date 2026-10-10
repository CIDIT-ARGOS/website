<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../core/livro_core.php';

$conexao = conectarBanco();
$escopo = escopoEsquadrao();

$data = $_GET['data'] ?? date('Y-m-d');
$esquadraoEscolhido = $escopo ?? ($_GET['esquadrao'] ?? 'todos');


$esquadroesDisponiveis = $escopo === null ? listarEsquadroesDistintos($conexao) : [$escopo];
$esquadroesParaMontar = ($escopo === null && $esquadraoEscolhido === 'todos')
    ? $esquadroesDisponiveis
    : [$escopo ?? $esquadraoEscolhido];

$livros = [];
foreach ($esquadroesParaMontar as $esq) {
    $livros[] = montarLivroEsquadrao($conexao, $esq, $data);
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Painel — Livro do Dia</title>
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
    input, select { padding: 8px 10px; background: #ffffff; border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 13px; }
    select { max-width: 100%; }
    button { padding: 9px 16px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; }
    .filtros { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .scroll-x { overflow-x: auto; min-width: 0; }
    table { border-collapse: collapse; width: 100%; margin-top: 8px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 6px 10px; text-align: left; }
    th { background: var(--azul-eear); color: #ffffff; }
    .vazio { color: var(--text-muted); font-size: 13px; padding: 8px 0; }

    .livro { background: #fff; border: 1px solid var(--border); border-radius: 10px; padding: 32px; margin-bottom: 24px; }
    .livro h1 { text-align: center; font-size: 15px; margin: 0 0 4px; }
    .livro .subtitulo { text-align: center; font-size: 13px; color: var(--text-muted); margin: 0 0 4px; }
    .livro h2.esquadrao { text-align: center; font-size: 14px; margin: 16px 0; }
    .livro h3.secao { font-size: 13px; text-transform: uppercase; border-bottom: 1px solid var(--border); padding-bottom: 4px; margin: 20px 0 8px; }
    .livro p { font-size: 13px; margin: 4px 0; }
    .livro p.sem-chamada { color: var(--text-muted); font-style: italic; }
    .livro ul { margin: 4px 0; padding-left: 20px; font-size: 13px; }
    .dispensa-bloco { font-size: 13px; margin-bottom: 12px; }
    .dispensa-bloco strong { display: block; }
    .i { display: inline-block; width: 16px; height: 16px; vertical-align: -3px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 5px; }

    @media print {
        body { background: #fff; }
        .topbar, .card.filtros-card { display: none !important; }
        .livro { border: none; box-shadow: none; padding: 0; margin: 0 0 40px; page-break-after: always; }
        .container { max-width: 100%; padding: 0; }
    }
</style>
<link rel="stylesheet" href="../css/argos-admin.css">
<script src="../js/argos-admin.js" defer></script>
</head>
<body>

<div class="topbar">
    <div><strong><span class="marca-argos" role="img" aria-label="Argos">ARG<i class="olho"></i>S</span></strong> <a href="index.php"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span>painel</a></div>
    <div>
        <button onclick="window.print()"><span class="i" style="--icon-url:url('../images/icons/printer.svg')"></span>Baixar PDF</button>
        <a href="index.php?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a>
    </div>
</div>

<div class="container">
    <div class="card filtros-card">
        <h2 style="margin-top:0;">Livro do Dia <?= $escopo ? '— Esquadrão ' . htmlspecialchars($escopo) : '' ?></h2>
        <form method="get" class="filtros">
            <label style="font-size:12px; color:var(--text-muted);">Data <input type="date" name="data" value="<?= htmlspecialchars($data) ?>"></label>
            <?php if ($escopo === null): ?>
                <select name="esquadrao">
                    <option value="todos" <?= $esquadraoEscolhido === 'todos' ? 'selected' : '' ?>>Todos os esquadrões</option>
                    <?php foreach ($esquadroesDisponiveis as $e): ?>
                        <option value="<?= htmlspecialchars($e) ?>" <?= $esquadraoEscolhido === $e ? 'selected' : '' ?>><?= htmlspecialchars($e) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <button type="submit"><span class="i" style="--icon-url:url('../images/icons/refresh.svg')"></span>Gerar</button>
        </form>
    </div>

    <?php foreach ($livros as $livro): ?>
        <div class="livro">
            <h1>COMANDO DA AERONÁUTICA</h1>
            <p class="subtitulo">ESCOLA DE ESPECIALISTAS DE AERONÁUTICA</p>
            <p class="subtitulo">CORPO DE ALUNOS</p>
            <h2 class="esquadrao"><?= htmlspecialchars(rotuloEsquadrao($livro['esquadrao'])) ?></h2>
            <p style="text-align:center;">Resumo do dia <?= htmlspecialchars(dataEstiloLivro($data)) ?> — gerado pelo Argos</p>

            <?php foreach ($livro['secoes'] as $secao): ?>
                <h3 class="secao"><?= htmlspecialchars($secao['rotulo']) ?></h3>
                <?php $faltas = $secao['ausencias']; ?>
                <?php if ($secao['chamadas_enviadas'] === 0): ?>
                    <p class="sem-chamada">Chamada não enviada.</p>
                <?php elseif (empty($faltas)): ?>
                    <p>Não há.</p>
                <?php else: ?>
                    <ul>
                        <?php foreach ($faltas as $f): ?>
                            <li>
                                <?= htmlspecialchars(identificacaoAluno($f)) ?> — <?= htmlspecialchars(descricaoSituacaoLivro($f)) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endforeach; ?>

            <h3 class="secao">OCORRÊNCIAS MÉDICAS — DISPENSA MÉDICA</h3>
            <?php if (empty($livro['dispensas'])): ?>
                <p>Não há.</p>
            <?php else: ?>
                <?php foreach ($livro['dispensas'] as $d): ?>
                    <div class="dispensa-bloco">
                        <strong><?= htmlspecialchars(identificacaoAluno($d)) ?></strong>
                        <span>INÍCIO: <?= htmlspecialchars(dataEstiloLivro($d['data_inicio'])) ?></span><br>
                        <span>TÉRMINO: <?= htmlspecialchars(dataEstiloLivro($d['data_termino'])) ?></span><br>
                        <?php if ($d['numero']): ?><span>Nº DA DISPENSA: <?= htmlspecialchars($d['numero']) ?></span><br><?php endif; ?>
                        <span>MOTIVO: <?= htmlspecialchars($d['motivo']) ?></span><br>
                        <?php if (!empty($d['medico_responsavel'])): ?><span>OFICIAL MÉDICO: <?= htmlspecialchars($d['medico_responsavel']) ?></span><br><?php endif; ?>
                        <?php
                            $dispensadoDePartes = array_filter([$d['tags_nomes'] ?? null, $d['dispensado_de'] ?? null]);
                        ?>
                        <?php if (!empty($dispensadoDePartes)): ?><span>DISPENSADO DE: <?= htmlspecialchars(implode(', ', $dispensadoDePartes)) ?></span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php include __DIR__ . '/../../core/rodape_versao.php'; ?>
</body>
</html>
<?php mysqli_close($conexao); ?>
