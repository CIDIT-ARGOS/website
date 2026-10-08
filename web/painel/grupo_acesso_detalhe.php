<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/acesso_negado.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../core/grupos_acesso_core.php';
require_once __DIR__ . '/../../core/unidades_core.php';

$usuarioId = idUsuarioPainel();
$conexao = conectarBanco();

if (!temPermissao($conexao, 'painel', $_SESSION['painel_cargo'], 'gerenciar_grupos_acesso', $usuarioId)) {
    exibirAcessoNegado("Seu cargo não tem a permissão 'gerenciar_grupos_acesso'.");
}

$grupoId = (int) ($_GET['id'] ?? 0);
$grupo = buscarGrupoAcessoPorId($conexao, $grupoId);
if (!$grupo) {
    die("Grupo de acesso não encontrado.");
}

$mensagem = null;
$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'adicionar_membro') {
        $painelUsuarioId = (int) ($_POST['painel_usuario_id'] ?? 0);
        $unidadeId = $_POST['unidade_id'] !== '' ? (int) $_POST['unidade_id'] : null;
        if ($painelUsuarioId > 0 && adicionarMembroGrupoAcesso($conexao, $grupoId, $painelUsuarioId, $unidadeId)) {
            $mensagem = "Membro adicionado.";
        } else {
            $erro = "Escolha um usuário do painel.";
        }
    }

    if ($acao === 'remover_membro') {
        removerMembroGrupoAcesso($conexao, (int) $_POST['membro_id']);
        $mensagem = "Membro removido.";
    }

    if ($acao === 'conceder_permissao') {
        $permissaoId = (int) ($_POST['permissao_id'] ?? 0);
        $unidadeId = $_POST['unidade_id_permissao'] !== '' ? (int) $_POST['unidade_id_permissao'] : null;
        if ($permissaoId > 0 && concederPermissaoGrupoAcesso($conexao, $grupoId, $permissaoId, $unidadeId)) {
            $mensagem = "Permissão concedida.";
        } else {
            $erro = "Escolha uma permissão.";
        }
    }

    if ($acao === 'revogar_permissao') {
        revogarPermissaoGrupoAcesso($conexao, (int) $_POST['concessao_id']);
        $mensagem = "Permissão revogada.";
    }
}

