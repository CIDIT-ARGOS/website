<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';

if (!podeEditarEfetivo()) {
    die("Seu cargo não tem a permissão 'editar_efetivo'.");
}

$conexao = conectarBanco();
$escopo = escopoEsquadrao();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$mensagem = null;
$erro = null;

$aluno = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT * FROM alunos WHERE id = " . $id));

if (!$aluno) {
    die("Aluno não encontrado.");
}

if ($escopo !== null && $aluno['esquadrao'] !== $escopo) {
    die("Você não tem permissão para editar alunos fora do Esquadrão $escopo.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sexo = $_POST['sexo'] ?? '';
    $curso = $_POST['curso'] ?? '';
    $serie = trim($_POST['serie'] ?? '');
    $esquadrilha = trim($_POST['esquadrilha'] ?? '');
    $especialidade = trim($_POST['especialidade'] ?? '');
    $subEspecialidade = trim($_POST['sub_especialidade'] ?? '');
    $ativo = isset($_POST['ativo']) ? 1 : 0;

    if (!in_array($sexo, ['M', 'F'])) {
        $erro = "Sexo inválido.";
    } elseif (!in_array($curso, ['CFS', 'EAGS'])) {
        $erro = "Curso inválido.";
    } elseif ($curso === 'EAGS' && $serie !== 'EAGS') {
        $erro = "Para EAGS, a série deve ser 'EAGS'.";
    } elseif ($curso === 'CFS' && !in_array($serie, ['1', '2', '3', '4'])) {
        $erro = "Para CFS, a série deve ser 1, 2, 3 ou 4.";
    } else {
        $stmt = mysqli_prepare($conexao, "
            UPDATE alunos SET sexo = ?, curso = ?, serie = ?, esquadrilha = ?, especialidade = ?, sub_especialidade = ?, ativo = ?
            WHERE id = ?
        ");
        mysqli_stmt_bind_param($stmt, "ssssssii", $sexo, $curso, $serie, $esquadrilha, $especialidade, $subEspecialidade, $ativo, $id);

        if (mysqli_stmt_execute($stmt)) {
            $mensagem = "Aluno atualizado com sucesso.";
            $aluno = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT * FROM alunos WHERE id = " . $id));
        } else {
            $erro = "Erro ao atualizar: " . mysqli_stmt_error($stmt);
        }
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Painel — Editar aluno</title>
<style>
    :root { --bg: #0d1117; --bg-card: #161b22; --border: #30363d; --text: #e6edf3; --text-muted: #8b949e; --accent: #4f8cff; --danger: #ff5f5f; --ok: #4caf7d; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); }
    .topbar { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid var(--border); }
    .topbar a { color: var(--text-muted); text-decoration: none; font-size: 13px; margin-left: 16px; }
    .container { padding: 24px; max-width: 500px; margin: 0 auto; }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; }
    label { display: block; font-size: 12px; color: var(--text-muted); margin-top: 12px; margin-bottom: 4px; }
    input, select { width: 100%; padding: 8px 10px; background: #0d1117; border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 13px; }
    input[readonly] { color: var(--text-muted); }
    button { padding: 9px 18px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; margin-top: 18px; }
    .erro { color: var(--danger); font-size: 13px; }
    .ok { color: var(--ok); font-size: 13px; }
</style>
</head>
<body>

<div class="topbar">
    <div><strong>ARGOS</strong> <a href="efetivo.php">← efetivo</a></div>
    <div><a href="index.php?logout=1">sair</a></div>
</div>

<div class="container">
    <h2>Editar aluno</h2>

    <?php if ($erro): ?><p class="erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($mensagem): ?><p class="ok"><?= htmlspecialchars($mensagem) ?></p><?php endif; ?>

    <div class="card">
        <form method="post">
            <input type="hidden" name="id" value="<?= $id ?>">

            <label>Nome de guerra</label>
            <input type="text" value="<?= htmlspecialchars($aluno['nome_guerra']) ?>" readonly>

            <label>Milhão</label>
            <input type="text" value="<?= htmlspecialchars($aluno['milhao']) ?>" readonly>

            <label>Sexo</label>
            <select name="sexo">
                <option value="M" <?= $aluno['sexo'] === 'M' ? 'selected' : '' ?>>Masculino</option>
                <option value="F" <?= $aluno['sexo'] === 'F' ? 'selected' : '' ?>>Feminino</option>
            </select>

            <label>Curso</label>
            <select name="curso">
                <option value="CFS" <?= $aluno['curso'] === 'CFS' ? 'selected' : '' ?>>CFS</option>
                <option value="EAGS" <?= $aluno['curso'] === 'EAGS' ? 'selected' : '' ?>>EAGS</option>
            </select>

            <label>Série</label>
            <input type="text" name="serie" value="<?= htmlspecialchars($aluno['serie']) ?>">

            <label>Esquadrilha</label>
            <input type="text" name="esquadrilha" value="<?= htmlspecialchars($aluno['esquadrilha']) ?>">

            <label>Especialidade</label>
            <input type="text" name="especialidade" value="<?= htmlspecialchars($aluno['especialidade'] ?? '') ?>">

            <label>Sub-especialidade</label>
            <input type="text" name="sub_especialidade" value="<?= htmlspecialchars($aluno['sub_especialidade'] ?? '') ?>">

            <label style="display:flex; align-items:center; gap:6px; margin-top:16px;">
                <input type="checkbox" name="ativo" style="width:auto;" <?= $aluno['ativo'] ? 'checked' : '' ?>> Ativo
            </label>

            <button type="submit">Salvar</button>
        </form>
    </div>
</div>

</body>
</html>
<?php mysqli_close($conexao); ?>
