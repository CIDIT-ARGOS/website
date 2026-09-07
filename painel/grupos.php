<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';

$conexao = conectarBanco();

if (escopoEsquadrao() !== null) {
    die("Grupos são geridos por cargos com visão de todo o CA.");
}

$mensagem = null;
$erro = null;

$grupoSelecionado = $_GET['grupo'] ?? null;
$categoriasValidas = ['clube', 'servico', 'comissao'];
$rotulosCategorias = ['clube' => 'Clube', 'servico' => 'Serviço', 'comissao' => 'Comissão'];

// ---------- Criar novo grupo ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar_grupo') {
    $nomeNovo = trim($_POST['nome'] ?? '');
    $categoria = $_POST['categoria'] ?? '';

    if ($nomeNovo === '') {
        $erro = "Informe o nome do grupo.";
    } elseif (!in_array($categoria, $categoriasValidas)) {
        $erro = "Categoria inválida.";
    } else {
        $existe = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT id FROM grupos WHERE nome = '" . mysqli_real_escape_string($conexao, $nomeNovo) . "'"));
        if ($existe) {
            $erro = "Já existe um grupo com esse nome.";
        } else {
            $stmt = mysqli_prepare($conexao, "INSERT INTO grupos (nome, categoria) VALUES (?, ?)");
            mysqli_stmt_bind_param($stmt, "ss", $nomeNovo, $categoria);
            mysqli_stmt_execute($stmt);

            // Todo grupo também vira uma opção de motivo de falta (ex: marcar "CIDIT"
            // como motivo na chamada normal da esquadrilha, sem precisar de retirada própria).
            $motivoExiste = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT id FROM motivos_falta WHERE nome = '" . mysqli_real_escape_string($conexao, $nomeNovo) . "'"));
            if (!$motivoExiste) {
                $proximaOrdem = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT COALESCE(MAX(ordem), 0) + 1 as prox FROM motivos_falta"))['prox'];
                $stmt = mysqli_prepare($conexao, "INSERT INTO motivos_falta (nome, requer_observacao, ordem) VALUES (?, 0, ?)");
                mysqli_stmt_bind_param($stmt, "si", $nomeNovo, $proximaOrdem);
                mysqli_stmt_execute($stmt);
            }

            $mensagem = "Grupo \"$nomeNovo\" criado (também disponível como motivo de falta).";
            $grupoSelecionado = $nomeNovo;
        }
    }
}

// ---------- Adicionar membro ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'adicionar') {
    $grupoNome = $_POST['grupo'] ?? '';
    $alunoId = (int)($_POST['aluno_id'] ?? 0);

    $grupo = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT id FROM grupos WHERE nome = '" . mysqli_real_escape_string($conexao, $grupoNome) . "'"));

    if (!$grupo || !$alunoId) {
        $erro = "Grupo ou aluno inválido.";
    } else {
        $stmt = mysqli_prepare($conexao, "INSERT IGNORE INTO grupo_membros (grupo_id, aluno_id) VALUES (?, ?)");
        mysqli_stmt_bind_param($stmt, "ii", $grupo['id'], $alunoId);
        mysqli_stmt_execute($stmt);
        $mensagem = "Membro adicionado.";
        $grupoSelecionado = $grupoNome;
    }
}

// ---------- Remover membro ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'remover') {
    $grupoId = (int)($_POST['grupo_id'] ?? 0);
    $alunoId = (int)($_POST['aluno_id'] ?? 0);

    $stmt = mysqli_prepare($conexao, "DELETE FROM grupo_membros WHERE grupo_id = ? AND aluno_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $grupoId, $alunoId);
    mysqli_stmt_execute($stmt);
    $mensagem = "Membro removido.";
}

$grupos = mysqli_fetch_all(mysqli_query($conexao, "SELECT * FROM grupos WHERE ativo = 1 ORDER BY nome"), MYSQLI_ASSOC);

