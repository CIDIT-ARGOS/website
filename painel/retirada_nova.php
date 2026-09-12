<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../retiradas_core.php';

$conexao = conectarBanco();

if (!temPermissao($conexao, 'painel', $_SESSION['painel_cargo'], 'registrar_retirada')) {
    die("Seu cargo não tem a permissão 'registrar_retirada'.");
}

$escopo = escopoEsquadrao(); // null = pode escolher esquadrão/grupo livremente
$erro = null;

$tiposRetirada = ['1_jornada' => '1ª Jornada', '2_jornada' => '2ª Jornada', 'educacao_fisica' => 'Educação Física', 'pernoite' => 'Pernoite'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo = $_POST['tipo'] ?? '';
    $agrupamentoTipo = $_POST['agrupamento_tipo'] ?? 'esquadrilha';
    $esquadrao = $escopo ?? trim($_POST['esquadrao'] ?? '');
    $agrupamentoValor = trim($_POST['agrupamento_valor'] ?? '');
    $alunoServicoId = (int)($_POST['aluno_servico_id'] ?? 0);

    $resultado = abrirRetirada($conexao, $tipo, $agrupamentoTipo, $agrupamentoValor, $esquadrao, $alunoServicoId);

    if ($resultado['ok']) {
        header("Location: retirada_marcar.php?id=" . $resultado['retirada_id']);
        exit;
    }
    $erro = $resultado['erro'];
}

// Esquadrões disponíveis (só quem tem visão CA escolhe; senão já é fixo)
$esquadroesDisponiveis = [];
if ($escopo === null) {
    $r = mysqli_query($conexao, "SELECT DISTINCT esquadrao FROM alunos WHERE ativo = 1 ORDER BY esquadrao");
    while ($l = mysqli_fetch_assoc($r)) $esquadroesDisponiveis[] = $l['esquadrao'];
}

