<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/Database/DbConnection.php';
require_once __DIR__ . '/../api/Repository/User/UserRepository.php';
require_once __DIR__ . '/../Common/Constants/ControlPainel.Constants.php';
require_once __DIR__ . '/../Common/Constants/Default.Constants.php';


use api\Database\DbConnection\DbConnection;
use api\Repository\User\UserRepository;
use Common\Constants\ControlPainelConstants;
use Common\Constants\DefaultConstants;

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
        $db->closeConnection();
        error_log("Login attempt for user: {$_POST['usuario']} - 1");
        exit;
    }
    error_log("Login attempt for user: {$_POST['usuario']} - 2");
    $erroLogin = "Usuário ou senha inválidos.";
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
    <link rel="stylesheet" href="/css/control-panel.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <title><?= DefaultConstants::PROJECT_NAME ?> — Painel Argos</title>
</head>

<body>

    <?php if (!$logado): ?>

        <div class="login-wrap">
            <div class="login-box">
                <h1><?= DefaultConstants::PROJECT_NAME ?></h1>
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
            <div class="brand"><?= DefaultConstants::PROJECT_NAME ?> <span><?= htmlspecialchars($_SESSION['admin_nome']) ?> · <?= htmlspecialchars($_SESSION['admin_nivel']) ?></span></div>
            <a href="?logout=1">sair</a>
        </div>

        <div class="container">
            <div class="page-header">
                <div>
                    <span class="page-eyebrow">ADMINISTRAÇÃO</span>
                    <h1>Painel de controle</h1>
                    <p>Gerencie os módulos administrativos do sistema Argos.</p>
                </div>
            </div>

            <div class="container">
                <div class="grid">
                    <?php foreach (ControlPainelConstants::CARDS as $card): ?>
                        <a href="<?= htmlspecialchars($card['href']) ?>" class="card">
                            <div class="card-icon">
                                <i data-lucide="<?= htmlspecialchars($card['icon']) ?>"></i>
                            </div>

                            <div class="card-content">
                                <h3><?= htmlspecialchars($card['title']) ?></h3>
                                <p>
                                    <?= htmlspecialchars($card['description']) ?>
                                </p>
                            </div>

                            <div class="card-footer">
                                <span><?= htmlspecialchars($card['footer']) ?></span>
                                <span class="arrow"><i data-lucide="arrow-right"></i></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</body>
<script>
    lucide.createIcons();
</script>

</html>