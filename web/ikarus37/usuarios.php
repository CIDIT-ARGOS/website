<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../core/cargos_core.php';
require_once __DIR__ . '/../../core/validacao_core.php';

// Limites batendo com o VARCHAR de admin_usuarios/painel_usuarios (database/init_db.sql).
const ADMIN_USUARIO_LIMITES_CAMPOS = ['nome' => 100, 'usuario' => 50, 'senha' => 200, 'nova_senha' => 200];
const PAINEL_USUARIO_LIMITES_CAMPOS_LOCAL = ['nome' => 100, 'usuario' => 50, 'esquadrao' => 50, 'senha' => 200];

$conexao = conectarBanco();

$meuId = (int)$_SESSION['admin_id'];
$souSuperAdmin = temPermissao($conexao, 'ikarus37', $_SESSION['admin_nivel'], 'gerenciar_usuarios_admin');
$podeGerenciarPainel = temPermissao($conexao, 'ikarus37', $_SESSION['admin_nivel'], 'gerenciar_usuarios_painel');

$mensagem = null;
$erro = null;

function contarSuperAdminsAtivos($conexao) {
    $r = mysqli_query($conexao, "SELECT COUNT(*) as total FROM admin_usuarios WHERE nivel = 'super_admin' AND ativo = 1");
    return (int) mysqli_fetch_assoc($r)['total'];
}

if (!$souSuperAdmin) {
    $erro = "Sua conta não tem a permissão 'gerenciar_usuarios_admin'.";
}

