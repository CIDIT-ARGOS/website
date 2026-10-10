<?php

// Controle dos QR codes dos alunos no Ikarus37. A tela em si é a mesma do
// Painel (core/qrcodes_tela.php); aqui ficam o login e a permissão do
// administrador técnico, que enxerga todos os esquadrões.

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/acesso_negado.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../core/qrcodes_tela.php';

$conexao = conectarBanco();

// Cadastrar QR é entregar a credencial do aluno: só administrador técnico avançado.
if (!temPermissao($conexao, 'ikarus37', $_SESSION['admin_nivel'], 'acesso_tecnico_avancado')) {
    exibirAcessoNegado("Sua conta não tem a permissão 'acesso_tecnico_avancado'.");
}

$estado = processarControleQr($conexao, null);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Ikarus37 — QR Codes</title>
<style>
    :root { --bg: #0f1115; --bg-card: #171a21; --border: #2a2e37; --text: #e6e8eb; --text-muted: #9aa1ac; --accent: #4f8cff; --danger: #ff5f5f; --ok: #4caf7d; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); overflow-x: hidden; }
    .topbar { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid var(--border); }
    .topbar .brand { font-weight: 600; }
    .topbar a { color: var(--text-muted); text-decoration: none; font-size: 13px; margin-left: 16px; }
    .container { padding: 24px; max-width: 1100px; margin: 0 auto; }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
    input, select, textarea { padding: 8px 10px; background: var(--bg); border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 13px; }
    button { padding: 8px 14px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 6px 10px; text-align: left; }
    th.num, td.num { text-align: center; }
    .erro { color: var(--danger); font-size: 13px; }
    .ok { color: var(--ok); font-size: 13px; }
    .form-linha { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
    .kpi { text-align: center; }
    .scroll-x { overflow-x: auto; min-width: 0; }
    .badge { font-size: 11px; padding: 2px 8px; border-radius: 4px; }
    .badge.ativo { background: rgba(76, 175, 125, .15); color: var(--ok); }
    .badge.inativo { background: rgba(255, 95, 95, .15); color: var(--danger); }
    .i { display: inline-block; width: 16px; height: 16px; vertical-align: -3px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 5px; }
</style>
<link rel="stylesheet" href="../css/argos-admin.css">
<link rel="stylesheet" href="../css/argos-ikarus.css">
<script src="../js/argos-admin.js" defer></script>
</head>
<body>

<div class="topbar">
    <div class="brand">IKARUS37 <a href="index.php"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span>painel</a></div>
    <div><a href="index.php?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a></div>
</div>

<div class="container">
    <?php renderizarControleQr($conexao, null, $estado, '../app/js/qr-scanner.js'); ?>
</div>

<?php include __DIR__ . '/../../core/rodape_versao.php'; ?>
</body>
</html>
<?php mysqli_close($conexao); ?>
