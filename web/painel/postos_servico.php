<?php

// Postos de serviço: cria os postos à mão (como os clubes) e registra quem
// está de serviço em cada um. Não há membros fixos — a pessoa muda todo dia,
// e às vezes no meio do serviço; cada troca fica no histórico.

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/acesso_negado.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../core/servicos_core.php';
require_once __DIR__ . '/../../core/alunos_core.php';

$conexao = conectarBanco();

// Quem faz chamada é quem precisa saber (e dizer) quem está de serviço.
if (!temPermissao($conexao, 'painel', $_SESSION['painel_cargo'], 'registrar_retirada', idUsuarioPainel())) {
    exibirAcessoNegado("Seu cargo não tem a permissão 'registrar_retirada'.");
}

$escopo = escopoEsquadrao();
$mensagem = null;
$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    $resultado = null;
    $sucesso = null;

    if ($acao === 'criar_posto') {
        $resultado = criarPostoServico($conexao, $_POST['nome'] ?? '', $_POST['sigla'] ?? '');
        $sucesso = "Posto de serviço criado.";
    }

    if ($acao === 'desativar_posto') {
        desativarPostoServico($conexao, (int) ($_POST['posto_id'] ?? 0));
        $resultado = ['ok' => true];
        $sucesso = "Posto desativado.";
    }

    if ($acao === 'escalar') {
        $aluno = buscarAlunoPorMilhao($conexao, trim($_POST['milhao_aluno'] ?? ''));
        if (!$aluno) {
            $resultado = ['ok' => false, 'erro' => 'Aluno não encontrado — escolha um nome da lista de sugestões.'];
        } elseif ($escopo !== null && $aluno['esquadrao'] !== $escopo) {
            $resultado = ['ok' => false, 'erro' => "Você só pode colocar de serviço alunos do Esquadrão $escopo."];
        } else {
            $resultado = escalarAlunoNoPosto(
                $conexao,
                (int) ($_POST['posto_id'] ?? 0),
                (int) $aluno['id'],
                $_SESSION['painel_nome'] ?? null,
                idUsuarioPainel(),
                !empty($_POST['substitui_escala_id']) ? (int) $_POST['substitui_escala_id'] : null,
                $_POST['motivo'] ?? ''
            );
            $sucesso = identificacaoAluno($aluno) . " está de serviço.";
        }
    }

    if ($acao === 'encerrar') {
        $resultado = encerrarEscala($conexao, (int) ($_POST['escala_id'] ?? 0), $_POST['motivo'] ?? '');
        $sucesso = "Serviço encerrado.";
    }

    if ($resultado !== null) {
        $mensagem = $resultado['ok'] ? $sucesso : null;
        $erro = $resultado['ok'] ? null : $resultado['erro'];
    }
}

$postos = listarServicos($conexao);
$hoje = date('Y-m-d');
foreach ($postos as &$posto) {
    $posto['de_servico'] = escalaAtualDoPosto($conexao, (int) $posto['id']);
    $posto['historico'] = historicoDoPosto($conexao, (int) $posto['id'], $hoje);
}
unset($posto);

$filtrosAlunos = ['com_posto_exibicao' => true];
if ($escopo !== null) {
    $filtrosAlunos['esquadrao'] = $escopo;
}
$alunos = listarAlunos($conexao, $filtrosAlunos);

