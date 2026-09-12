<?php

require_once __DIR__ . '/../config.php';

session_start();

$erroLogin = null;

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['usuario'], $_POST['senha'])) {
    $conexao = conectarBanco();

    $usuarioDigitado = mysqli_real_escape_string($conexao, $_POST['usuario']);
    $resultado = mysqli_query($conexao, "SELECT id, nome, usuario, senha_hash, cargo, esquadrao, ativo FROM painel_usuarios WHERE usuario = '$usuarioDigitado' LIMIT 1");
    $usuario = $resultado ? mysqli_fetch_assoc($resultado) : null;

    if ($usuario && (int)$usuario['ativo'] === 1 && password_verify($_POST['senha'], $usuario['senha_hash'])) {
        $_SESSION['painel_id'] = $usuario['id'];
        $_SESSION['painel_nome'] = $usuario['nome'];
        $_SESSION['painel_usuario'] = $usuario['usuario'];
        $_SESSION['painel_cargo'] = $usuario['cargo'];
        $_SESSION['painel_esquadrao'] = $usuario['esquadrao'];
        $_SESSION['painel_origem'] = 'painel_usuarios';

        mysqli_query($conexao, "UPDATE painel_usuarios SET ultimo_login = NOW() WHERE id = " . (int)$usuario['id']);

        mysqli_close($conexao);
        header("Location: index.php");
        exit;
    } else {
        // Ponte: quem tem conta técnica no Ikarus37 também acessa o painel, como Administrador Técnico.
        $resultadoAdmin = mysqli_query($conexao, "SELECT id, nome, usuario, senha_hash, ativo FROM admin_usuarios WHERE usuario = '$usuarioDigitado' LIMIT 1");
        $admin = $resultadoAdmin ? mysqli_fetch_assoc($resultadoAdmin) : null;

        if ($admin && (int)$admin['ativo'] === 1 && password_verify($_POST['senha'], $admin['senha_hash'])) {
            $_SESSION['painel_id'] = 'admin_' . $admin['id'];
            $_SESSION['painel_nome'] = $admin['nome'];
            $_SESSION['painel_usuario'] = $admin['usuario'];
            $_SESSION['painel_cargo'] = 'ADMIN_TECNICO';
            $_SESSION['painel_esquadrao'] = null;
            $_SESSION['painel_origem'] = 'ikarus37';

            mysqli_close($conexao);
            header("Location: index.php");
            exit;
        }

        $erroLogin = "Usuário ou senha inválidos.";
    }

    mysqli_close($conexao);
}

require_once __DIR__ . '/auth_helpers.php';