$membros = listarMembrosGrupoAcesso($conexao, $grupoId);
$permissoesConcedidas = listarPermissoesGrupoAcesso($conexao, $grupoId);
$todasPermissoes = listarTodasPermissoes($conexao);
$unidades = listarUnidades($conexao);
$usuariosPainel = mysqli_fetch_all(mysqli_query($conexao, "SELECT id, nome, usuario, cargo FROM painel_usuarios WHERE ativo = 1 ORDER BY nome"), MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Painel — <?= htmlspecialchars($grupo['nome']) ?></title>
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
    .container { padding: 24px; max-width: 900px; margin: 0 auto; }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
    input, select { padding: 8px 10px; background: #ffffff; border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 13px; }
    select { max-width: 100%; }
    button { padding: 8px 14px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; }
    button.danger { background: var(--danger); }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 8px 10px; text-align: left; vertical-align: top; }
    th { background: var(--azul-eear); color: #ffffff; }
    .erro { color: var(--danger); font-size: 13px; }
    .ok { color: var(--ok); font-size: 13px; }
    .form-linha { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .scroll-x { overflow-x: auto; min-width: 0; }
    .i { display: inline-block; width: 16px; height: 16px; vertical-align: -3px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 5px; }
</style>
</head>
<body>

<div class="topbar">
    <div><strong>ARGOS</strong> <a href="dominio.php?entidade=grupos_acesso"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span>domínio</a></div>
    <div><a href="index.php?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a></div>
</div>

<div class="container">
    <h2><?= htmlspecialchars($grupo['nome']) ?></h2>
    <p style="color: var(--text-muted); font-size: 13px; margin-top: -8px;"><?= htmlspecialchars($grupo['descricao'] ?? 'Sem descrição.') ?></p>

    <?php if ($erro): ?><p class="erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($mensagem): ?><p class="ok"><?= htmlspecialchars($mensagem) ?></p><?php endif; ?>

    <div class="card">
        <h4>Membros</h4>
        <form method="post" class="form-linha">
            <input type="hidden" name="acao" value="adicionar_membro">
            <select name="painel_usuario_id" required>
                <option value="">— escolha um usuário do painel —</option>
                <?php foreach ($usuariosPainel as $u): ?>
                    <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nome']) ?> (<?= htmlspecialchars($u['usuario']) ?>, <?= htmlspecialchars($u['cargo']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <select name="unidade_id">
                <option value="">— sem restrição de unidade —</option>
                <?php foreach ($unidades as $u): ?>
                    <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nome']) ?> (<?= htmlspecialchars($u['tipo']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <button type="submit"><span class="i" style="--icon-url:url('../images/icons/user-plus.svg')"></span>Adicionar</button>
        </form>

        <div class="scroll-x">
        <table>
            <tr><th>Nome</th><th>Usuário</th><th>Cargo</th><th>Restrito à unidade</th><th>Ações</th></tr>
            <?php foreach ($membros as $m): ?>
                <tr>
                    <td><?= htmlspecialchars($m['nome']) ?></td>
                    <td><?= htmlspecialchars($m['usuario']) ?></td>
                    <td><?= htmlspecialchars($m['cargo']) ?></td>
                    <td><?= htmlspecialchars($m['unidade_nome'] ?? '— todas —') ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="acao" value="remover_membro">
                            <input type="hidden" name="membro_id" value="<?= $m['id'] ?>">
                            <button type="submit" class="danger" onclick="return confirm('Remover este membro do grupo?')"><span class="i" style="--icon-url:url('../images/icons/trash.svg')"></span>Remover</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($membros)): ?>
                <tr><td colspan="5" style="color:var(--text-muted);">Nenhum membro ainda.</td></tr>
            <?php endif; ?>
        </table>
        </div>
    </div>

    <div class="card">
        <h4>Permissões concedidas</h4>
        <form method="post" class="form-linha">
            <input type="hidden" name="acao" value="conceder_permissao">
            <select name="permissao_id" required>
                <option value="">— escolha uma permissão —</option>
                <?php foreach ($todasPermissoes as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['chave']) ?> — <?= htmlspecialchars($p['descricao']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="unidade_id_permissao">
                <option value="">— vale em qualquer unidade do membro —</option>
                <?php foreach ($unidades as $u): ?>
                    <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nome']) ?> (<?= htmlspecialchars($u['tipo']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <button type="submit"><span class="i" style="--icon-url:url('../images/icons/shield-check.svg')"></span>Conceder</button>
        </form>

        <div class="scroll-x">
        <table>
            <tr><th>Permissão</th><th>Restrita à unidade</th><th>Ações</th></tr>
            <?php foreach ($permissoesConcedidas as $p): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($p['chave']) ?></strong><br><span style="color:var(--text-muted); font-size:12px;"><?= htmlspecialchars($p['descricao']) ?></span></td>
                    <td><?= htmlspecialchars($p['unidade_nome'] ?? '— qualquer uma —') ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="acao" value="revogar_permissao">
                            <input type="hidden" name="concessao_id" value="<?= $p['id'] ?>">
                            <button type="submit" class="danger" onclick="return confirm('Revogar esta permissão do grupo?')"><span class="i" style="--icon-url:url('../images/icons/trash.svg')"></span>Revogar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($permissoesConcedidas)): ?>
                <tr><td colspan="3" style="color:var(--text-muted);">Nenhuma permissão concedida ainda.</td></tr>
            <?php endif; ?>
        </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../core/rodape_versao.php'; ?>
</body>
</html>
<?php mysqli_close($conexao); ?>
