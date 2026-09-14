<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../core/dominio_core.php';
require_once __DIR__ . '/../../core/unidades_core.php';

$conexao = conectarBanco();

if (!temPermissao($conexao, 'ikarus37', $_SESSION['admin_nivel'], 'gerenciar_dominio')) {
    die("Sua conta não tem a permissão 'gerenciar_dominio'.");
}

$entidades = dominioEntidades();
$entidadesVisiveis = array_filter($entidades, function ($e) use ($conexao) {
    global $_SESSION;
    return temPermissao($conexao, 'ikarus37', $_SESSION['admin_nivel'], $e['permissao']);
});

$chaveEntidade = $_GET['entidade'] ?? array_key_first($entidadesVisiveis);
$entidade = $entidadesVisiveis[$chaveEntidade] ?? null;

$mensagem = null;
$erro = null;

if ($entidade) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar') {
        $resultado = dominioCriar($conexao, $entidade, $_POST);
        $mensagem = $resultado['ok'] ? "{$entidade['rotulo_singular']} criado(a)." : null;
        $erro = $resultado['ok'] ? null : $resultado['erro'];
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar') {
        $resultado = dominioAtualizar($conexao, $entidade, (int) $_POST['id'], $_POST);
        $mensagem = $resultado['ok'] ? "{$entidade['rotulo_singular']} atualizado(a)." : null;
        $erro = $resultado['ok'] ? null : $resultado['erro'];
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir') {
        $resultado = dominioExcluir($conexao, $entidade, (int) $_POST['id']);
        $mensagem = $resultado['ok'] ? "{$entidade['rotulo_singular']} excluído(a)." : null;
        $erro = $resultado['ok'] ? null : $resultado['erro'];
    }
}

$registros = $entidade ? dominioListar($conexao, $entidade) : [];
$unidadesDisponiveis = listarUnidades($conexao);

