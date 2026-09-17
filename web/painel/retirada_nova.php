<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/acesso_negado.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../core/retiradas_core.php';
require_once __DIR__ . '/../../core/alunos_core.php';
require_once __DIR__ . '/../../core/grupos_core.php';

$conexao = conectarBanco();

if (!temPermissao($conexao, 'painel', $_SESSION['painel_cargo'], 'registrar_retirada', idUsuarioPainel())) {
    exibirAcessoNegado("Seu cargo não tem a permissão 'registrar_retirada'.");
}

$escopo = escopoEsquadrao(); // null = pode escolher esquadrão/grupo livremente
$erro = null;

$tiposRetirada = ['1_jornada' => '1ª Jornada', '2_jornada' => '2ª Jornada', 'educacao_fisica' => 'Educação Física', 'pernoite' => 'Pernoite'];
$rotulosCategorias = ['clube' => 'Clube', 'servico' => 'Serviço', 'comissao' => 'Comissão'];

// O responsável pela retirada é sempre quem está logado nesse exato momento —
// nunca uma escolha do formulário — pra não dar pra abrir uma retirada e
// atribuí-la ao nome de outra pessoa.
$viaIkarus = ($_SESSION['painel_origem'] ?? '') === 'ikarus37';
$painelUsuarioId = $viaIkarus ? null : (int) $_SESSION['painel_id'];
$responsavelNome = $_SESSION['painel_nome'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo = $_POST['tipo'] ?? '';
    $agrupamentoTipo = $_POST['agrupamento_tipo'] ?? 'esquadrilha';
    $esquadrao = $escopo ?? trim($_POST['esquadrao'] ?? '');
    $agrupamentoValor = trim($_POST['agrupamento_valor'] ?? '');

    $resultado = abrirRetirada($conexao, $tipo, $agrupamentoTipo, $agrupamentoValor, $esquadrao, $responsavelNome, $painelUsuarioId);

    if ($resultado['ok']) {
        header("Location: retirada_marcar.php?id=" . $resultado['retirada_id']);
        exit;
    }
    $erro = $resultado['erro'];
}

// Esquadrões disponíveis (só quem tem visão CA escolhe; senão já é fixo)
$esquadroesDisponiveis = $escopo === null ? listarEsquadroesDistintos($conexao) : [];

