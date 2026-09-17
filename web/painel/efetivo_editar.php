<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/acesso_negado.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../core/alunos_core.php';

if (!podeEditarEfetivo()) {
    exibirAcessoNegado("Seu cargo não tem a permissão 'editar_efetivo'.");
}

$conexao = conectarBanco();
$escopo = escopoEsquadrao();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$mensagem = null;
$erro = null;

$aluno = buscarAlunoPorId($conexao, $id);

if (!$aluno) {
    die("Aluno não encontrado.");
}

if ($escopo !== null && $aluno['esquadrao'] !== $escopo) {
    exibirAcessoNegado("Você não tem permissão para editar alunos fora do Esquadrão $escopo.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dados = $_POST;
    $dados['ativo'] = isset($_POST['ativo']);

    $resultado = atualizarAlunoParcial($conexao, $id, $dados);
    if ($resultado['ok']) {
        $mensagem = "Aluno atualizado com sucesso.";
        $aluno = buscarAlunoPorId($conexao, $id);
    } else {
        $erro = $resultado['erro'];
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
        :root {
        --bg: #f4f7fc; --bg-card: #ffffff; --border: #dbe3ef;
        --text: #16202e; --text-muted: #55637a; --accent: #2f6fed;
        --azul-eear: #0a2e5c; --danger: #c0392b; --ok: #1f8a4c;
    }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); }
    .topbar { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid var(--border); }
    .topbar a { color: var(--text-muted); text-decoration: none; font-size: 13px; margin-left: 16px; }
    .container { padding: 24px; max-width: 500px; margin: 0 auto; }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; }
    label { display: block; font-size: 12px; color: var(--text-muted); margin-top: 12px; margin-bottom: 4px; }
    input, select { width: 100%; padding: 8px 10px; background: #ffffff; border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 13px; }
    select { max-width: 100%; }
    input[readonly] { color: var(--text-muted); }
    button { padding: 9px 18px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; margin-top: 18px; }
    .erro { color: var(--danger); font-size: 13px; }
    .ok { color: var(--ok); font-size: 13px; }
    .i { display: inline-block; width: 13px; height: 13px; vertical-align: -2px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 4px; }
</style>
</head>
<body>

<div class="topbar">
    <div><strong>ARGOS</strong> <a href="efetivo.php"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span>efetivo</a></div>
    <div><a href="index.php?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a></div>
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

<?php include __DIR__ . '/../../core/rodape_versao.php'; ?>
</body>
</html>
<?php mysqli_close($conexao); ?>