$logado = !empty($_SESSION['painel_id']);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Painel — Argos</title>
<style>
    :root {
        --bg: #0d1117; --bg-card: #161b22; --border: #30363d;
        --text: #e6edf3; --text-muted: #8b949e; --accent: #4f8cff; --danger: #ff5f5f;
    }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); }
    .login-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; }
    .login-box { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 32px; width: 100%; max-width: 340px; }
    .login-box h1 { font-size: 20px; margin: 0 0 4px; }
    .login-box p.sub { color: var(--text-muted); font-size: 13px; margin: 0 0 20px; }
    .login-box input { width: 100%; padding: 10px 12px; margin-bottom: 12px; background: #0d1117; border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 14px; }
    .login-box button { width: 100%; padding: 10px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 14px; cursor: pointer; }
    .erro { color: var(--danger); font-size: 13px; margin-bottom: 12px; }

    .topbar { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid var(--border); }
    .topbar .brand { font-weight: 600; }
    .topbar .brand span { color: var(--text-muted); font-weight: 400; font-size: 13px; margin-left: 8px; }
    .topbar a { color: var(--text-muted); text-decoration: none; font-size: 13px; }
    .topbar a:hover { color: var(--text); }
    .container { padding: 24px; max-width: 1100px; margin: 0 auto; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; margin-top: 20px; }
    .card {
        display: block;
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 20px;
        text-decoration: none;
        color: inherit;
        cursor: pointer;
        transition: border-color 0.15s, transform 0.15s;
    }
    .card:hover {
        border-color: var(--accent);
        transform: translateY(-2px);
    }
    .card-icon {
        display: block;
        width: 26px;
        height: 26px;
        margin-bottom: 12px;
        background-color: var(--accent);
        -webkit-mask-image: var(--icon-url);
        mask-image: var(--icon-url);
        -webkit-mask-size: contain;
        mask-size: contain;
        -webkit-mask-repeat: no-repeat;
        mask-repeat: no-repeat;
        -webkit-mask-position: center;
        mask-position: center;
    }
    .card h3 { margin: 0 0 6px; font-size: 15px; }
    .card p { margin: 0; color: var(--text-muted); font-size: 13px; }
</style>
</head>
<body>

<?php if (!$logado): ?>

    <div class="login-wrap">
        <div class="login-box">
            <h1>Argos</h1>
            <p class="sub">Painel de comando — Corpo de Alunos</p>
            <?php if ($erroLogin): ?><p class="erro"><?= htmlspecialchars($erroLogin) ?></p><?php endif; ?>
            <form method="post">
                <input type="text" name="usuario" placeholder="Usuário" autofocus required>
                <input type="password" name="senha" placeholder="Senha" required>
                <button type="submit">Entrar</button>
            </form>
        </div>
    </div>

<?php else: ?>

    <div class="topbar">
        <div class="brand">ARGOS <span><?= htmlspecialchars($_SESSION['painel_nome']) ?> · <?= htmlspecialchars(nomeCargo($_SESSION['painel_cargo'])) ?><?= $_SESSION['painel_esquadrao'] ? ' · Esquadrão ' . htmlspecialchars($_SESSION['painel_esquadrao']) : '' ?><?= ($_SESSION['painel_origem'] ?? '') === 'ikarus37' ? ' · via Ikarus37' : '' ?></span></div>
        <a href="?logout=1">sair</a>
    </div>

    <div class="container">
        <h2>Painel de comando</h2>
        <p style="color: var(--text-muted); font-size: 14px;">
            <?= escopoEsquadrao() ? 'Visão restrita ao Esquadrão ' . htmlspecialchars(escopoEsquadrao()) : 'Visão de todo o Corpo de Alunos' ?>
        </p>

        <?php $conexao = conectarBanco(); ?>
        <div class="grid">
            <?php if (temPermissao($conexao, 'painel', $_SESSION['painel_cargo'], 'registrar_retirada')): ?>
            <a href="retiradas.php" class="card">
                <span class="card-icon" style="--icon-url: url('../images/icons/clipboard-check.svg')"></span>
                <h3>Retiradas de falta</h3>
                <p>Abrir chamada, marcar presença/falta e enviar.</p>
            </a>
            <?php endif; ?>
            <a href="efetivo.php" class="card">
                <span class="card-icon" style="--icon-url: url('../images/icons/users.svg')"></span>
                <h3>Efetivo</h3>
                <p>Consulta e gestão dos alunos<?= escopoEsquadrao() ? ' do seu esquadrão' : '' ?>.</p>
            </a>
            <a href="relatorios.php" class="card">
                <span class="card-icon" style="--icon-url: url('../images/icons/report.svg')"></span>
                <h3>Relatório de retiradas</h3>
                <p>Presenças, faltas e motivos por período<?= escopoEsquadrao() ? ', esquadrão ' . htmlspecialchars(escopoEsquadrao()) : '' ?>.</p>
            </a>
            <?php if (escopoEsquadrao() === null): ?>
            <a href="grupos.php" class="card">
                <span class="card-icon" style="--icon-url: url('../images/icons/users-group.svg')"></span>
                <h3>Grupos</h3>
                <p>Gerenciar quem pertence a grupos que cruzam esquadrões.</p>
            </a>
            <?php endif; ?>
            <?php if (podeGerenciarUsuarios()): ?>
            <a href="usuarios.php" class="card">
                <span class="card-icon" style="--icon-url: url('../images/icons/user-cog.svg')"></span>
                <h3>Usuários do painel</h3>
                <p>Cadastro de comandantes, encarregados e auxiliares.</p>
            </a>
            <?php endif; ?>
        </div>
        <?php mysqli_close($conexao); ?>
    </div>

<?php endif; ?>

</body>
</html>
