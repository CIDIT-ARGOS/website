<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/Database/DbConnection.php';
require_once __DIR__ . '/../api/Repository/User/UserRepository.php';


use api\Database\DbConnection\DbConnection;
use api\Repository\User\UserRepository;

session_start();

$erroLogin = null;

// ---------- LOGOUT ----------
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// ---------- LOGIN ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['usuario'], $_POST['senha'])) {
    // TODO: recuperar de VAR env
    // TODO: Retirar antes do commit
    $db = new DbConnection(
        "localhost",
        "argos",
        "argos",
        "argos"
    );
    $connection = $db->getConnection();
    $userRepository = new UserRepository();
    $admin = $userRepository->findByUser($_POST['usuario'], $db);
    if ($admin && (int)$admin['ativo'] === 1 && password_verify($_POST['senha'], $admin['senha_hash'])) {
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_nome'] = $admin['nome'];
        $_SESSION['admin_usuario'] = $admin['usuario'];
        $_SESSION['admin_nivel'] = $admin['nivel'];
        $userRepository->updateLastLogin((int)$admin['id'], $db);
        header("Location: index.php");
        exit;
    } else {
        $erroLogin = "Usuário ou senha inválidos.";
    }

    $db->closeConnection();
}

$logado = !empty($_SESSION['admin_id']);

?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Ikarus37 — Painel Argos</title>
    <style>
        :root {
            --bg: #0f1115;
            --bg-card: #171a21;
            --border: #2a2e37;
            --text: #e6e8eb;
            --text-muted: #9aa1ac;
            --accent: #4f8cff;
            --danger: #ff5f5f;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        .login-wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-box {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 32px;
            width: 100%;
            max-width: 340px;
        }

        .login-box h1 {
            font-size: 20px;
            margin: 0 0 4px;
        }

        .login-box p.sub {
            color: var(--text-muted);
            font-size: 13px;
            margin: 0 0 20px;
        }

        .login-box input {
            width: 100%;
            padding: 10px 12px;
            margin-bottom: 12px;
            background: #0f1115;
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--text);
            font-size: 14px;
        }

        .login-box button {
            width: 100%;
            padding: 10px;
            background: var(--accent);
            border: none;
            border-radius: 6px;
            color: #fff;
            font-size: 14px;
            cursor: pointer;
        }

        .erro {
            color: var(--danger);
            font-size: 13px;
            margin-bottom: 12px;
        }

        /* dashboard */
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 24px;
            border-bottom: 1px solid var(--border);
        }

        .topbar .brand {
            font-weight: 600;
        }

        .topbar .brand span {
            color: var(--text-muted);
            font-weight: 400;
            font-size: 13px;
            margin-left: 8px;
        }

        .topbar a {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 13px;
        }

        .topbar a:hover {
            color: var(--text);
        }

        .container {
            padding: 24px;
            max-width: 1100px;
            margin: 0 auto;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 16px;
            margin-top: 20px;
        }

        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 20px;
        }

        .card h3 {
            margin: 0 0 6px;
            font-size: 15px;
        }

        .card p {
            margin: 0;
            color: var(--text-muted);
            font-size: 13px;
        }

        .badge {
            display: inline-block;
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 999px;
            background: #2a2e37;
            color: var(--text-muted);
            margin-top: 10px;
        }
    </style>
</head>

<body>

    <?php if (!$logado): ?>

        <div class="login-wrap">
            <div class="login-box">
                <h1>Ikarus37</h1>
                <p class="sub">Painel administrativo — Projeto Argos</p>
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
            <div class="brand">IKARUS37 <span><?= htmlspecialchars($_SESSION['admin_nome']) ?> · <?= htmlspecialchars($_SESSION['admin_nivel']) ?></span></div>
            <a href="?logout=1">sair</a>
        </div>

        <div class="container">
            <h2>Painel de controle</h2>
            <p style="color: var(--text-muted); font-size: 14px;">Módulos administrativos do sistema Argos.</p>

            <div class="grid">
                <div class="card">
                    <h3>API</h3>
                    <p>Endpoints, chaves de acesso e monitoramento das requisições.</p>
                    <a href="api.php" class="badge" style="text-decoration:none; color: var(--accent);">abrir →</a>
                </div>
                <div class="card">
                    <h3>Banco de Dados</h3>
                    <p>Tabelas, estrutura e manutenção dos dados do sistema.</p>
                    <a href="banco.php" class="badge" style="text-decoration:none; color: var(--accent);">abrir →</a>
                </div>
                <div class="card">
                    <h3>Apps conectados</h3>
                    <p>Cada app conectado é uma chave de API — gerencie em "API" acima.</p>
                    <a href="api.php" class="badge" style="text-decoration:none; color: var(--accent);">abrir →</a>
                </div>
                <div class="card">
                    <h3>Usuários administradores</h3>
                    <p>Gestão de contas e níveis de permissão do painel.</p>
                    <a href="usuarios.php" class="badge" style="text-decoration:none; color: var(--accent);">abrir →</a>
                </div>
                <div class="card">
                    <h3>Backup do banco</h3>
                    <p>Baixar dump completo (estrutura + dados) antes de mudanças arriscadas.</p>
                    <a href="backup.php" class="badge" style="text-decoration:none; color: var(--accent);">abrir →</a>
                </div>
                <div class="card">
                    <h3>Painel de Comando</h3>
                    <p>Área operacional (efetivo, retiradas, relatórios) — sua conta de admin também acessa lá.</p>
                    <a href="../painel/" class="badge" style="text-decoration:none; color: var(--accent);">abrir →</a>
                </div>
            </div>
        </div>

    <?php endif; ?>

</body>

</html>