// ---------- CRIAR ----------
if ($souSuperAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar') {
    $nome = trim($_POST['nome'] ?? '');
    $usuario = trim($_POST['usuario'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $nivel = $_POST['nivel'] ?? 'admin';

    $erroComprimento = validarComprimentos(['nome' => $nome, 'usuario' => $usuario, 'senha' => $senha], ADMIN_USUARIO_LIMITES_CAMPOS);

    if ($nome === '' || $usuario === '' || $senha === '') {
        $erro = "Preencha nome, usuário e senha.";
    } elseif ($erroComprimento) {
        $erro = $erroComprimento;
    } elseif (!cargoValido($conexao, 'ikarus37', $nivel)) {
        $erro = "Nível inválido.";
    } else {
        $usuarioEsc = mysqli_real_escape_string($conexao, $usuario);
        $existe = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT id FROM admin_usuarios WHERE usuario = '$usuarioEsc'"));

        if ($existe) {
            $erro = "Já existe um usuário com esse login.";
        } else {
            $hash = password_hash($senha, PASSWORD_BCRYPT);
            $stmt = mysqli_prepare($conexao, "INSERT INTO admin_usuarios (nome, usuario, senha_hash, nivel) VALUES (?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "ssss", $nome, $usuario, $hash, $nivel);
            mysqli_stmt_execute($stmt);
            $mensagem = "Usuário criado com sucesso.";
        }
    }
}

// ---------- ATUALIZAR (nome/nivel/ativo) ----------
if ($souSuperAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar') {
    $id = (int)$_POST['id'];
    $nome = trim($_POST['nome'] ?? '');
    $nivel = $_POST['nivel'] ?? 'admin';
    $ativo = isset($_POST['ativo']) ? 1 : 0;

    $alvo = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT * FROM admin_usuarios WHERE id = $id"));
    $erroComprimento = validarComprimentos(['nome' => $nome], ADMIN_USUARIO_LIMITES_CAMPOS);

    if (!$alvo) {
        $erro = "Usuário não encontrado.";
    } elseif ($erroComprimento) {
        $erro = $erroComprimento;
    } elseif ($alvo['nivel'] === 'super_admin' && ($nivel !== 'super_admin' || $ativo === 0) && contarSuperAdminsAtivos($conexao) <= 1) {
        $erro = "Não é possível rebaixar ou desativar o último super_admin ativo.";
    } else {
        $stmt = mysqli_prepare($conexao, "UPDATE admin_usuarios SET nome = ?, nivel = ?, ativo = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "ssii", $nome, $nivel, $ativo, $id);
        mysqli_stmt_execute($stmt);
        $mensagem = "Usuário atualizado.";
    }
}

// ---------- RESETAR SENHA ----------
if ($souSuperAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'resetar_senha') {
    $id = (int)$_POST['id'];
    $novaSenha = $_POST['nova_senha'] ?? '';

    if (strlen($novaSenha) < 6) {
        $erro = "A nova senha precisa ter pelo menos 6 caracteres.";
    } elseif (mb_strlen($novaSenha) > 200) {
        $erro = "A nova senha excede o tamanho máximo permitido (200 caracteres).";
    } else {
        $hash = password_hash($novaSenha, PASSWORD_BCRYPT);
        $stmt = mysqli_prepare($conexao, "UPDATE admin_usuarios SET senha_hash = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "si", $hash, $id);
        mysqli_stmt_execute($stmt);
        $mensagem = "Senha redefinida com sucesso.";
    }
}

// ---------- EXCLUIR ----------
if ($souSuperAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir') {
    $id = (int)$_POST['id'];

    if ($id === $meuId) {
        $erro = "Você não pode excluir a própria conta.";
    } else {
        $alvo = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT * FROM admin_usuarios WHERE id = $id"));
        if ($alvo && $alvo['nivel'] === 'super_admin' && contarSuperAdminsAtivos($conexao) <= 1) {
            $erro = "Não é possível excluir o último super_admin ativo.";
        } else {
            mysqli_query($conexao, "DELETE FROM admin_usuarios WHERE id = $id");
            $mensagem = "Usuário excluído.";
        }
    }
}

$usuarios = [];
$resultUsuarios = mysqli_query($conexao, "SELECT id, nome, usuario, nivel, ativo, criado_em, ultimo_login FROM admin_usuarios ORDER BY criado_em ASC");
if ($resultUsuarios) {
    while ($linha = mysqli_fetch_assoc($resultUsuarios)) {
        $usuarios[] = $linha;
    }
}

// ===================== USUÁRIOS DO PAINEL (cadeia de comando) =====================

$cargosCA = listarChavesCargos($conexao, 'painel', 'ca');
$cargosEsquadrao = listarChavesCargos($conexao, 'painel', 'esquadrao');
$todosCargosLista = listarCargos($conexao, 'painel');
$niveisLista = listarCargos($conexao, 'ikarus37');

$mensagemPainel = null;
$erroPainel = null;

function contarCmdCaAtivos($conexao) {
    $r = mysqli_query($conexao, "SELECT COUNT(*) as total FROM painel_usuarios WHERE cargo = 'CMD_CA' AND ativo = 1");
    return (int) mysqli_fetch_assoc($r)['total'];
}

// ---------- CRIAR ----------
if ($podeGerenciarPainel && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'painel_criar') {
    $nome = trim($_POST['nome'] ?? '');
    $usuario = trim($_POST['usuario'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $cargo = $_POST['cargo'] ?? '';
    $esquadrao = trim($_POST['esquadrao'] ?? '');

    $erroComprimentoPainel = validarComprimentos(
        ['nome' => $nome, 'usuario' => $usuario, 'senha' => $senha, 'esquadrao' => $esquadrao],
        PAINEL_USUARIO_LIMITES_CAMPOS_LOCAL
    );

    if ($nome === '' || $usuario === '' || $senha === '') {
        $erroPainel = "Preencha nome, usuário e senha.";
    } elseif ($erroComprimentoPainel) {
        $erroPainel = $erroComprimentoPainel;
    } elseif (!cargoValido($conexao, 'painel', $cargo)) {
        $erroPainel = "Cargo inválido.";
    } elseif (in_array($cargo, $cargosEsquadrao) && $esquadrao === '') {
        $erroPainel = "Cargos de esquadrão exigem informar o esquadrão.";
    } else {
        $esquadraoFinal = in_array($cargo, $cargosCA) ? null : $esquadrao;
        $usuarioEsc = mysqli_real_escape_string($conexao, $usuario);
        $existe = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT id FROM painel_usuarios WHERE usuario = '$usuarioEsc'"));

        if ($existe) {
            $erroPainel = "Já existe um usuário do painel com esse login.";
        } else {
            $hash = password_hash($senha, PASSWORD_BCRYPT);
            $stmt = mysqli_prepare($conexao, "INSERT INTO painel_usuarios (nome, usuario, senha_hash, cargo, esquadrao) VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "sssss", $nome, $usuario, $hash, $cargo, $esquadraoFinal);
            mysqli_stmt_execute($stmt);
            $mensagemPainel = "Usuário do painel criado com sucesso.";
        }
    }
}

// ---------- ATUALIZAR ----------
if ($podeGerenciarPainel && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'painel_atualizar') {
    $id = (int)$_POST['id'];
    $nome = trim($_POST['nome'] ?? '');
    $cargo = $_POST['cargo'] ?? '';
    $esquadrao = trim($_POST['esquadrao'] ?? '');
    $ativo = isset($_POST['ativo']) ? 1 : 0;

    $alvo = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT * FROM painel_usuarios WHERE id = $id"));
    $erroComprimentoPainel = validarComprimentos(['nome' => $nome, 'esquadrao' => $esquadrao], PAINEL_USUARIO_LIMITES_CAMPOS_LOCAL);

    if (!$alvo) {
        $erroPainel = "Usuário do painel não encontrado.";
    } elseif ($erroComprimentoPainel) {
        $erroPainel = $erroComprimentoPainel;
    } elseif ($alvo['cargo'] === 'CMD_CA' && ($cargo !== 'CMD_CA' || $ativo === 0) && contarCmdCaAtivos($conexao) <= 1) {
        $erroPainel = "Não é possível rebaixar ou desativar o último CMD_CA ativo.";
    } elseif (in_array($cargo, $cargosEsquadrao) && $esquadrao === '') {
        $erroPainel = "Cargos de esquadrão exigem informar o esquadrão.";
    } else {
        $esquadraoFinal = in_array($cargo, $cargosCA) ? null : $esquadrao;
        $stmt = mysqli_prepare($conexao, "UPDATE painel_usuarios SET nome = ?, cargo = ?, esquadrao = ?, ativo = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "sssii", $nome, $cargo, $esquadraoFinal, $ativo, $id);
        mysqli_stmt_execute($stmt);
        $mensagemPainel = "Usuário do painel atualizado.";
    }
}

// ---------- RESETAR SENHA ----------
if ($podeGerenciarPainel && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'painel_resetar_senha') {
    $id = (int)$_POST['id'];
    $novaSenha = $_POST['nova_senha'] ?? '';

    if (strlen($novaSenha) < 6) {
        $erroPainel = "A nova senha precisa ter pelo menos 6 caracteres.";
    } elseif (mb_strlen($novaSenha) > 200) {
        $erroPainel = "A nova senha excede o tamanho máximo permitido (200 caracteres).";
    } else {
        $hash = password_hash($novaSenha, PASSWORD_BCRYPT);
        $stmt = mysqli_prepare($conexao, "UPDATE painel_usuarios SET senha_hash = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "si", $hash, $id);
        mysqli_stmt_execute($stmt);
        $mensagemPainel = "Senha do painel redefinida com sucesso.";
    }
}

// ---------- EXCLUIR ----------
if ($podeGerenciarPainel && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'painel_excluir') {
    $id = (int)$_POST['id'];
    $alvo = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT * FROM painel_usuarios WHERE id = $id"));

    if ($alvo && $alvo['cargo'] === 'CMD_CA' && contarCmdCaAtivos($conexao) <= 1) {
        $erroPainel = "Não é possível excluir o último CMD_CA ativo.";
    } else {
        mysqli_query($conexao, "DELETE FROM painel_usuarios WHERE id = $id");
        $mensagemPainel = "Usuário do painel excluído.";
    }
}

$usuariosPainel = mysqli_fetch_all(mysqli_query($conexao, "SELECT * FROM painel_usuarios ORDER BY criado_em ASC"), MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Ikarus37 — Usuários administradores</title>
<style>
    :root {
        --bg: #0f1115; --bg-card: #171a21; --border: #2a2e37;
        --text: #e6e8eb; --text-muted: #9aa1ac; --accent: #4f8cff; --danger: #ff5f5f; --ok: #4caf7d;
    }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); }
    .topbar { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid var(--border); }
    .topbar .brand { font-weight: 600; }
    .topbar a { color: var(--text-muted); text-decoration: none; font-size: 13px; margin-left: 16px; }
    .topbar a:hover { color: var(--text); }
    .container { padding: 24px; max-width: 1100px; margin: 0 auto; }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
    input, select { padding: 8px 10px; background: #0f1115; border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 13px; }
    button { padding: 8px 14px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; }
    button.danger { background: var(--danger); }
    button.ghost { background: transparent; border: 1px solid var(--border); color: var(--text-muted); }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 8px 10px; text-align: left; vertical-align: top; }
    th { background: #1d212a; color: var(--text-muted); }
    .erro { color: var(--danger); font-size: 13px; }
    .ok { color: var(--ok); font-size: 13px; }
    .form-linha { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 10px; }
    .badge { font-size: 11px; padding: 2px 8px; border-radius: 999px; }
    .badge.ativo { background: #17301f; color: var(--ok); }
    .badge.inativo { background: #301717; color: var(--danger); }
    .scroll-x { overflow-x: auto; }
    .acoes-form { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 6px; }
    .i { display: inline-block; width: 13px; height: 13px; vertical-align: -2px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 4px; }
    .campo-senha { position: relative; display: inline-block; }
    .campo-senha input { padding-right: 34px !important; }
    .campo-senha .toggle-senha { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text-muted); background: none; border: none; padding: 0; width: 18px; }
    .campo-senha .toggle-senha:hover { color: var(--text); }
</style>
</head>
<body>

<div class="topbar">
    <div class="brand">IKARUS37 <a href="index.php"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span>painel</a></div>
    <div><a href="index.php?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a></div>
</div>

<div class="container">
    <h2>Usuários administradores</h2>

    <?php if ($erro): ?><p class="erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($mensagem): ?><p class="ok"><?= htmlspecialchars($mensagem) ?></p><?php endif; ?>

    <?php if ($souSuperAdmin): ?>
    <div class="card">
        <h4>Novo administrador</h4>
        <form method="post">
            <input type="hidden" name="acao" value="criar">
            <div class="form-linha">
                <input type="text" name="nome" placeholder="Nome" required>
                <input type="text" name="usuario" placeholder="Usuário (login)" required>
                <div class="campo-senha">
                    <input type="password" name="senha" id="campo_senha_admin" placeholder="Senha" required>
                    <button type="button" class="toggle-senha" onclick="alternarSenha('campo_senha_admin', this)" aria-label="Mostrar senha">
                        <span class="i" style="--icon-url:url('../images/icons/eye.svg'); margin:0;"></span>
                    </button>
                </div>
                <select name="nivel">
                    <?php foreach ($niveisLista as $n): ?>
                        <option value="<?= htmlspecialchars($n['chave']) ?>" <?= $n['chave'] === 'admin' ? 'selected' : '' ?>><?= htmlspecialchars($n['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Criar</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="card">
        <h4>Contas existentes</h4>
        <div class="scroll-x">
        <table>
            <tr>
                <th>Nome</th><th>Usuário</th><th>Nível</th><th>Status</th><th>Criado em</th><th>Último login</th>
                <?php if ($souSuperAdmin): ?><th>Ações</th><?php endif; ?>
            </tr>
            <?php foreach ($usuarios as $u): ?>
                <?php $formId = 'form_edit_' . $u['id']; ?>
                <?php if ($souSuperAdmin): ?>
                    <form id="<?= $formId ?>" method="post">
                        <input type="hidden" name="acao" value="atualizar">
                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                    </form>
                <?php endif; ?>
                <tr>
                    <?php if ($souSuperAdmin): ?>
                        <td><input form="<?= $formId ?>" type="text" name="nome" value="<?= htmlspecialchars($u['nome']) ?>"></td>
                        <td><?= htmlspecialchars($u['usuario']) ?><?= $u['id'] == $meuId ? ' <small style="color:var(--text-muted)">(você)</small>' : '' ?></td>
                        <td>
                            <select form="<?= $formId ?>" name="nivel">
                                <?php foreach ($niveisLista as $n): ?>
                                    <option value="<?= htmlspecialchars($n['chave']) ?>" <?= $u['nivel'] === $n['chave'] ? 'selected' : '' ?>><?= htmlspecialchars($n['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <label style="font-size:12px;">
                                <input form="<?= $formId ?>" type="checkbox" name="ativo" <?= $u['ativo'] ? 'checked' : '' ?>>
                                <span class="badge <?= $u['ativo'] ? 'ativo' : 'inativo' ?>"><?= $u['ativo'] ? 'ativo' : 'inativo' ?></span>
                            </label>
                        </td>
                        <td><?= htmlspecialchars($u['criado_em']) ?></td>
                        <td><?= htmlspecialchars($u['ultimo_login'] ?? '—') ?></td>
                        <td>
                            <div class="acoes-form">
                                <button form="<?= $formId ?>" type="submit">Salvar</button>
                                <button type="button" class="ghost" onclick="redefinirSenha('resetar_senha', <?= $u['id'] ?>)">redefinir senha</button>
                                <?php if ($u['id'] != $meuId): ?>
                                <button type="button" class="danger" onclick="excluirUsuario('excluir', <?= $u['id'] ?>, 'Excluir este usuário?')"><span class="i" style="--icon-url:url('../images/icons/trash.svg')"></span>Excluir</button>
                                <?php endif; ?>
                            </div>
                        </td>
                    <?php else: ?>
                        <td><?= htmlspecialchars($u['nome']) ?></td>
                        <td><?= htmlspecialchars($u['usuario']) ?></td>
                        <td><?= htmlspecialchars($u['nivel']) ?></td>
                        <td><span class="badge <?= $u['ativo'] ? 'ativo' : 'inativo' ?>"><?= $u['ativo'] ? 'ativo' : 'inativo' ?></span></td>
                        <td><?= htmlspecialchars($u['criado_em']) ?></td>
                        <td><?= htmlspecialchars($u['ultimo_login'] ?? '—') ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
    </div>

    <h2 style="margin-top:36px;">Usuários do painel (cadeia de comando)</h2>
    <p style="color: var(--text-muted); font-size: 13px; margin-top:-8px;">
        Contas de acesso ao <code>/painel</code> — CMD_CA, SUBCMD_CA e os cargos de esquadrão. Sistema de login separado do <code>admin_usuarios</code> acima.
    </p>

    <?php if ($erroPainel): ?><p class="erro"><?= htmlspecialchars($erroPainel) ?></p><?php endif; ?>
    <?php if ($mensagemPainel): ?><p class="ok"><?= htmlspecialchars($mensagemPainel) ?></p><?php endif; ?>

    <?php if (!$podeGerenciarPainel): ?>
        <p class="erro">Sua conta não tem a permissão 'gerenciar_usuarios_painel'.</p>
    <?php endif; ?>

    <?php if ($podeGerenciarPainel): ?>
    <div class="card">
        <h4>Novo usuário do painel</h4>
        <form method="post">
            <input type="hidden" name="acao" value="painel_criar">
            <div class="form-linha">
                <input type="text" name="nome" placeholder="Nome" required>
                <input type="text" name="usuario" placeholder="Usuário (login)" required>
                <div class="campo-senha">
                    <input type="password" name="senha" id="campo_senha_painel" placeholder="Senha" required>
                    <button type="button" class="toggle-senha" onclick="alternarSenha('campo_senha_painel', this)" aria-label="Mostrar senha">
                        <span class="i" style="--icon-url:url('../images/icons/eye.svg'); margin:0;"></span>
                    </button>
                </div>
                <select name="cargo" onchange="alternarEsquadraoNovo(this)">
                    <?php foreach ($todosCargosLista as $c): ?>
                        <option value="<?= htmlspecialchars($c['chave']) ?>" <?= $c['chave'] === 'CMD_ESQUADRAO' ? 'selected' : '' ?>><?= htmlspecialchars($c['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" id="novo_esquadrao" name="esquadrao" placeholder="Esquadrão (ex: PRATA)">
                <button type="submit">Criar</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="card">
        <h4>Contas existentes</h4>
        <div class="scroll-x">
        <table>
            <tr>
                <th>Nome</th><th>Usuário</th><th>Cargo</th><th>Esquadrão</th><th>Status</th><th>Último login</th>
                <?php if ($podeGerenciarPainel): ?><th>Ações</th><?php endif; ?>
            </tr>
            <?php foreach ($usuariosPainel as $u): ?>
                <?php $formId = 'form_painel_' . $u['id']; ?>
                <?php if ($podeGerenciarPainel): ?>
                    <form id="<?= $formId ?>" method="post">
                        <input type="hidden" name="acao" value="painel_atualizar">
                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                    </form>
                <?php endif; ?>
                <tr>
                    <?php if ($podeGerenciarPainel): ?>
                        <td><input form="<?= $formId ?>" type="text" name="nome" value="<?= htmlspecialchars($u['nome']) ?>"></td>
                        <td><?= htmlspecialchars($u['usuario']) ?></td>
                        <td>
                            <select form="<?= $formId ?>" name="cargo" onchange="alternarEsquadraoLinha(this, 'esq_painel_<?= $u['id'] ?>')">
                                <?php foreach ($todosCargosLista as $c): ?>
                                    <option value="<?= htmlspecialchars($c['chave']) ?>" <?= $u['cargo'] === $c['chave'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input form="<?= $formId ?>" id="esq_painel_<?= $u['id'] ?>" type="text" name="esquadrao" value="<?= htmlspecialchars($u['esquadrao'] ?? '') ?>"
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
                            <div class="acoes-form">
                                <button form="<?= $formId ?>" type="submit">Salvar</button>
                                <button type="button" class="ghost" onclick="redefinirSenha('painel_resetar_senha', <?= $u['id'] ?>)">redefinir senha</button>
                                <button type="button" class="danger" onclick="excluirUsuario('painel_excluir', <?= $u['id'] ?>, 'Excluir este usuário do painel?')"><span class="i" style="--icon-url:url('../images/icons/trash.svg')"></span>Excluir</button>
                            </div>
                        </td>
                    <?php else: ?>
                        <td><?= htmlspecialchars($u['nome']) ?></td>
                        <td><?= htmlspecialchars($u['usuario']) ?></td>
                        <td><?= htmlspecialchars($u['cargo']) ?></td>
                        <td><?= htmlspecialchars($u['esquadrao'] ?? '—') ?></td>
                        <td><span class="badge <?= $u['ativo'] ? 'ativo' : 'inativo' ?>"><?= $u['ativo'] ? 'ativo' : 'inativo' ?></span></td>
                        <td><?= htmlspecialchars($u['ultimo_login'] ?? '—') ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
    </div>
</div>

<script>
    const CARGOS_ESQUADRAO = <?= json_encode(array_values($cargosEsquadrao)) ?>;

    function alternarSenha(id, botao) {
        const campo = document.getElementById(id);
        const oculto = campo.type === 'password';
        campo.type = oculto ? 'text' : 'password';
        botao.querySelector('.i').style.setProperty('--icon-url', oculto ? "url('../images/icons/eye-off.svg')" : "url('../images/icons/eye.svg')");
        botao.setAttribute('aria-label', oculto ? 'Ocultar senha' : 'Mostrar senha');
    }

    function alternarEsquadraoNovo(select) {
        document.getElementById('novo_esquadrao').style.display = CARGOS_ESQUADRAO.includes(select.value) ? 'inline-block' : 'none';
    }

    function alternarEsquadraoLinha(select, campoId) {
        document.getElementById(campoId).style.display = CARGOS_ESQUADRAO.includes(select.value) ? 'inline-block' : 'none';
    }

    function enviarAcao(campos) {
        const form = document.createElement('form');
        form.method = 'post';
        for (const [nome, valor] of Object.entries(campos)) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = nome;
            input.value = valor;
            form.appendChild(input);
        }
        document.body.appendChild(form);
        form.submit();
    }

    function redefinirSenha(acao, id) {
        const novaSenha = prompt('Nova senha (mínimo 6 caracteres):');
        if (!novaSenha) return;
        enviarAcao({ acao, id, nova_senha: novaSenha });
    }

    function excluirUsuario(acao, id, mensagem) {
        if (!confirm(mensagem)) return;
        enviarAcao({ acao, id });
    }
</script>

<?php include __DIR__ . '/../../core/rodape_versao.php'; ?>
</body>
</html>
<?php mysqli_close($conexao); ?>
