<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';

if (!podeGerenciarUsuarios()) {
    die("Seu cargo não tem permissão para gerenciar usuários do painel.");
}

$conexao = conectarBanco();
$meuId = (int)$_SESSION['painel_id'];

$mensagem = null;
$erro = null;

$cargosEsquadrao = ['CMD_ESQUADRAO', 'ENC_ESQUADRAO', 'AUX_ESQUADRAO'];
$cargosCA = ['CMD_CA', 'SUBCMD_CA', 'ADMIN_TECNICO', 'AUX_CA'];
$todosCargos = array_merge($cargosCA, $cargosEsquadrao);

function contarCmdCaAtivos($conexao) {
    $r = mysqli_query($conexao, "SELECT COUNT(*) as total FROM painel_usuarios WHERE cargo = 'CMD_CA' AND ativo = 1");
    return (int) mysqli_fetch_assoc($r)['total'];
}

// ---------- CRIAR ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar') {
    $nome = trim($_POST['nome'] ?? '');
    $usuario = trim($_POST['usuario'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $cargo = $_POST['cargo'] ?? '';
    $esquadrao = trim($_POST['esquadrao'] ?? '');

    if ($nome === '' || $usuario === '' || $senha === '') {
        $erro = "Preencha nome, usuário e senha.";
    } elseif (!in_array($cargo, $todosCargos)) {
        $erro = "Cargo inválido.";
    } elseif (in_array($cargo, $cargosEsquadrao) && $esquadrao === '') {
        $erro = "Cargos de esquadrão exigem informar o esquadrão.";
    } else {
        $esquadraoFinal = in_array($cargo, $cargosCA) ? null : $esquadrao;
        $usuarioEsc = mysqli_real_escape_string($conexao, $usuario);
        $existe = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT id FROM painel_usuarios WHERE usuario = '$usuarioEsc'"));

        if ($existe) {
            $erro = "Já existe um usuário com esse login.";
        } else {
            $hash = password_hash($senha, PASSWORD_BCRYPT);
            $stmt = mysqli_prepare($conexao, "INSERT INTO painel_usuarios (nome, usuario, senha_hash, cargo, esquadrao) VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "sssss", $nome, $usuario, $hash, $cargo, $esquadraoFinal);
            mysqli_stmt_execute($stmt);
            $mensagem = "Usuário criado com sucesso.";
        }
    }
}

// ---------- ATUALIZAR ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar') {
    $id = (int)$_POST['id'];
    $nome = trim($_POST['nome'] ?? '');
    $cargo = $_POST['cargo'] ?? '';
    $esquadrao = trim($_POST['esquadrao'] ?? '');
    $ativo = isset($_POST['ativo']) ? 1 : 0;

    $alvo = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT * FROM painel_usuarios WHERE id = $id"));

    if (!$alvo) {
        $erro = "Usuário não encontrado.";
    } elseif ($alvo['cargo'] === 'CMD_CA' && ($cargo !== 'CMD_CA' || $ativo === 0) && contarCmdCaAtivos($conexao) <= 1) {
        $erro = "Não é possível rebaixar ou desativar o último CMD_CA ativo.";
    } elseif (in_array($cargo, $cargosEsquadrao) && $esquadrao === '') {
        $erro = "Cargos de esquadrão exigem informar o esquadrão.";
    } else {
        $esquadraoFinal = in_array($cargo, $cargosCA) ? null : $esquadrao;
        $stmt = mysqli_prepare($conexao, "UPDATE painel_usuarios SET nome = ?, cargo = ?, esquadrao = ?, ativo = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "sssii", $nome, $cargo, $esquadraoFinal, $ativo, $id);
        mysqli_stmt_execute($stmt);
        $mensagem = "Usuário atualizado.";
    }
}

// ---------- RESETAR SENHA ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'resetar_senha') {
    $id = (int)$_POST['id'];
    $novaSenha = $_POST['nova_senha'] ?? '';

    if (strlen($novaSenha) < 6) {
        $erro = "A nova senha precisa ter pelo menos 6 caracteres.";
    } else {
        $hash = password_hash($novaSenha, PASSWORD_BCRYPT);
        $stmt = mysqli_prepare($conexao, "UPDATE painel_usuarios SET senha_hash = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "si", $hash, $id);
        mysqli_stmt_execute($stmt);
        $mensagem = "Senha redefinida com sucesso.";
    }
}

// ---------- EXCLUIR ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir') {
    $id = (int)$_POST['id'];

    if ($id === $meuId) {
        $erro = "Você não pode excluir a própria conta.";
    } else {
        $alvo = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT * FROM painel_usuarios WHERE id = $id"));
        if ($alvo && $alvo['cargo'] === 'CMD_CA' && contarCmdCaAtivos($conexao) <= 1) {
            $erro = "Não é possível excluir o último CMD_CA ativo.";
        } else {
            mysqli_query($conexao, "DELETE FROM painel_usuarios WHERE id = $id");
            $mensagem = "Usuário excluído.";
        }
    }
}

$usuarios = mysqli_fetch_all(mysqli_query($conexao, "SELECT * FROM painel_usuarios ORDER BY criado_em ASC"), MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Painel — Usuários</title>
<style>
    :root { --bg: #0d1117; --bg-card: #161b22; --border: #30363d; --text: #e6edf3; --text-muted: #8b949e; --accent: #4f8cff; --danger: #ff5f5f; --ok: #4caf7d; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); overflow-x: hidden; }
    .topbar { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid var(--border); }
    .topbar a { color: var(--text-muted); text-decoration: none; font-size: 13px; margin-left: 16px; }
    .container { padding: 24px; max-width: 1100px; margin: 0 auto; }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
    input, select { padding: 8px 10px; background: #0d1117; border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 13px; }
    button { padding: 8px 14px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; }
    button.danger { background: var(--danger); }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 8px 10px; text-align: left; vertical-align: top; }
    th { background: #1c2128; color: var(--text-muted); }
    .erro { color: var(--danger); font-size: 13px; }
    .ok { color: var(--ok); font-size: 13px; }
    .form-linha { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .badge { font-size: 11px; padding: 2px 8px; border-radius: 999px; }
    .badge.ativo { background: #17301f; color: var(--ok); }
    .badge.inativo { background: #301717; color: var(--danger); }
    .scroll-x { overflow-x: auto; min-width: 0; }
    details summary { cursor: pointer; color: var(--accent); font-size: 12px; }
</style>
<script>
    function alternarEsquadrao(select, campoEsquadrao) {
        const cargosEsquadrao = ['CMD_ESQUADRAO', 'ENC_ESQUADRAO', 'AUX_ESQUADRAO'];
        campoEsquadrao.style.display = cargosEsquadrao.includes(select.value) ? 'inline-block' : 'none';
    }
</script>
</head>
<body>

<div class="topbar">
    <div><strong>ARGOS</strong> <a href="index.php">← painel</a></div>
    <div><a href="index.php?logout=1">sair</a></div>
</div>

<div class="container">
    <h2>Usuários do painel</h2>

    <?php if ($erro): ?><p class="erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($mensagem): ?><p class="ok"><?= htmlspecialchars($mensagem) ?></p><?php endif; ?>

    <div class="card">
        <h4>Novo usuário</h4>
        <form method="post" class="form-linha">
            <input type="hidden" name="acao" value="criar">
            <input type="text" name="nome" placeholder="Nome" required>
            <input type="text" name="usuario" placeholder="Usuário (login)" required>
            <input type="password" name="senha" placeholder="Senha" required>
            <select name="cargo" onchange="alternarEsquadrao(this, this.form.esquadrao)">
                <option value="CMD_CA">Comandante do CA</option>
                <option value="SUBCMD_CA">Subcomandante do CA</option>
                <option value="ADMIN_TECNICO">Administrador Técnico</option>
                <option value="AUX_CA">Auxiliar CA</option>
                <option value="CMD_ESQUADRAO" selected>Comandante de Esquadrão</option>
                <option value="ENC_ESQUADRAO">Encarregado de Esquadrão</option>
                <option value="AUX_ESQUADRAO">Auxiliar de Esquadrão</option>
            </select>
            <input type="text" name="esquadrao" placeholder="Esquadrão (ex: PRATA)">
            <button type="submit">Criar</button>
        </form>
    </div>

    <div class="card">
        <h4>Contas existentes</h4>
        <div class="scroll-x">
        <table>
            <tr>
                <th>Nome</th><th>Usuário</th><th>Cargo</th><th>Esquadrão</th><th>Status</th><th>Último login</th><th>Ações</th>
            </tr>
            <?php foreach ($usuarios as $u): ?>
                <?php $formId = 'form_edit_' . $u['id']; ?>
                <form id="<?= $formId ?>" method="post">
                    <input type="hidden" name="acao" value="atualizar">
                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                </form>
                <tr>
                    <td><input form="<?= $formId ?>" type="text" name="nome" value="<?= htmlspecialchars($u['nome']) ?>"></td>
                    <td><?= htmlspecialchars($u['usuario']) ?><?= $u['id'] == $meuId ? ' <small style="color:var(--text-muted)">(você)</small>' : '' ?></td>
                    <td>
                        <select form="<?= $formId ?>" name="cargo" onchange="alternarEsquadrao(this, document.getElementById('esq_<?= $u['id'] ?>'))">
                            <?php foreach ($todosCargos as $c): ?>
                                <option value="<?= $c ?>" <?= $u['cargo'] === $c ? 'selected' : '' ?>><?= $c ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input form="<?= $formId ?>" id="esq_<?= $u['id'] ?>" type="text" name="esquadrao" value="<?= htmlspecialchars($u['esquadrao'] ?? '') ?>"
                            style="<?= in_array($u['cargo'], $cargosCA) ? 'display:none;' : '' ?>">
                    </td>
                    <td>
                        <label style="font-size:12px;">
                            <input form="<?= $formId ?>" type="checkbox" name="ativo" <?= $u['ativo'] ? 'checked' : '' ?>>
                            <span class="badge <?= $u['ativo'] ? 'ativo' : 'inativo' ?>"><?= $u['ativo'] ? 'ativo' : 'inativo' ?></span>
                        </label>
                    </td>
                    <td><?= htmlspecialchars($u['ultimo_login'] ?? '—') ?></td>
                    <td>
                        <button form="<?= $formId ?>" type="submit">Salvar</button>
                        <details>
                            <summary>redefinir senha</summary>
                            <form method="post" style="margin-top:6px; display:flex; gap:6px;">
                                <input type="hidden" name="acao" value="resetar_senha">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <input type="password" name="nova_senha" placeholder="Nova senha" required>
                                <button type="submit">Redefinir</button>
                            </form>
                        </details>
                        <?php if ($u['id'] != $meuId): ?>
                        <form method="post" onsubmit="return confirm('Excluir este usuário?');" style="margin-top:6px;">
                            <input type="hidden" name="acao" value="excluir">
                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <button type="submit" class="danger">Excluir</button>
                        </form>
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
