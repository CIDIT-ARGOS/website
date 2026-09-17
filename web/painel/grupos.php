<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/acesso_negado.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../core/grupos_core.php';
require_once __DIR__ . '/../../core/alunos_core.php';

$conexao = conectarBanco();

if (escopoEsquadrao() !== null) {
    exibirAcessoNegado("Grupos são geridos por cargos com visão de todo o CA.");
}

$mensagem = null;
$erro = null;

$grupoSelecionado = $_GET['grupo'] ?? null;
$rotulosCategorias = ['clube' => 'Clube', 'servico' => 'Serviço', 'comissao' => 'Comissão'];

// ---------- Criar novo grupo ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar_grupo') {
    $nomeNovo = trim($_POST['nome'] ?? '');
    $categoria = $_POST['categoria'] ?? '';

    $resultado = criarGrupo($conexao, $nomeNovo, $categoria);
    if ($resultado['ok']) {
        $mensagem = "Grupo \"$nomeNovo\" criado (também disponível como motivo de falta).";
        $grupoSelecionado = $nomeNovo;
    } else {
        $erro = $resultado['erro'];
    }
}

// ---------- Adicionar membro ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'adicionar') {
    $grupoNome = $_POST['grupo'] ?? '';
    $alunoId = (int)($_POST['aluno_id'] ?? 0);

    $grupo = buscarGrupoPorNome($conexao, $grupoNome);

    if (!$grupo || !$alunoId) {
        $erro = "Grupo ou aluno inválido.";
    } else {
        adicionarMembro($conexao, $grupo['id'], $alunoId);
        $mensagem = "Membro adicionado.";
        $grupoSelecionado = $grupoNome;
    }
}

// ---------- Excluir grupo ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir_grupo') {
    $grupoId = (int)($_POST['grupo_id'] ?? 0);
    excluirGrupo($conexao, $grupoId);
    $mensagem = "Grupo excluído.";
    $grupoSelecionado = null;
}

// ---------- Remover membro ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'remover') {
    $grupoId = (int)($_POST['grupo_id'] ?? 0);
    $alunoId = (int)($_POST['aluno_id'] ?? 0);

    removerMembro($conexao, $grupoId, $alunoId);
    $mensagem = "Membro removido.";
}

$grupos = listarGrupos($conexao);

$membros = [];
$grupoAtual = null;
if ($grupoSelecionado) {
    $grupoAtual = buscarGrupoPorNome($conexao, $grupoSelecionado);
    if ($grupoAtual) {
        $membros = listarMembros($conexao, $grupoAtual['id']);
    }
}