$membros = [];
$grupoAtual = null;
if ($grupoSelecionado) {
    $grupoAtual = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT * FROM grupos WHERE nome = '" . mysqli_real_escape_string($conexao, $grupoSelecionado) . "'"));
    if ($grupoAtual) {
        $stmt = mysqli_prepare($conexao, "
            SELECT a.id, a.nome_guerra, a.milhao, a.esquadrao, a.esquadrilha
            FROM grupo_membros gm
            JOIN alunos a ON a.id = gm.aluno_id
            WHERE gm.grupo_id = ?
            ORDER BY a.nome_guerra
        ");
        mysqli_stmt_bind_param($stmt, "i", $grupoAtual['id']);
        mysqli_stmt_execute($stmt);
        $membros = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    }
}

$buscaNome = $_GET['busca'] ?? '';
$candidatos = [];
if ($grupoAtual && $buscaNome !== '') {
    $termo = "%$buscaNome%";
    $stmt = mysqli_prepare($conexao, "
        SELECT id, nome_guerra, milhao, esquadrao, esquadrilha FROM alunos
        WHERE ativo = 1 AND (nome_guerra LIKE ? OR milhao LIKE ?)
        AND id NOT IN (SELECT aluno_id FROM grupo_membros WHERE grupo_id = ?)
        ORDER BY nome_guerra LIMIT 20
    ");
    mysqli_stmt_bind_param($stmt, "ssi", $termo, $termo, $grupoAtual['id']);
    mysqli_stmt_execute($stmt);
    $candidatos = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
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
    :root { --bg: #0d1117; --bg-card: #161b22; --border: #30363d; --text: #e6edf3; --text-muted: #8b949e; --accent: #4f8cff; --danger: #ff5f5f; --ok: #4caf7d; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); overflow-x: hidden; }
    .topbar { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid var(--border); }
    .topbar a { color: var(--text-muted); text-decoration: none; font-size: 13px; margin-left: 16px; }
    .container { padding: 24px; max-width: 900px; margin: 0 auto; }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
    input, select { padding: 8px 10px; background: #0d1117; border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 13px; }
    button { padding: 7px 12px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 12px; cursor: pointer; }
    button.danger { background: var(--danger); }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 6px 10px; text-align: left; }
    th { background: #1c2128; color: var(--text-muted); }
    .erro { color: var(--danger); font-size: 13px; }
    .ok { color: var(--ok); font-size: 13px; }
    .tabs a { color: var(--text-muted); text-decoration: none; margin-right: 14px; padding-bottom: 4px; }
    .tabs a.ativo { color: var(--text); border-bottom: 2px solid var(--accent); }
</style>
</head>
<body>

<div class="topbar">
    <div><strong>ARGOS</strong> <a href="index.php">← painel</a></div>
    <div><a href="index.php?logout=1">sair</a></div>
</div>

<div class="container">
    <h2>Grupos</h2>

    <div class="tabs">
        <?php foreach ($grupos as $g): ?>
            <a href="?grupo=<?= urlencode($g['nome']) ?>" class="<?= $grupoSelecionado === $g['nome'] ? 'ativo' : '' ?>">
                <?= htmlspecialchars($g['nome']) ?> <small style="color:var(--text-muted);">(<?= $rotulosCategorias[$g['categoria']] ?? $g['categoria'] ?>)</small>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($erro): ?><p class="erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($mensagem): ?><p class="ok"><?= htmlspecialchars($mensagem) ?></p><?php endif; ?>

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
            <h4>Adicionar membro a <?= htmlspecialchars($grupoAtual['nome']) ?></h4>
            <form method="get">
                <input type="hidden" name="grupo" value="<?= htmlspecialchars($grupoAtual['nome']) ?>">
                <input type="text" name="busca" placeholder="Nome ou milhão" value="<?= htmlspecialchars($buscaNome) ?>">
                <button type="submit">Buscar</button>
            </form>

            <?php if (!empty($candidatos)): ?>
                <table>
                    <tr><th>Nome</th><th>Milhão</th><th>Esquadrão/Esquadrilha</th><th></th></tr>
                    <?php foreach ($candidatos as $c): ?>
                        <tr>
                            <td><?= htmlspecialchars($c['nome_guerra']) ?></td>
                            <td><?= htmlspecialchars($c['milhao']) ?></td>
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
            <?php elseif ($buscaNome !== ''): ?>
                <p style="color: var(--text-muted); font-size: 13px;">Nenhum aluno encontrado.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h4>Membros atuais (<?= count($membros) ?>)</h4>
            <table>
                <tr><th>Nome</th><th>Milhão</th><th>Esquadrão/Esquadrilha</th><th></th></tr>
                <?php foreach ($membros as $m): ?>
                    <tr>
                        <td><?= htmlspecialchars($m['nome_guerra']) ?></td>
                        <td><?= htmlspecialchars($m['milhao']) ?></td>
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
    <?php else: ?>
        <p style="color: var(--text-muted); font-size: 13px;">Selecione um grupo acima.</p>
    <?php endif; ?>
</div>

</body>
</html>
<?php mysqli_close($conexao); ?>
