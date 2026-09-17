<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/acesso_negado.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../core/alunos_core.php';
require_once __DIR__ . '/../../core/turmas_core.php';

if (!podeEditarEfetivo()) {
    exibirAcessoNegado("Seu cargo não tem a permissão 'editar_efetivo'.");
}

$conexao = conectarBanco();
$escopo = escopoEsquadrao();

$turmaId = (int) ($_GET['turma_id'] ?? $_POST['turma_id'] ?? 0);
$turma = buscarTurmaPorId($conexao, $turmaId);
if (!$turma) {
    die("Turma não encontrada.");
}

$resultado = null;
$erro = null;
$esquadraoEnviado = trim($_POST['esquadrao'] ?? ($turma['esquadrao_atual'] ?? ''));
$linhasEnviadas = $_POST['linhas'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($escopo !== null && $esquadraoEnviado !== $escopo) {
        $erro = "Você só pode cadastrar alunos no Esquadrão $escopo.";
    } elseif (trim($esquadraoEnviado) === '') {
        $erro = "Informe o esquadrão dessa leva de alunos.";
    } elseif (trim($linhasEnviadas) === '') {
        $erro = "Cole ao menos uma linha de aluno.";
    } else {
        $camposComuns = [
            'esquadrao' => $esquadraoEnviado,
            'curso' => $turma['curso'],
            'serie' => $turma['serie'],
            'turma_id' => $turmaId,
        ];
        $resultado = cadastrarAlunosEmLote($conexao, $linhasEnviadas, $camposComuns);
        if ($resultado['sucesso'] > 0 && empty($resultado['erros'])) {
            $linhasEnviadas = '';
        }
    }
}

$postosDisponiveis = listarPostosGraduacao($conexao);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Painel — Cadastrar alunos da turma</title>
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
    input, select, textarea { padding: 8px 10px; background: #ffffff; border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 13px; font-family: inherit; }
    select { max-width: 100%; }
    textarea { width: 100%; min-height: 220px; font-family: 'Consolas', 'Courier New', monospace; font-size: 12px; }
    label { display: block; font-size: 12px; color: var(--text-muted); margin-top: 12px; margin-bottom: 4px; }
    button { padding: 9px 18px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; margin-top: 18px; }
    .erro { color: var(--danger); font-size: 13px; }
    .ok { color: var(--ok); font-size: 13px; }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 6px 10px; text-align: left; vertical-align: top; }
    th { background: var(--azul-eear); color: #ffffff; }
    code { background: #eef1f6; padding: 1px 5px; border-radius: 4px; font-size: 12px; }
    .exemplo { background: #f8fafc; border: 1px dashed var(--border); border-radius: 6px; padding: 10px 12px; font-family: 'Consolas', 'Courier New', monospace; font-size: 12px; white-space: pre; overflow-x: auto; }
    .i { display: inline-block; width: 13px; height: 13px; vertical-align: -2px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 4px; }
</style>
</head>
<body>

<div class="topbar">
    <div><strong>ARGOS</strong> <a href="turmas.php"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span>turmas</a></div>
    <div><a href="index.php?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a></div>
</div>

<div class="container">
    <h2>Cadastrar alunos — Turma <?= htmlspecialchars($turma['nome']) ?></h2>
    <p style="color: var(--text-muted); font-size: 13px; margin-top: -8px;">
        Curso <strong><?= htmlspecialchars($turma['curso']) ?></strong><?= $turma['serie'] ? ' / série ' . htmlspecialchars($turma['serie']) : '' ?> —
        todos os alunos cadastrados aqui já entram vinculados a essa turma.
    </p>

    <?php if ($erro): ?><p class="erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($resultado): ?>
        <p class="ok"><?= $resultado['sucesso'] ?> aluno(s) cadastrado(s) com sucesso.</p>
        <?php if (!empty($resultado['erros'])): ?>
            <p class="erro"><?= count($resultado['erros']) ?> linha(s) com erro — corrija e cole de novo só essas linhas:</p>
            <table>
                <tr><th>Linha</th><th>Conteúdo</th><th>Erro</th></tr>
                <?php foreach ($resultado['erros'] as $e): ?>
                    <tr>
                        <td><?= (int) $e['linha'] ?></td>
                        <td><code><?= htmlspecialchars($e['texto']) ?></code></td>
                        <td><?= htmlspecialchars($e['erro']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    <?php endif; ?>

    <div class="card">
        <p style="font-size: 13px;">
            Cole uma linha por aluno, campos separados por <strong>tab</strong> (colando direto de uma planilha) ou <strong>;</strong>,
            nessa ordem:
        </p>
        <div class="exemplo">Posto;Nome de Guerra;Sexo;Identidade Militar;Milhão;Esquadrilha;Especialidade;Sub-especialidade</div>
        <p style="font-size: 12px; color: var(--text-muted);">
            Os 2 últimos campos (Especialidade, Sub-especialidade) são opcionais — pode deixar em branco ou omitir.
            Posto em branco vira <code>GS</code> (<?= htmlspecialchars($postosDisponiveis[0]['exibicao'] ?? 'AL') ?>) por padrão.
            Sexo: <code>M</code> ou <code>F</code>. Curso e série vêm da turma, não precisa repetir.
        </p>
        <div class="exemplo">GS;SILVA;M;12345678900;26/3200;A;BMA
GS;SOUZA;F;12345678901;26/3201;B;BCT;SPEC</div>

        <form method="post">
            <input type="hidden" name="turma_id" value="<?= $turmaId ?>">

            <label>Esquadrão dessa leva</label>
            <input type="text" name="esquadrao" value="<?= htmlspecialchars($esquadraoEnviado) ?>" <?= $escopo !== null ? 'readonly' : '' ?> required>

            <label>Alunos (uma linha por aluno)</label>
            <textarea name="linhas" placeholder="Cole aqui..." required><?= htmlspecialchars($linhasEnviadas) ?></textarea>

            <button type="submit">Cadastrar leva</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../core/rodape_versao.php'; ?>
</body>
</html>
<?php mysqli_close($conexao); ?>
