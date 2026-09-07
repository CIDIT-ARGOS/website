<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';

$conexao = conectarBanco();
$escopo = escopoEsquadrao(); // null = CA inteiro, senão string do esquadrão

// ---------- Filtros ----------
$filtros = ["a.ativo = 1"];
$params = [];
$tipos = "";

if ($escopo !== null) {
    $filtros[] = "a.esquadrao = ?";
    $params[] = $escopo;
    $tipos .= "s";
} elseif (!empty($_GET['esquadrao'])) {
    $filtros[] = "a.esquadrao = ?";
    $params[] = $_GET['esquadrao'];
    $tipos .= "s";
}

if (!empty($_GET['esquadrilha'])) {
    $filtros[] = "a.esquadrilha = ?";
    $params[] = $_GET['esquadrilha'];
    $tipos .= "s";
}
if (!empty($_GET['especialidade'])) {
    $filtros[] = "a.especialidade = ?";
    $params[] = $_GET['especialidade'];
    $tipos .= "s";
}
if (!empty($_GET['busca'])) {
    $filtros[] = "(a.nome_guerra LIKE ? OR a.milhao LIKE ?)";
    $termo = "%" . $_GET['busca'] . "%";
    $params[] = $termo;
    $params[] = $termo;
    $tipos .= "ss";
}

$where = "WHERE " . implode(" AND ", $filtros);
$sql = "
    SELECT a.*, COALESCE(p.exibicao, a.posto_graduacao) as posto_exibicao
    FROM alunos a
    LEFT JOIN postos_graduacao p ON p.codigo = a.posto_graduacao
    $where
    ORDER BY a.esquadrilha, a.nome_guerra
";

$stmt = mysqli_prepare($conexao, $sql);
if ($params) {
    mysqli_stmt_bind_param($stmt, $tipos, ...$params);
}
mysqli_stmt_execute($stmt);
$alunos = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

// listas para os filtros (dentro do escopo)
$esquadroesDisponiveis = [];
if ($escopo === null) {
    $r = mysqli_query($conexao, "SELECT DISTINCT esquadrao FROM alunos WHERE ativo = 1 ORDER BY esquadrao");
    while ($l = mysqli_fetch_assoc($r)) $esquadroesDisponiveis[] = $l['esquadrao'];
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Painel — Efetivo</title>
<style>
    :root { --bg: #0d1117; --bg-card: #161b22; --border: #30363d; --text: #e6edf3; --text-muted: #8b949e; --accent: #4f8cff; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); overflow-x: hidden; }
    .topbar { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid var(--border); }
    .topbar a { color: var(--text-muted); text-decoration: none; font-size: 13px; margin-left: 16px; }
    .container { padding: 24px; max-width: 1200px; margin: 0 auto; }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
    input, select { padding: 8px 10px; background: #0d1117; border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 13px; }
    button { padding: 8px 14px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 6px 10px; text-align: left; }
    th { background: #1c2128; color: var(--text-muted); }
    .scroll-x { overflow-x: auto; min-width: 0; }
    .filtros { display: flex; gap: 10px; flex-wrap: wrap; }
    .link-btn { color: var(--accent); text-decoration: none; font-size: 12px; }
</style>
</head>
<body>

<div class="topbar">
    <div><strong>ARGOS</strong> <a href="index.php">← painel</a></div>
    <div><a href="index.php?logout=1">sair</a></div>
</div>

<div class="container">
    <h2>Efetivo <?= $escopo ? '— Esquadrão ' . htmlspecialchars($escopo) : '(todos os esquadrões)' ?></h2>

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
            <button type="submit">Filtrar</button>
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
                        <td><a href="efetivo_editar.php?id=<?= $a['id'] ?>" class="link-btn">editar</a></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
    </div>
</div>

</body>
</html>
<?php mysqli_close($conexao); ?>