$grupos = mysqli_fetch_all(mysqli_query($conexao, "SELECT nome FROM grupos WHERE ativo = 1 ORDER BY nome"), MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Painel — Nova retirada</title>
<style>
    :root { --bg: #0d1117; --bg-card: #161b22; --border: #30363d; --text: #e6edf3; --text-muted: #8b949e; --accent: #4f8cff; --danger: #ff5f5f; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); }
    .topbar { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid var(--border); }
    .topbar a { color: var(--text-muted); text-decoration: none; font-size: 13px; margin-left: 16px; }
    .container { padding: 24px; max-width: 500px; margin: 0 auto; }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; }
    label { display: block; font-size: 12px; color: var(--text-muted); margin-top: 12px; margin-bottom: 4px; }
    input, select { width: 100%; padding: 8px 10px; background: #0d1117; border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 13px; }
    button { padding: 9px 18px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; margin-top: 18px; }
    .erro { color: var(--danger); font-size: 13px; }
    fieldset { border: 1px solid var(--border); border-radius: 8px; margin-top: 14px; padding: 10px; }
    legend { font-size: 12px; color: var(--text-muted); padding: 0 6px; }
</style>
<script>
    function alternarAgrupamento() {
        const tipo = document.getElementById('agrupamento_tipo').value;
        const ehEsquadrilha = tipo === 'esquadrilha';

        document.getElementById('bloco_esquadrilha').style.display = ehEsquadrilha ? 'block' : 'none';
        document.getElementById('bloco_grupo').style.display = ehEsquadrilha ? 'none' : 'block';

        // Só o campo do bloco ativo deve ser enviado no submit.
        document.getElementById('esquadrilha').disabled = !ehEsquadrilha;
        document.getElementById('grupo').disabled = ehEsquadrilha;

        carregarAlunosServico();
    }

    async function carregarAlunosServico() {
        const esquadrao = document.getElementById('esquadrao') ? document.getElementById('esquadrao').value : document.getElementById('esquadrao_fixo').value;
        const agrupamentoTipo = document.getElementById('agrupamento_tipo').value;
        const valor = agrupamentoTipo === 'esquadrilha'
            ? document.getElementById('esquadrilha').value
            : document.getElementById('grupo').value;

        const select = document.getElementById('aluno_servico_id');
        select.innerHTML = '<option>Carregando...</option>';

        const params = new URLSearchParams();
        if (agrupamentoTipo === 'esquadrilha') {
            params.set('esquadrao', esquadrao);
            params.set('esquadrilha', valor);
        }
        // Busca alunos via endpoint interno (sem precisar da API key, é a mesma sessão do painel)
        const resp = await fetch('ajax_alunos_grupo.php?' + params.toString() + '&agrupamento_tipo=' + agrupamentoTipo + '&grupo=' + encodeURIComponent(valor));
        const alunos = await resp.json();

        select.innerHTML = '';
        alunos.forEach(a => {
            const opt = document.createElement('option');
            opt.value = a.id;
            opt.textContent = a.nome_guerra + ' (' + a.milhao + ')';
            select.appendChild(opt);
        });
    }

    let timeoutBusca = null;
    function buscarAlunoServicoDebounced() {
        clearTimeout(timeoutBusca);
        timeoutBusca = setTimeout(buscarAlunoServico, 300);
    }

    async function buscarAlunoServico() {
        const termo = document.getElementById('busca_aluno_servico').value.trim();
        const select = document.getElementById('aluno_servico_id');

        if (termo.length < 2) {
            // Campo de busca vazio: volta pra lista padrão da esquadrilha/grupo.
            carregarAlunosServico();
            return;
        }

        select.innerHTML = '<option>Buscando...</option>';
        const resp = await fetch('ajax_buscar_aluno.php?termo=' + encodeURIComponent(termo));
        const alunos = await resp.json();

        select.innerHTML = '';
        if (alunos.length === 0) {
            select.innerHTML = '<option value="">Nenhum aluno encontrado</option>';
            return;
        }
        alunos.forEach(a => {
            const opt = document.createElement('option');
            opt.value = a.id;
            opt.textContent = a.nome_guerra + ' (' + a.milhao + ') — ' + a.esquadrao + '/' + a.esquadrilha;
            select.appendChild(opt);
        });
    }
</script>
</head>
<body>

<div class="topbar">
    <div><strong>ARGOS</strong> <a href="retiradas.php">← retiradas</a></div>
    <div><a href="index.php?logout=1">sair</a></div>
</div>

<div class="container">
    <h2>Nova retirada</h2>

    <?php if ($erro): ?><p class="erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>

    <div class="card">
        <form method="post">
            <label>Tipo de entrada em forma</label>
            <select name="tipo" required>
                <?php foreach ($tiposRetirada as $valor => $rotulo): ?>
                    <option value="<?= $valor ?>"><?= $rotulo ?></option>
                <?php endforeach; ?>
            </select>

            <?php if ($escopo === null): ?>
                <label>Agrupamento</label>
                <select id="agrupamento_tipo" name="agrupamento_tipo" onchange="alternarAgrupamento()">
                    <option value="esquadrilha">Esquadrilha</option>
                    <option value="grupo">Grupo (ex: CIDIT)</option>
                </select>
            <?php else: ?>
                <input type="hidden" id="agrupamento_tipo" name="agrupamento_tipo" value="esquadrilha">
            <?php endif; ?>

            <fieldset id="bloco_esquadrilha">
                <legend>Esquadrilha</legend>
                <?php if ($escopo === null): ?>
                    <label>Esquadrão</label>
                    <select id="esquadrao" name="esquadrao" onchange="carregarAlunosServico()">
                        <?php foreach ($esquadroesDisponiveis as $e): ?>
                            <option value="<?= htmlspecialchars($e) ?>"><?= htmlspecialchars($e) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="hidden" id="esquadrao_fixo" value="<?= htmlspecialchars($escopo) ?>">
                    <p style="font-size:13px; color:var(--text-muted); margin:4px 0;">Esquadrão: <?= htmlspecialchars($escopo) ?></p>
                <?php endif; ?>

                <label>Esquadrilha</label>
                <select id="esquadrilha" name="agrupamento_valor" onchange="carregarAlunosServico()">
                    <option value="A">A</option>
                    <option value="B">B</option>
                    <option value="C">C</option>
                    <option value="D">D</option>
                </select>
            </fieldset>

            <fieldset id="bloco_grupo" style="display:none;">
                <legend>Grupo</legend>
                <label>Grupo</label>
                <select id="grupo" name="agrupamento_valor" disabled onchange="carregarAlunosServico()">
                    <?php foreach ($grupos as $g): ?>
                        <option value="<?= htmlspecialchars($g['nome']) ?>"><?= htmlspecialchars($g['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </fieldset>

            <label>Identificação do aluno de serviço</label>
            <select id="aluno_servico_id" name="aluno_servico_id" required>
                <option value="">Selecione a esquadrilha/grupo primeiro</option>
            </select>

            <input type="text" id="busca_aluno_servico" placeholder="Não achou? Buscar por nome/milhão em qualquer esquadrão (ex: Aluno de Dia à Esquadrilha)"
                oninput="buscarAlunoServicoDebounced()" style="margin-top:6px; font-size:12px;">

            <button type="submit">Abrir retirada</button>
        </form>
    </div>
</div>

<script>
    // Carrega a lista inicial ao abrir a página
    carregarAlunosServico();
</script>

</body>
</html>
<?php mysqli_close($conexao); ?>