$grupos = listarGrupos($conexao);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Painel — Nova retirada</title>
<style>
        :root {
        --bg: #f4f7fc; --bg-card: #ffffff; --border: #dbe3ef;
        --text: #16202e; --text-muted: #55637a; --accent: #2f6fed;
        --azul-eear: #0a2e5c; --danger: #c0392b; --ok: #1f8a4c;
    }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); }
    .topbar { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid var(--border); }
    .topbar a { color: var(--text-muted); text-decoration: none; font-size: 13px; margin-left: 16px; }
    .container { padding: 24px; max-width: 500px; margin: 0 auto; }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; }
    label { display: block; font-size: 12px; color: var(--text-muted); margin-top: 12px; margin-bottom: 4px; }
    input, select { width: 100%; padding: 8px 10px; background: #ffffff; border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 13px; }
    select { max-width: 100%; }
    button { padding: 9px 18px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; margin-top: 18px; }
    .erro { color: var(--danger); font-size: 13px; }
    fieldset { border: 1px solid var(--border); border-radius: 8px; margin-top: 14px; padding: 10px; }
    legend { font-size: 12px; color: var(--text-muted); padding: 0 6px; }
    .responsavel { background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; font-size: 13px; margin-top: 12px; }
    .responsavel .rotulo { color: var(--text-muted); font-size: 11px; text-transform: uppercase; letter-spacing: .03em; }
    .i { display: inline-block; width: 13px; height: 13px; vertical-align: -2px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 4px; }
</style>
<script>
    function alternarTipo() {
        const tipoAlto = document.getElementById('tipo_alto_nivel').value;
        const ehRotina = tipoAlto === 'rotina';

        document.getElementById('agrupamento_tipo').value = ehRotina ? 'esquadrilha' : 'grupo';
        document.getElementById('bloco_esquadrilha').style.display = ehRotina ? 'block' : 'none';
        document.getElementById('bloco_grupo').style.display = ehRotina ? 'none' : 'block';

        // Só o campo do bloco ativo deve ser enviado no submit.
        document.getElementById('esquadrilha').disabled = !ehRotina;
        document.getElementById('grupo').disabled = ehRotina;

        if (!ehRotina) {
            filtrarGruposPorCategoria(tipoAlto);
        }
    }

    function filtrarGruposPorCategoria(categoria) {
        const select = document.getElementById('grupo');
        let primeiraVisivel = null;
        for (const opt of select.options) {
            const visivel = opt.dataset.categoria === categoria;
            opt.hidden = !visivel;
            if (visivel && !primeiraVisivel) primeiraVisivel = opt;
        }
        select.value = primeiraVisivel ? primeiraVisivel.value : '';
    }
</script>
</head>
<body>

<div class="topbar">
    <div><strong>ARGOS</strong> <a href="retiradas.php"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span>retiradas</a></div>
    <div><a href="index.php?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a></div>
</div>

<div class="container">
    <h2>Nova retirada</h2>

    <?php if ($erro): ?><p class="erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>

    <div class="card">
        <form method="post">
            <?php if ($escopo === null): ?>
                <label>Tipo</label>
                <select id="tipo_alto_nivel" onchange="alternarTipo()">
                    <option value="rotina">Rotina CA</option>
                    <?php foreach ($rotulosCategorias as $valor => $rotulo): ?>
                        <option value="<?= $valor ?>"><?= $rotulo ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" id="agrupamento_tipo" name="agrupamento_tipo" value="esquadrilha">
            <?php else: ?>
                <input type="hidden" id="agrupamento_tipo" name="agrupamento_tipo" value="esquadrilha">
            <?php endif; ?>

            <fieldset id="bloco_esquadrilha">
                <legend>Rotina CA</legend>
                <?php if ($escopo === null): ?>
                    <label>Esquadrão</label>
                    <select id="esquadrao" name="esquadrao">
                        <?php foreach ($esquadroesDisponiveis as $e): ?>
                            <option value="<?= htmlspecialchars($e) ?>"><?= htmlspecialchars($e) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <p style="font-size:13px; color:var(--text-muted); margin:4px 0;">Esquadrão: <?= htmlspecialchars($escopo) ?></p>
                <?php endif; ?>

                <label>Esquadrilha</label>
                <select id="esquadrilha" name="agrupamento_valor">
                    <option value="A">A</option>
                    <option value="B">B</option>
                    <option value="C">C</option>
                    <option value="D">D</option>
                </select>
            </fieldset>

            <fieldset id="bloco_grupo" style="display:none;">
                <legend>Grupo</legend>
                <label>Grupo</label>
                <select id="grupo" name="agrupamento_valor" disabled>
                    <?php foreach ($grupos as $g): ?>
                        <option value="<?= htmlspecialchars($g['nome']) ?>" data-categoria="<?= htmlspecialchars($g['categoria']) ?>" hidden>
                            <?= htmlspecialchars($g['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </fieldset>

            <label>Entrada</label>
            <select name="tipo" required>
                <?php foreach ($tiposRetirada as $valor => $rotulo): ?>
                    <option value="<?= $valor ?>"><?= $rotulo ?></option>
                <?php endforeach; ?>
            </select>

            <div class="responsavel">
                <div class="rotulo">Responsável pela retirada</div>
                <?= htmlspecialchars($responsavelNome) ?> · <?= htmlspecialchars(nomeCargo($_SESSION['painel_cargo'])) ?>
            </div>

            <button type="submit">Abrir retirada</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../core/rodape_versao.php'; ?>
</body>
</html>
<?php mysqli_close($conexao); ?>
