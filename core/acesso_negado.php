<?php

// Tela padrão de "acesso negado", pra substituir os die() crus que
// derrubavam a página com texto sem estilo nenhum quando faltava permissão.
// Uso: require_once __DIR__ . '/../../core/acesso_negado.php'; depois
// exibirAcessoNegado("Sua conta não tem a permissão 'x'.", 'index.php');

function exibirAcessoNegado($mensagem, $linkVoltar = 'index.php', $rotuloVoltar = 'Voltar') {
    http_response_code(403);
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Acesso negado</title>
<style>
    :root { --bg: #f4f7fc; --bg-card: #ffffff; --border: #dbe3ef; --text: #16202e; --text-muted: #55637a; --danger: #c0392b; }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); padding: 16px; }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 32px; max-width: 420px; width: 100%; text-align: center; }
    .card .icone { font-size: 32px; margin-bottom: 8px; }
    .card h2 { margin: 0 0 8px; color: var(--danger); font-size: 18px; }
    .card p { color: var(--text-muted); font-size: 14px; line-height: 1.5; }
    .card a { display: inline-block; margin-top: 16px; padding: 10px 20px; background: var(--danger); color: #fff; text-decoration: none; border-radius: 6px; font-size: 14px; }
</style>
</head>
<body>
    <div class="card">
        <div class="icone">🔒</div>
        <h2>Acesso negado</h2>
        <p><?= htmlspecialchars($mensagem) ?></p>
        <a href="<?= htmlspecialchars($linkVoltar) ?>"><?= htmlspecialchars($rotuloVoltar) ?></a>
    </div>
</body>
</html>
<?php
    exit;
}
