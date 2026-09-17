<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/login_seguranca_core.php';

session_start();

$erroLogin = null;

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['usuario'], $_POST['senha'])) {
    $conexao = conectarBanco();

    $ipRequisicao = $_SERVER['REMOTE_ADDR'] ?? '';
    $usuarioBruto = mb_substr(trim($_POST['usuario']), 0, 50);

    // Corta ANTES de qualquer consulta ou password_verify() — um POST bruto
    // (fora do <input maxlength> do HTML) pode mandar uma senha de
    // megabytes só pra gastar processamento do servidor (issue #25).
    $senhaOversized = strlen($_POST['senha']) > 200;

    $bloqueadoAte = $senhaOversized ? null : (loginIpBloqueado($conexao, $ipRequisicao)
        ?? loginContaBloqueada($conexao, 'painel_usuarios', $usuarioBruto)
        ?? loginContaBloqueada($conexao, 'admin_usuarios', $usuarioBruto));

    if ($senhaOversized) {
        loginRegistrarFalhaIp($conexao, $ipRequisicao);
        $erroLogin = "Usuário ou senha inválidos.";
        mysqli_close($conexao);
    } elseif ($bloqueadoAte) {
        $erroLogin = "Muitas tentativas erradas. Tente de novo depois de " . htmlspecialchars($bloqueadoAte) . ".";
        mysqli_close($conexao);
    } else {
        $usuarioDigitado = mysqli_real_escape_string($conexao, $usuarioBruto);
        $resultado = mysqli_query($conexao, "SELECT id, nome, usuario, senha_hash, cargo, esquadrao, ativo FROM painel_usuarios WHERE usuario = '$usuarioDigitado' LIMIT 1");
        $usuario = $resultado ? mysqli_fetch_assoc($resultado) : null;

        if ($usuario && (int)$usuario['ativo'] === 1 && password_verify($_POST['senha'], $usuario['senha_hash'])) {
            $_SESSION['painel_id'] = $usuario['id'];
            $_SESSION['painel_nome'] = $usuario['nome'];
            $_SESSION['painel_usuario'] = $usuario['usuario'];
            $_SESSION['painel_cargo'] = $usuario['cargo'];
            $_SESSION['painel_esquadrao'] = $usuario['esquadrao'];
            $_SESSION['painel_origem'] = 'painel_usuarios';

            loginResetarTentativas($conexao, 'painel_usuarios', $usuario['id']);
            loginResetarTentativasIp($conexao, $ipRequisicao);
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

                loginResetarTentativas($conexao, 'admin_usuarios', $admin['id']);
                loginResetarTentativasIp($conexao, $ipRequisicao);
                mysqli_close($conexao);
                header("Location: index.php");
                exit;
            }

            if ($usuario) {
                loginRegistrarFalha($conexao, 'painel_usuarios', $usuario['usuario']);
            } elseif ($admin) {
                loginRegistrarFalha($conexao, 'admin_usuarios', $admin['usuario']);
            }
            loginRegistrarFalhaIp($conexao, $ipRequisicao);
            $erroLogin = "Usuário ou senha inválidos.";
        }

        mysqli_close($conexao);
    }
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
        --bg: #f4f7fc; --bg-card: #ffffff; --border: #dbe3ef;
        --text: #16202e; --text-muted: #55637a; --accent: #2f6fed;
        --azul-eear: #0a2e5c; --danger: #c0392b; --ok: #1f8a4c;
    }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); }
    .login-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; }
    .login-box { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 32px; width: 100%; max-width: 340px; }
    .login-box h1 { font-size: 20px; margin: 0 0 4px; }
    .login-box p.sub { color: var(--text-muted); font-size: 13px; margin: 0 0 20px; }
    .login-box input { width: 100%; padding: 10px 12px; margin-bottom: 12px; background: #ffffff; border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 14px; }
    .campo-senha { position: relative; margin-bottom: 12px; }
    .campo-senha input { padding-right: 40px !important; margin-bottom: 0 !important; }
    .campo-senha .toggle-senha { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text-muted); background: none; border: none; padding: 0; width: 20px; }
    .campo-senha .toggle-senha:hover { color: var(--text); }
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
    .i { display: inline-block; width: 13px; height: 13px; vertical-align: -2px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 4px; }
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
                <input type="text" name="usuario" placeholder="Usuário" maxlength="50" autofocus required>
                <div class="campo-senha">
                    <input type="password" name="senha" id="campo_senha_login" placeholder="Senha" maxlength="200" required>
                    <button type="button" class="toggle-senha" onclick="alternarSenha('campo_senha_login', this)" aria-label="Mostrar senha">
                        <span class="i" style="--icon-url:url('../images/icons/eye.svg'); margin:0;"></span>
                    </button>
                </div>
                <button type="submit">Entrar</button>
            </form>
            <script>
                function alternarSenha(id, botao) {
                    const campo = document.getElementById(id);
                    const oculto = campo.type === 'password';
                    campo.type = oculto ? 'text' : 'password';
                    botao.querySelector('.i').style.setProperty('--icon-url', oculto ? "url('../images/icons/eye-off.svg')" : "url('../images/icons/eye.svg')");
                    botao.setAttribute('aria-label', oculto ? 'Ocultar senha' : 'Mostrar senha');
                }
            </script>
        </div>
    </div>

