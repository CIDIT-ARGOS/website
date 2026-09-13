<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../core/painel_usuarios_core.php';

if (!podeGerenciarUsuarios()) {
    die("Seu cargo não tem permissão para gerenciar usuários do painel.");
}

$conexao = conectarBanco();
$meuId = (int)$_SESSION['painel_id'];

$mensagem = null;
$erro = null;

$cargosEsquadrao = CARGOS_ESQUADRAO;
$cargosCA = CARGOS_CA;
$todosCargos = todosOsCargosPainel();

// ---------- CRIAR ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar') {
    $resultado = criarUsuarioPainel($conexao, $_POST);
    if ($resultado['ok']) {
        $mensagem = "Usuário criado com sucesso.";
    } else {
        $erro = $resultado['erro'];
    }
}

// ---------- ATUALIZAR ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar') {
    $dados = $_POST;
    $dados['ativo'] = isset($_POST['ativo']);
    $resultado = atualizarUsuarioPainel($conexao, (int)$_POST['id'], $dados);
    if ($resultado['ok']) {
        $mensagem = "Usuário atualizado.";
    } else {
        $erro = $resultado['erro'];
    }
}

// ---------- RESETAR SENHA ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'resetar_senha') {
    $resultado = resetarSenhaUsuarioPainel($conexao, (int)$_POST['id'], $_POST['nova_senha'] ?? '');
    if ($resultado['ok']) {
        $mensagem = "Senha redefinida com sucesso.";
    } else {
        $erro = $resultado['erro'];
    }
}

// ---------- EXCLUIR ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir') {
    $resultado = excluirUsuarioPainel($conexao, (int)$_POST['id'], $meuId);
    if ($resultado['ok']) {
        $mensagem = "Usuário excluído.";
    } else {
        $erro = $resultado['erro'];
    }
}

$usuarios = listarUsuariosPainel($conexao);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Painel — Usuários</title>
<style>
        :root {
        --bg: #f4f7fc; --bg-card: #ffffff; --border: #dbe3ef;
        --text: #16202e; --text-muted: #55637a; --accent: #2f6fed;
        --azul-eear: #0a2e5c; --danger: #c0392b; --ok: #1f8a4c;
    }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); overflow-x: hidden; }
    .topbar { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid var(--border); }
    .topbar a { color: var(--text-muted); text-decoration: none; font-size: 13px; margin-left: 16px; }
    .container { padding: 24px; max-width: 1100px; margin: 0 auto; }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
    input, select { padding: 8px 10px; background: #ffffff; border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 13px; }
    button { padding: 8px 14px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; }
    button.danger { background: var(--danger); }
    button.ghost { background: transparent; border: 1px solid var(--border); color: var(--text-muted); }
    .acoes { display: flex; flex-direction: column; gap: 6px; align-items: flex-start; }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 8px 10px; text-align: left; vertical-align: top; }
    th { background: var(--azul-eear); color: #ffffff; }
    .erro { color: var(--danger); font-size: 13px; }
    .ok { color: var(--ok); font-size: 13px; }
    .form-linha { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .badge { font-size: 11px; padding: 2px 8px; border-radius: 999px; }
    .badge.ativo { background: #d9f2e3; color: var(--ok); }
    .badge.inativo { background: #fbe0e0; color: var(--danger); }
    .scroll-x { overflow-x: auto; min-width: 0; }
    details summary { cursor: pointer; color: var(--accent); font-size: 12px; }
    .i { display: inline-block; width: 13px; height: 13px; vertical-align: -2px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 4px; }
</style>
<script>
    function alternarEsquadrao(select, campoEsquadrao) {
        const cargosEsquadrao = ['CMD_ESQUADRAO', 'ENC_ESQUADRAO', 'AUX_ESQUADRAO'];
        campoEsquadrao.style.display = cargosEsquadrao.includes(select.value) ? 'inline-block' : 'none';
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

    function redefinirSenha(id) {
        const novaSenha = prompt('Nova senha (mínimo 6 caracteres):');
        if (!novaSenha) return;
        enviarAcao({ acao: 'resetar_senha', id, nova_senha: novaSenha });
    }

    function excluirUsuario(id) {
        if (!confirm('Excluir este usuário do painel?')) return;
        enviarAcao({ acao: 'excluir', id });
    }
</script>
</head>
<body>

<div class="topbar">
    <div><strong>ARGOS</strong> <a href="index.php"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span>painel</a></div>
    <div><a href="index.php?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a></div>
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
                    <td class="acoes">
                        <button form="<?= $formId ?>" type="submit">Salvar</button>
                        <button type="button" class="ghost" onclick="redefinirSenha(<?= $u['id'] ?>)">redefinir senha</button>
                        <?php if ($u['id'] != $meuId): ?>
                        <button type="button" class="danger" onclick="excluirUsuario(<?= $u['id'] ?>)"><span class="i" style="--icon-url:url('../images/icons/trash.svg')"></span>Excluir</button>
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
