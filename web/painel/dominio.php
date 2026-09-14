<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../core/dominio_core.php';
require_once __DIR__ . '/../../core/unidades_core.php';

if (!podeGerenciarDominio()) {
    die("Seu cargo não tem a permissão 'gerenciar_dominio'.");
}

$conexao = conectarBanco();
$usuarioId = idUsuarioPainel();

$entidades = dominioEntidades();
$entidadesVisiveis = array_filter($entidades, function ($e) use ($conexao, $usuarioId) {
    global $_SESSION;
    return temPermissao($conexao, 'painel', $_SESSION['painel_cargo'], $e['permissao'], $usuarioId);
});

$chaveEntidade = $_GET['entidade'] ?? array_key_first($entidadesVisiveis);
$entidade = $entidadesVisiveis[$chaveEntidade] ?? null;

$mensagem = null;
$erro = null;

if ($entidade) {
    // ---------- Criar ----------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar') {
        $resultado = dominioCriar($conexao, $entidade, $_POST);
        $mensagem = $resultado['ok'] ? "{$entidade['rotulo_singular']} criado(a)." : null;
        $erro = $resultado['ok'] ? null : $resultado['erro'];
    }

    // ---------- Atualizar ----------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar') {
        $resultado = dominioAtualizar($conexao, $entidade, (int) $_POST['id'], $_POST);
        $mensagem = $resultado['ok'] ? "{$entidade['rotulo_singular']} atualizado(a)." : null;
        $erro = $resultado['ok'] ? null : $resultado['erro'];
    }

    // ---------- Excluir ----------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir') {
        $resultado = dominioExcluir($conexao, $entidade, (int) $_POST['id']);
        $mensagem = $resultado['ok'] ? "{$entidade['rotulo_singular']} excluído(a)." : null;
        $erro = $resultado['ok'] ? null : $resultado['erro'];
    }
}

$registros = $entidade ? dominioListar($conexao, $entidade) : [];
$unidadesDisponiveis = listarUnidades($conexao);

function dominioCampoInput($campo, $valor, $formId, $unidadesDisponiveis) {
    $nome = htmlspecialchars($campo['nome']);
    $id = htmlspecialchars($formId . '_' . $campo['nome']);
    switch ($campo['tipo']) {
        case 'checkbox':
            $checked = !empty($valor) ? 'checked' : '';
            echo "<input form=\"$formId\" type=\"checkbox\" name=\"$nome\" id=\"$id\" $checked>";
            break;
        case 'select':
            echo "<select form=\"$formId\" name=\"$nome\" id=\"$id\">";
            foreach ($campo['opcoes'] as $chaveOpcao => $rotuloOpcao) {
                $sel = ((string) $valor === (string) $chaveOpcao) ? 'selected' : '';
                echo "<option value=\"" . htmlspecialchars($chaveOpcao) . "\" $sel>" . htmlspecialchars($rotuloOpcao) . "</option>";
            }
            echo "</select>";
            break;
        case 'unidade_pai':
            echo "<select form=\"$formId\" name=\"$nome\" id=\"$id\"><option value=\"\">— nenhuma (raiz) —</option>";
            foreach ($unidadesDisponiveis as $u) {
                $sel = ((string) $valor === (string) $u['id']) ? 'selected' : '';
                echo "<option value=\"{$u['id']}\" $sel>" . htmlspecialchars($u['nome']) . " (" . htmlspecialchars($u['tipo']) . ")</option>";
            }
            echo "</select>";
            break;
        default:
            $valorEsc = htmlspecialchars((string) ($valor ?? ''));
            echo "<input form=\"$formId\" type=\"text\" name=\"$nome\" id=\"$id\" value=\"$valorEsc\">";
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Painel — Controle do Domínio de Negócio</title>
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
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 8px 10px; text-align: left; vertical-align: top; }
    th { background: var(--azul-eear); color: #ffffff; }
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
    <div><strong>ARGOS</strong> <a href="index.php"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span>painel</a></div>
    <div><a href="index.php?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a></div>
</div>

<div class="container">
    <h2>Controle do Domínio de Negócio</h2>
    <p style="color: var(--text-muted); font-size: 13px; margin-top: -8px;">Objetos do sistema editáveis por aqui, sem precisar mexer direto no banco.</p>

    <div class="tabs">
        <?php foreach ($entidadesVisiveis as $chave => $e): ?>
            <a href="?entidade=<?= urlencode($chave) ?>" class="<?= $chaveEntidade === $chave ? 'ativo' : '' ?>"><?= htmlspecialchars($e['rotulo']) ?></a>
        <?php endforeach; ?>
        <?php if (empty($entidadesVisiveis)): ?>
            <p style="color: var(--text-muted); font-size: 13px;">Seu cargo não tem permissão de gestão pra nenhum objeto ainda.</p>
        <?php endif; ?>
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
                    <?php dominioCampoInput($campo, $campo['tipo'] === 'checkbox' ? 1 : '', 'form_novo', $unidadesDisponiveis); ?>
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
                        <td><?php dominioCampoInput($campo, $r[$campo['nome']] ?? null, $formId, $unidadesDisponiveis); ?></td>
                    <?php endforeach; ?>
                    <td style="white-space:nowrap;">
                        <button type="submit" form="<?= $formId ?>">Salvar</button>
                        <?php if ($chaveEntidade === 'grupos_acesso'): ?>
                            <a href="grupo_acesso_detalhe.php?id=<?= $r['id'] ?>"><span class="i" style="--icon-url:url('../images/icons/users-group.svg')"></span>membros/permissões</a>
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

    <?php if ($chaveEntidade === 'grupos_acesso'): ?>
        <p style="color: var(--text-muted); font-size: 12px;">Use "membros/permissões" na linha de cada grupo pra escolher quem entra e o que o grupo concede.</p>
    <?php endif; ?>
    <?php if ($chaveEntidade === 'cargos'): ?>
        <p style="color: var(--text-muted); font-size: 12px;"><a href="permissoes_cargo.php">Gerenciar permissões de cada cargo →</a></p>
    <?php endif; ?>
    <?php endif; ?>
</div>

</body>
</html>
<?php mysqli_close($conexao); ?>