<?php else: ?>

    <div class="topbar">
        <div class="brand">ARGOS <span><?= htmlspecialchars($_SESSION['painel_nome']) ?> · <?= htmlspecialchars(nomeCargo($_SESSION['painel_cargo'])) ?><?= $_SESSION['painel_esquadrao'] ? ' · Esquadrão ' . htmlspecialchars($_SESSION['painel_esquadrao']) : '' ?><?= ($_SESSION['painel_origem'] ?? '') === 'ikarus37' ? ' · via Ikarus37' : '' ?></span></div>
        <a href="?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a>
    </div>

    <div class="container">
        <h2>Painel de comando</h2>
        <p style="color: var(--text-muted); font-size: 14px;">
            <?= escopoEsquadrao() ? 'Visão restrita ao Esquadrão ' . htmlspecialchars(escopoEsquadrao()) : 'Visão de todo o Corpo de Alunos' ?>
        </p>

        <?php $conexao = conectarBanco(); ?>
        <div class="grid">
            <?php if (temPermissao($conexao, 'painel', $_SESSION['painel_cargo'], 'registrar_retirada', idUsuarioPainel())): ?>
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
            <a href="situacao.php" class="card">
                <span class="card-icon" style="--icon-url: url('../images/icons/map-pin.svg')"></span>
                <h3>Situação do efetivo</h3>
                <p>Onde cada aluno está agora — presente, ausente e por qual motivo.</p>
            </a>
            <a href="dashboard.php" class="card">
                <span class="card-icon" style="--icon-url: url('../images/icons/chart-bar.svg')"></span>
                <h3>Painel Argos</h3>
                <p>Gráficos, quantidades e tendências — dados reais pra analisar e exportar.</p>
            </a>
            <a href="relatorios.php" class="card">
                <span class="card-icon" style="--icon-url: url('../images/icons/report.svg')"></span>
                <h3>Relatório de retiradas</h3>
                <p>Presenças, faltas e motivos por período<?= escopoEsquadrao() ? ', esquadrão ' . htmlspecialchars(escopoEsquadrao()) : '' ?>.</p>
            </a>
            <a href="livro_do_dia.php" class="card">
                <span class="card-icon" style="--icon-url: url('../images/icons/book.svg')"></span>
                <h3>Livro do Dia</h3>
                <p>Resumo do dia no formato do Livro de Serviço — pronto pra baixar em PDF.</p>
            </a>
            <?php if (podeGerenciarDispensas()): ?>
            <a href="dispensas.php" class="card">
                <span class="card-icon" style="--icon-url: url('../images/icons/medical-cross.svg')"></span>
                <h3>Dispensas médicas</h3>
                <p>Cadastro de dispensa com período — sugerida automaticamente na chamada.</p>
            </a>
            <?php endif; ?>
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
            <?php if (podeGerenciarDominio()): ?>
            <a href="dominio.php" class="card">
                <span class="card-icon" style="--icon-url: url('../images/icons/sitemap.svg')"></span>
                <h3>Controle do Domínio de Negócio</h3>
                <p>Unidades organizacionais e grupos de acesso — sem precisar mexer no banco.</p>
            </a>
            <?php endif; ?>
        </div>
        <?php mysqli_close($conexao); ?>
    </div>

<?php endif; ?>

<?php include __DIR__ . '/../../core/rodape_versao.php'; ?>
</body>
</html>