function dominioCampoInputIkarus($campo, $valor, $formId, $unidadesDisponiveis) {
    $nome = htmlspecialchars($campo['nome']);
    switch ($campo['tipo']) {
        case 'checkbox':
            $checked = !empty($valor) ? 'checked' : '';
            echo "<input form=\"$formId\" type=\"checkbox\" name=\"$nome\" $checked>";
            break;
        case 'select':
            echo "<select form=\"$formId\" name=\"$nome\">";
            foreach ($campo['opcoes'] as $chaveOpcao => $rotuloOpcao) {
                $sel = ((string) $valor === (string) $chaveOpcao) ? 'selected' : '';
                echo "<option value=\"" . htmlspecialchars($chaveOpcao) . "\" $sel>" . htmlspecialchars($rotuloOpcao) . "</option>";
            }
            echo "</select>";
            break;
        case 'unidade_pai':
            echo "<select form=\"$formId\" name=\"$nome\"><option value=\"\">— nenhuma (raiz) —</option>";
            foreach ($unidadesDisponiveis as $u) {
                $sel = ((string) $valor === (string) $u['id']) ? 'selected' : '';
                echo "<option value=\"{$u['id']}\" $sel>" . htmlspecialchars($u['nome']) . " (" . htmlspecialchars($u['tipo']) . ")</option>";
            }
            echo "</select>";
            break;
        default:
            $valorEsc = htmlspecialchars((string) ($valor ?? ''));
            echo "<input form=\"$formId\" type=\"text\" name=\"$nome\" value=\"$valorEsc\">";
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Ikarus37 — Controle do Domínio de Negócio</title>
<style>
    :root { --bg: #0f1115; --bg-card: #171a21; --border: #2a2e37; --text: #e6e8eb; --text-muted: #9aa1ac; --accent: #4f8cff; --danger: #ff5f5f; --ok: #4caf7d; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); overflow-x: hidden; }
    .topbar { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid var(--border); }
    .topbar .brand { font-weight: 600; }
    .topbar a { color: var(--text-muted); text-decoration: none; font-size: 13px; margin-left: 16px; }
    .container { padding: 24px; max-width: 1100px; margin: 0 auto; }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
    input, select { padding: 8px 10px; background: #0f1115; border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 13px; }
    button { padding: 8px 14px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; }
    button.danger { background: var(--danger); }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 8px 10px; text-align: left; vertical-align: top; }
    th { background: #1d212a; color: var(--text-muted); }
    .erro { color: var(--danger); font-size: 13px; }
    .ok { color: var(--ok); font-size: 13px; }
    .tabs { display: flex; gap: 4px; margin-bottom: 16px; flex-wrap: wrap; }
    .tabs a { padding: 8px 14px; border-radius: 6px; color: var(--text-muted); text-decoration: none; font-size: 13px; border: 1px solid var(--border); }
    .tabs a.ativo { background: var(--accent); color: #fff; border-color: var(--accent); }
    .form-linha { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .scroll-x { overflow-x: auto; min-width: 0; }
    .i { display: inline-block; width: 13px; height: 13px; vertical-align: -2px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 4px; }
</style>
</head>
<body>

<div class="topbar">
    <div class="brand">IKARUS37 <a href="index.php"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span>painel</a></div>
    <div><a href="index.php?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a></div>
</div>

<div class="container">
    <h2>Controle do Domínio de Negócio</h2>
    <p style="color: var(--text-muted); font-size: 13px;">Mesmos objetos editáveis pelo Painel, sem restrição de cargo — o console SQL (Banco de Dados) fica só pra emergência técnica.</p>

    <div class="tabs">
        <?php foreach ($entidadesVisiveis as $chave => $e): ?>
            <a href="?entidade=<?= urlencode($chave) ?>" class="<?= $chaveEntidade === $chave ? 'ativo' : '' ?>"><?= htmlspecialchars($e['rotulo']) ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ($erro): ?><p class="erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($mensagem): ?><p class="ok"><?= htmlspecialchars($mensagem) ?></p><?php endif; ?>

    <?php if ($entidade): ?>
    <div class="card">
        <h4>Novo(a) <?= htmlspecialchars(mb_strtolower($entidade['rotulo_singular'])) ?></h4>
        <form id="form_novo" method="post" class="form-linha">
            <input type="hidden" name="acao" value="criar">
            <?php foreach ($entidade['campos'] as $campo): ?>
                <label style="font-size:12px; color:var(--text-muted); display:flex; flex-direction:column; gap:4px;">
                    <?= htmlspecialchars($campo['rotulo']) ?>
                    <?php dominioCampoInputIkarus($campo, $campo['tipo'] === 'checkbox' ? 1 : '', 'form_novo', $unidadesDisponiveis); ?>
                </label>
            <?php endforeach; ?>
            <button type="submit">Criar</button>
        </form>
    </div>

    <div class="card">
        <h4><?= htmlspecialchars($entidade['rotulo']) ?> (<?= count($registros) ?>)</h4>
        <div class="scroll-x">
        <table>
            <tr>
                <?php foreach ($entidade['campos'] as $campo): ?>
                    <th><?= htmlspecialchars($campo['rotulo']) ?></th>
                <?php endforeach; ?>
                <th>Ações</th>
            </tr>
            <?php foreach ($registros as $r): ?>
                <?php $formId = 'form_edit_' . $r['id']; ?>
                <tr>
                    <?php foreach ($entidade['campos'] as $campo): ?>
                        <td><?php dominioCampoInputIkarus($campo, $r[$campo['nome']] ?? null, $formId, $unidadesDisponiveis); ?></td>
                    <?php endforeach; ?>
                    <td style="white-space:nowrap;">
                        <button type="submit" form="<?= $formId ?>">Salvar</button>
                        <?php if ($chaveEntidade === 'grupos_acesso'): ?>
                            <a href="grupo_acesso_detalhe.php?id=<?= $r['id'] ?>" style="color:var(--accent); font-size:13px;">membros/permissões</a>
                        <?php endif; ?>
                        <button type="button" class="danger" onclick="if(confirm('Excluir este registro?')) document.getElementById('form_excluir_<?= $r['id'] ?>').submit();"><span class="i" style="--icon-url:url('../images/icons/trash.svg')"></span>Excluir</button>
                    </td>
                </tr>
                <form id="<?= $formId ?>" method="post">
                    <input type="hidden" name="acao" value="atualizar">
                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                </form>
                <form id="form_excluir_<?= $r['id'] ?>" method="post">
                    <input type="hidden" name="acao" value="excluir">
                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                </form>
            <?php endforeach; ?>
            <?php if (empty($registros)): ?>
                <tr><td colspan="<?= count($entidade['campos']) + 1 ?>" style="color:var(--text-muted);">Nenhum registro ainda.</td></tr>
            <?php endif; ?>
        </table>
        </div>
    </div>
    <?php if ($chaveEntidade === 'cargos'): ?>
        <p style="color: var(--text-muted); font-size: 12px;"><a href="permissoes_cargo.php" style="color:var(--accent);">Gerenciar permissões de cada cargo →</a></p>
    <?php endif; ?>
    <?php endif; ?>
</div>

</body>
</html>
<?php mysqli_close($conexao); ?>