$horario = fn($dataHora) => date('d/m H:i', strtotime($dataHora));

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Painel — Postos de serviço</title>
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
    .container { padding: 24px; max-width: 1000px; margin: 0 auto; }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
    input, select { padding: 8px 10px; background: #ffffff; border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 13px; }
    select { max-width: 100%; }
    button { padding: 8px 14px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; }
    button.danger { background: var(--danger); }
    .erro { color: var(--danger); font-size: 13px; }
    .ok { color: var(--ok); font-size: 13px; }
    .form-linha { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .posto-cabecalho { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
    .posto-cabecalho h3 { margin: 0; }
    .posto-cabecalho h3 small { color: var(--text-muted); font-weight: 400; font-size: 13px; margin-left: 6px; }
    .de-servico { list-style: none; margin: 14px 0; padding: 0; }
    .de-servico li { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; padding: 10px 12px; border: 1px solid var(--border); border-left: 4px solid var(--ok); border-radius: 6px; margin-bottom: 8px; }
    .de-servico li.vago { border-left-color: var(--danger); color: var(--text-muted); }
    .de-servico small, .historico small { color: var(--text-muted); }
    .rotulo { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .08em; color: var(--text-muted); margin: 16px 0 8px; }
    .historico { margin-top: 14px; font-size: 13px; }
    .historico ul { margin: 8px 0 0; padding-left: 18px; }
    .historico li { margin-bottom: 4px; }
    .i { display: inline-block; width: 16px; height: 16px; vertical-align: -3px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 5px; }
</style>
<link rel="stylesheet" href="../css/argos-admin.css">
<script src="../js/argos-admin.js" defer></script>
</head>
<body>

<div class="topbar">
    <div><strong><span class="marca-argos" role="img" aria-label="Argos">ARG<i class="olho"></i>S</span></strong> <a href="index.php"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span>painel</a></div>
    <div><a href="index.php?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a></div>
</div>

<div class="container">
    <h2>Postos de serviço</h2>
    <p style="color: var(--text-muted); font-size: 13px; margin-top: -8px;">
        Cada posto é criado aqui, como um clube — mas sem membros fixos: registre quem está de serviço agora e troque quando render ou der pane.
        Quem está de serviço já entra na chamada marcado como ausente por <strong>Serviço</strong>, com o posto.
    </p>

    <?php if ($erro): ?><p class="erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($mensagem): ?><p class="ok"><?= htmlspecialchars($mensagem) ?></p><?php endif; ?>

    <datalist id="lista_alunos">
        <?php foreach ($alunos as $a): ?>
            <option value="<?= htmlspecialchars($a['milhao']) ?>"><?= htmlspecialchars(identificacaoAluno($a)) ?> — <?= htmlspecialchars($a['esquadrao']) ?>/<?= htmlspecialchars($a['esquadrilha']) ?></option>
        <?php endforeach; ?>
    </datalist>

    <div class="card">
        <h4 style="margin-top:0;">Novo posto de serviço</h4>
        <form method="post" class="form-linha">
            <input type="hidden" name="acao" value="criar_posto">
            <input type="text" name="nome" placeholder="Nome do posto (ex: Aluno de Dia ao Esquadrão)" maxlength="<?= POSTO_SERVICO_LIMITE_NOME ?>" required style="flex:1; min-width:260px;">
            <input type="text" name="sigla" placeholder="Sigla (opcional)" maxlength="<?= POSTO_SERVICO_LIMITE_SIGLA ?>" style="width:140px;">
            <button type="submit"><span class="i" style="--icon-url:url('../images/icons/plus.svg')"></span>Criar posto</button>
        </form>
    </div>

    <?php if (empty($postos)): ?>
        <div class="card"><p style="color:var(--text-muted); margin:0;">Nenhum posto de serviço cadastrado ainda. Crie o primeiro acima.</p></div>
    <?php endif; ?>

    <?php foreach ($postos as $posto): ?>
        <div class="card">
            <div class="posto-cabecalho">
                <h3><?= htmlspecialchars($posto['nome']) ?><?php if (!empty($posto['codigo'])): ?><small><?= htmlspecialchars($posto['codigo']) ?></small><?php endif; ?></h3>
                <form method="post" onsubmit="return confirm('Desativar este posto? Quem estiver de serviço nele sai do serviço. O histórico é mantido.');">
                    <input type="hidden" name="acao" value="desativar_posto">
                    <input type="hidden" name="posto_id" value="<?= $posto['id'] ?>">
                    <button type="submit" class="ghost"><span class="i" style="--icon-url:url('../images/icons/trash.svg')"></span>Desativar posto</button>
                </form>
            </div>

            <div class="rotulo">De serviço agora</div>
            <ul class="de-servico">
                <?php if (empty($posto['de_servico'])): ?>
                    <li class="vago">Ninguém de serviço neste posto.</li>
                <?php endif; ?>
                <?php foreach ($posto['de_servico'] as $e): ?>
                    <li>
                        <span>
                            <strong><?= htmlspecialchars(identificacaoAluno($e)) ?></strong>
                            <small> · <?= htmlspecialchars($e['esquadrao']) ?>/<?= htmlspecialchars($e['esquadrilha']) ?> · desde <?= htmlspecialchars($horario($e['inicio'])) ?><?= $e['registrado_por'] ? ' · registrado por ' . htmlspecialchars($e['registrado_por']) : '' ?></small>
                        </span>
                        <form method="post" class="form-linha" onsubmit="return confirm('Encerrar o serviço deste aluno?');">
                            <input type="hidden" name="acao" value="encerrar">
                            <input type="hidden" name="escala_id" value="<?= $e['id'] ?>">
                            <input type="text" name="motivo" placeholder="Motivo (opcional)" maxlength="<?= POSTO_SERVICO_LIMITE_MOTIVO ?>" style="width:170px;">
                            <button type="submit" class="ghost">Encerrar</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="rotulo">Colocar de serviço</div>
            <form method="post" class="form-linha">
                <input type="hidden" name="acao" value="escalar">
                <input type="hidden" name="posto_id" value="<?= $posto['id'] ?>">
                <input type="text" name="milhao_aluno" list="lista_alunos" placeholder="Nome ou milhão do aluno" required autocomplete="off" style="min-width:240px;">
                <?php if (!empty($posto['de_servico'])): ?>
                    <select name="substitui_escala_id" aria-label="Rende quem">
                        <?php foreach ($posto['de_servico'] as $i => $e): ?>
                            <option value="<?= $e['id'] ?>" <?= $i === 0 ? 'selected' : '' ?>>Rende <?= htmlspecialchars($e['nome_guerra']) ?></option>
                        <?php endforeach; ?>
                        <option value="">Não rende ninguém (entra junto)</option>
                    </select>
                    <input type="text" name="motivo" placeholder="Motivo da troca (opcional)" maxlength="<?= POSTO_SERVICO_LIMITE_MOTIVO ?>" style="min-width:200px;">
                <?php endif; ?>
                <button type="submit"><span class="i" style="--icon-url:url('../images/icons/user-plus.svg')"></span>Colocar de serviço</button>
            </form>

            <?php if (!empty($posto['historico'])): ?>
                <details class="historico">
                    <summary>Quem já passou por aqui hoje (<?= count($posto['historico']) ?>)</summary>
                    <ul>
                        <?php foreach ($posto['historico'] as $e): ?>
                            <li>
                                <?= htmlspecialchars(identificacaoAluno($e)) ?>
                                <small> · <?= htmlspecialchars($horario($e['inicio'])) ?> até <?= htmlspecialchars($horario($e['fim'])) ?><?= $e['motivo_saida'] ? ' · ' . htmlspecialchars($e['motivo_saida']) : '' ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php include __DIR__ . '/../../core/rodape_versao.php'; ?>
</body>
</html>
<?php mysqli_close($conexao); ?>