$buscaNome = $_GET['busca'] ?? '';
$candidatos = [];
if ($grupoAtual && $buscaNome !== '') {
    $candidatos = buscarCandidatosGrupo($conexao, $grupoAtual['id'], $buscaNome);
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Painel — Grupos</title>
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
    button { padding: 7px 12px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 12px; cursor: pointer; }
    button.danger { background: var(--danger); }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 6px 10px; text-align: left; }
    th { background: var(--azul-eear); color: #ffffff; }
    .scroll-x { overflow-x: auto; min-width: 0; }
    .erro { color: var(--danger); font-size: 13px; }
    .ok { color: var(--ok); font-size: 13px; }
    .layout { display: grid; grid-template-columns: 240px minmax(0, 1fr); gap: 20px; align-items: start; }
    .lista-grupos { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 12px; max-height: 75vh; overflow-y: auto; }
    .lista-grupos input { width: 100%; margin-bottom: 10px; }
    .lista-grupos h5 { font-size: 11px; text-transform: uppercase; letter-spacing: .03em; color: var(--text-muted); margin: 14px 0 6px; }
    .lista-grupos h5:first-of-type { margin-top: 0; }
    .lista-grupos a { display: flex; justify-content: space-between; align-items: center; padding: 7px 8px; border-radius: 6px; color: var(--text); text-decoration: none; font-size: 13px; }
    .lista-grupos a:hover { background: var(--bg); }
    .lista-grupos a.ativo { background: var(--accent); color: #fff; }
    .lista-grupos a small { color: inherit; opacity: .65; }
    .lista-grupos .vazio { color: var(--text-muted); font-size: 12px; padding: 6px 8px; }
    @media (max-width: 700px) {
        .layout { grid-template-columns: 1fr; }
        .lista-grupos { max-height: 300px; }
    }
    .i { display: inline-block; width: 13px; height: 13px; vertical-align: -2px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 4px; }
</style>
</head>
<body>

<div class="topbar">
    <div><strong>ARGOS</strong> <a href="index.php"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span>painel</a></div>
    <div><a href="index.php?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a></div>
</div>

<div class="container">
    <h2>Grupos</h2>

    <?php if ($erro): ?><p class="erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($mensagem): ?><p class="ok"><?= htmlspecialchars($mensagem) ?></p><?php endif; ?>

    <div class="layout">
        <div class="lista-grupos">
            <input type="text" id="filtro_grupos" placeholder="Buscar grupo..." oninput="filtrarGrupos()">
            <?php foreach ($rotulosCategorias as $catValor => $catRotulo): ?>
                <?php $gruposDaCategoria = array_filter($grupos, fn($g) => $g['categoria'] === $catValor); ?>
                <h5><?= $catRotulo ?></h5>
                <?php if (empty($gruposDaCategoria)): ?>
                    <p class="vazio">Nenhum grupo ainda.</p>
                <?php endif; ?>
                <?php foreach ($gruposDaCategoria as $g): ?>
                    <a href="?grupo=<?= urlencode($g['nome']) ?>" data-nome="<?= htmlspecialchars(mb_strtolower($g['nome'])) ?>" class="<?= $grupoSelecionado === $g['nome'] ? 'ativo' : '' ?>">
                        <?= htmlspecialchars($g['nome']) ?>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>

        <div>
    <div class="card">
        <h4>Criar novo grupo</h4>
        <form method="post" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <input type="hidden" name="acao" value="criar_grupo">
            <input type="text" name="nome" placeholder="Nome (ex: UNAEV)" required>
            <select name="categoria">
                <option value="clube">Clube</option>
                <option value="servico">Serviço</option>
                <option value="comissao">Comissão</option>
            </select>
            <button type="submit">Criar grupo</button>
        </form>
        <p style="color: var(--text-muted); font-size: 12px; margin-top: 8px; margin-bottom: 0;">
            O grupo passa a existir como agrupamento próprio (retirada de falta separada) e também
            aparece como opção de motivo na chamada normal da esquadrilha.
        </p>
    </div>

    <?php if ($grupoAtual): ?>
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h4 style="margin:0;">Adicionar membro a <?= htmlspecialchars($grupoAtual['nome']) ?></h4>
                <form method="post" onsubmit="return confirm('Excluir o grupo \'<?= htmlspecialchars($grupoAtual['nome'], ENT_QUOTES) ?>\'? Os membros são desvinculados, mas o histórico de retiradas já feitas continua.');">
                    <input type="hidden" name="acao" value="excluir_grupo">
                    <input type="hidden" name="grupo_id" value="<?= $grupoAtual['id'] ?>">
                    <button type="submit" class="danger">Excluir grupo</button>
                </form>
            </div>
            <form method="get">
                <input type="hidden" name="grupo" value="<?= htmlspecialchars($grupoAtual['nome']) ?>">
                <input type="text" name="busca" placeholder="Nome ou milhão" value="<?= htmlspecialchars($buscaNome) ?>">
                <button type="submit"><span class="i" style="--icon-url:url('../images/icons/search.svg')"></span>Buscar</button>
            </form>

            <?php if (!empty($candidatos)): ?>
                <div class="scroll-x">
                <table>
                    <tr><th>Identificação</th><th>Esquadrão/Esquadrilha</th><th></th></tr>
                    <?php foreach ($candidatos as $c): ?>
                        <tr>
                            <td><?= htmlspecialchars(identificacaoAluno($c)) ?></td>
                            <td><?= htmlspecialchars($c['esquadrao']) ?> / <?= htmlspecialchars($c['esquadrilha']) ?></td>
                            <td>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="acao" value="adicionar">
                                    <input type="hidden" name="grupo" value="<?= htmlspecialchars($grupoAtual['nome']) ?>">
                                    <input type="hidden" name="aluno_id" value="<?= $c['id'] ?>">
                                    <button type="submit">Adicionar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                </div>
            <?php elseif ($buscaNome !== ''): ?>
                <p style="color: var(--text-muted); font-size: 13px;">Nenhum aluno encontrado.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h4>Membros atuais (<?= count($membros) ?>)</h4>
            <div class="scroll-x">
            <table>
                <tr><th>Identificação</th><th>Esquadrão/Esquadrilha</th><th></th></tr>
                <?php foreach ($membros as $m): ?>
                    <tr>
                        <td><?= htmlspecialchars(identificacaoAluno($m)) ?></td>
                        <td><?= htmlspecialchars($m['esquadrao']) ?> / <?= htmlspecialchars($m['esquadrilha']) ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Remover do grupo?');">
                                <input type="hidden" name="acao" value="remover">
                                <input type="hidden" name="grupo_id" value="<?= $grupoAtual['id'] ?>">
                                <input type="hidden" name="aluno_id" value="<?= $m['id'] ?>">
                                <button type="submit" class="danger">Remover</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <p style="color: var(--text-muted); font-size: 13px; margin: 0;">Selecione um grupo na lista ao lado.</p>
        </div>
    <?php endif; ?>
        </div>
    </div>
</div>

<script>
    function filtrarGrupos() {
        const termo = document.getElementById('filtro_grupos').value.trim().toLowerCase();
        document.querySelectorAll('.lista-grupos a').forEach(a => {
            a.style.display = a.dataset.nome.includes(termo) ? 'flex' : 'none';
        });
        document.querySelectorAll('.lista-grupos h5').forEach(h => {
            let temVisivel = false;
            let el = h.nextElementSibling;
            while (el && el.tagName !== 'H5') {
                if (el.tagName === 'A' && el.style.display !== 'none') temVisivel = true;
                el = el.nextElementSibling;
            }
            h.style.display = temVisivel ? 'block' : 'none';
        });
    }
</script>

<?php include __DIR__ . '/../../core/rodape_versao.php'; ?>
</body>
</html>
<?php mysqli_close($conexao); ?>
