<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/acesso_negado.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../core/retiradas_core.php';
require_once __DIR__ . '/../../core/motivos_core.php';
require_once __DIR__ . '/../../core/alunos_core.php';
require_once __DIR__ . '/../../core/dispensas_core.php';
require_once __DIR__ . '/../../core/servicos_core.php';


$conexao = conectarBanco();

if (!temPermissao($conexao, 'painel', $_SESSION['painel_cargo'], 'registrar_retirada', idUsuarioPainel())) {
    exibirAcessoNegado("Seu cargo não tem a permissão 'registrar_retirada'.");
}

$escopo = escopoEsquadrao();
$id = (int)($_GET['id'] ?? 0);

$retirada = buscarRetiradaPorId($conexao, $id);
if (!$retirada) {
    die("Retirada não encontrada.");
}
if ($escopo !== null && $retirada['esquadrao'] !== $escopo) {
    exibirAcessoNegado("Você não tem permissão para ver retiradas fora do Esquadrão $escopo.");
}

$mensagem = null;
$erro = null;
$podeLancarDispensa = podeGerenciarDispensas();

// ---------- Lançar dispensa médica direto na chamada ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'lancar_dispensa' && $retirada['status'] === 'pendente' && $podeLancarDispensa) {
    $alunoId = (int) ($_POST['aluno_id'] ?? 0);
    $dados = $_POST;
    $dados['painel_usuario_id'] = idUsuarioPainel();

    $resultado = criarDispensa($conexao, $dados);
    if ($resultado['ok']) {
        $motivoDispensa = buscarMotivoPorCodigo($conexao, 'DMED');
        if ($motivoDispensa) {
            marcarItem($conexao, $id, $alunoId, 0, $motivoDispensa['id'], null);
        }
        $mensagem = "Dispensa lançada e chamada atualizada.";
    } else {
        $erro = $resultado['erro'];
    }
}

// ---------- Salvar marcações ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['itens']) && $retirada['status'] === 'pendente') {
    foreach ($_POST['itens'] as $alunoId => $item) {
        $presente = isset($item['presente']) ? 1 : 0;
        $motivoFaltaId = !empty($item['motivo_falta_id']) ? (int)$item['motivo_falta_id'] : null;
        $observacao = trim($item['observacao'] ?? '') ?: null;
        marcarItem($conexao, $id, (int)$alunoId, $presente, $motivoFaltaId, $observacao, $item['servico_id'] ?? null);
    }
    $mensagem = "Marcações salvas.";

    if (isset($_POST['enviar'])) {
        $resultadoEnvio = enviarRetirada($conexao, $id);
        if ($resultadoEnvio['ok']) {
            $mensagem = "Retirada enviada. Protocolo: " . $resultadoEnvio['protocolo'];
        } else {
            $erro = $resultadoEnvio['erro'];
        }
    }

    $retirada = buscarRetiradaPorId($conexao, $id);
}

$itens = listarItensRetirada($conexao, $id);

$motivos = listarMotivos($conexao);
$tiposDispensa = $podeLancarDispensa ? listarDispensaTipos($conexao) : [];
$dataInicioMin = date('Y-m-d', strtotime('-' . DISPENSA_TOLERANCIA_DIAS_PASSADO . ' days'));
$dataInicioMax = date('Y-m-d', strtotime('+' . DISPENSA_TOLERANCIA_DIAS_FUTURO . ' days'));

$tiposRetirada = TIPOS_RETIRADA_ROTULOS;
$somenteLeitura = $retirada['status'] === 'enviada';

$servicos = listarServicos($conexao);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Painel — Chamada</title>
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
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 6px 10px; text-align: left; vertical-align: middle; }
    th { background: var(--azul-eear); color: #ffffff; }
    .scroll-x { overflow-x: auto; min-width: 0; }
    select, input[type=text] { padding: 6px 8px; background: #ffffff; border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 12px; }
    select { max-width: 220px; }
    button { padding: 9px 18px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; margin-top: 14px; margin-right: 8px; }
    button.enviar { background: var(--ok); }
    .erro { color: var(--danger); font-size: 13px; }
    .ok { color: var(--ok); font-size: 13px; }
    .falta-row { background: #fdeaea; }
    .badge { font-size: 11px; padding: 2px 8px; border-radius: 999px; }
    .badge.pendente { background: #fff3cd; color: #8a6100; }
    .badge.enviada { background: #d9f2e3; color: var(--ok); }
    .tag-check { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; background: #eef1f6; border: 1px solid var(--border); border-radius: 999px; padding: 4px 10px; cursor: pointer; }
    .tag-check input { margin: 0; }
    .i { display: inline-block; width: 16px; height: 16px; vertical-align: -3px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 5px; }
</style>
<link rel="stylesheet" href="../css/argos-admin.css">
<script src="../js/argos-admin.js" defer></script>
<script>
    // Motivos e postos de serviço, pro painel de opções (cada opção é um botão).
    const MOTIVOS = <?= json_encode(array_map(fn($m) => ['id' => (int) $m['id'], 'nome' => $m['nome'], 'codigo' => $m['codigo'], 'falta' => $m['classificacao'] === 'falta'], $motivos), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const POSTOS = <?= json_encode(array_map(fn($p) => ['id' => (int) $p['id'], 'nome' => $p['nome']], $servicos), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const CODIGO_MOTIVO_SERVICO = 'SV';

    function escaparHtml(texto) {
        const div = document.createElement('div');
        div.textContent = texto;
        return div.innerHTML;
    }
    function semAcento(texto) {
        return String(texto).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    }

    // O botão da linha mostra o motivo escolhido; clicar nele reabre o painel.
    function pintarMotivo(alunoId) {
        const botao = document.getElementById('motivo_botao_' + alunoId);
        const motivo = MOTIVOS.find(m => m.id === Number(document.getElementById('motivo_' + alunoId).value));
        const posto = POSTOS.find(p => p.id === Number(document.getElementById('servico_' + alunoId).value));
        botao.classList.toggle('vazio', !motivo);
        botao.innerHTML = motivo
            ? (motivo.codigo ? '<b class="sigla">' + escaparHtml(motivo.codigo) + '</b>' : '')
                + '<span>' + escaparHtml(motivo.nome) + (posto ? ' · ' + escaparHtml(posto.nome) : '') + '</span>'
            : '<span>Escolher motivo…</span>';
    }

    function alternarMotivo(alunoId, presenteCheckbox) {
        const falta = !presenteCheckbox.checked;
        document.getElementById('linha_' + alunoId).classList.toggle('falta-row', falta);
        document.getElementById('motivo_botao_' + alunoId).hidden = !falta;
        if (falta && !document.getElementById('motivo_' + alunoId).value) {
            abrirPainelMotivo(alunoId);
        }
    }

    // Painel de opções: primeiro o motivo; se for "Serviço" (e houver postos
    // cadastrados), o posto em seguida. Fechar sem escolher não muda nada.
    function mostrarOpcoes({ titulo, opcoes, selecionado, comBusca, aoEscolher }) {
        const painel = document.getElementById('painelOpcoes');
        const grade = document.getElementById('painelOpcoesGrade');
        const busca = document.getElementById('painelOpcoesBusca');
        document.getElementById('painelOpcoesTitulo').textContent = titulo;
        busca.value = '';
        busca.hidden = !comBusca;

        function desenhar() {
            const termo = semAcento(busca.value);
            const botoes = opcoes.map((o, indice) => {
                if (termo && !semAcento((o.sigla || '') + ' ' + o.nome).includes(termo)) return '';
                const classes = 'opcao' + (o.destaque ? ' destaque' : '') + (o.discreta ? ' discreta' : '')
                    + (selecionado !== null && o.valor === selecionado ? ' escolhida' : '');
                return '<button type="button" class="' + classes + '" data-indice="' + indice + '">'
                    + (o.sigla ? '<b class="sigla">' + escaparHtml(o.sigla) + '</b>' : '')
                    + '<span>' + escaparHtml(o.nome) + '</span></button>';
            }).join('');
            grade.innerHTML = botoes || '<p class="painel-opcoes-vazio">Nenhuma opção com esse nome.</p>';
        }
        busca.oninput = desenhar;
        grade.onclick = (evento) => {
            const botao = evento.target.closest('.opcao');
            if (botao) aoEscolher(opcoes[Number(botao.dataset.indice)].valor);
        };
        desenhar();
        if (!painel.open) painel.showModal();
        grade.scrollTop = 0;
        if (comBusca) busca.focus();
    }

    function abrirPainelMotivo(alunoId) {
        const campoMotivo = document.getElementById('motivo_' + alunoId);
        const campoPosto = document.getElementById('servico_' + alunoId);
        document.getElementById('painelOpcoesAluno').textContent = document.getElementById('motivo_botao_' + alunoId).dataset.aluno;

        function gravar(motivoId, postoId) {
            campoMotivo.value = motivoId;
            campoPosto.value = postoId || '';
            pintarMotivo(alunoId);
            document.getElementById('painelOpcoes').close();
        }

        mostrarOpcoes({
            titulo: 'Motivo da falta',
            opcoes: MOTIVOS.map(m => ({ valor: m.id, sigla: m.codigo || '', nome: m.nome, destaque: m.falta }))
                .sort((x, y) => Number(y.destaque) - Number(x.destaque)),
            selecionado: campoMotivo.value ? Number(campoMotivo.value) : null,
            comBusca: true,
            aoEscolher: (motivoId) => {
                const motivo = MOTIVOS.find(m => m.id === motivoId);
                if (motivo.codigo !== CODIGO_MOTIVO_SERVICO || POSTOS.length === 0) {
                    gravar(motivoId, null);
                    return;
                }
                mostrarOpcoes({
                    titulo: 'Qual posto de serviço?',
                    opcoes: POSTOS.map(p => ({ valor: p.id, nome: p.nome })).concat([{ valor: null, nome: 'Não informar o posto', discreta: true }]),
                    selecionado: campoPosto.value ? Number(campoPosto.value) : null,
                    comBusca: POSTOS.length > 8,
                    aoEscolher: (postoId) => gravar(motivoId, postoId),
                });
            },
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.motivo-botao').forEach(botao => pintarMotivo(botao.id.replace('motivo_botao_', '')));
    });

    function alternarDispensa(alunoId) {
        const linha = document.getElementById('dispensa_' + alunoId);
        linha.hidden = !linha.hidden;
    }

    function lancarDispensa(alunoId) {
        const campos = {
            acao: 'lancar_dispensa',
            aluno_id: alunoId,
            data_inicio: document.getElementById('disp_inicio_' + alunoId).value,
            data_termino: document.getElementById('disp_termino_' + alunoId).value,
            numero: document.getElementById('disp_numero_' + alunoId).value,
            motivo: document.getElementById('disp_motivo_' + alunoId).value,
            medico_responsavel: document.getElementById('disp_medico_' + alunoId).value,
            dispensado_de: document.getElementById('disp_dispensado_de_' + alunoId).value,
        };
        if (!campos.motivo.trim()) {
            alert('Informe o motivo da dispensa.');
            return;
        }
        if (!campos.medico_responsavel.trim()) {
            alert('Informe o Oficial Médico responsável pela dispensa.');
            return;
        }
        const form = document.createElement('form');
        form.method = 'post';
        for (const [nome, valor] of Object.entries(campos)) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = nome;
            input.value = valor;
            form.appendChild(input);
        }
        document.querySelectorAll('.disp_tipo_' + alunoId + ':checked').forEach(chk => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'dispensa_tipo_ids[]';
            input.value = chk.value;
            form.appendChild(input);
        });
        document.body.appendChild(form);
        form.submit();
    }
</script>
</head>
<body>

<div class="topbar">
    <div><strong><span class="marca-argos" role="img" aria-label="Argos">ARG<i class="olho"></i>S</span></strong> <a href="retiradas.php"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span>retiradas</a></div>
    <div><a href="index.php?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a></div>
</div>

<div class="container">
    <h2>
        Chamada — <?= htmlspecialchars($tiposRetirada[$retirada['tipo']] ?? $retirada['tipo']) ?>
        (<?= htmlspecialchars($retirada['agrupamento_tipo']) ?>: <?= htmlspecialchars($retirada['agrupamento_valor']) ?>)
        <span class="badge <?= $retirada['status'] ?>"><?= htmlspecialchars($retirada['status']) ?></span>
    </h2>
    <p style="color: var(--text-muted); font-size: 13px;">Responsável: <strong><?= htmlspecialchars($retirada['responsavel_nome'] ?? '—') ?></strong></p>
    <?php if ($retirada['protocolo']): ?>
        <p style="color: var(--text-muted); font-size: 13px;">Protocolo: <strong><?= htmlspecialchars($retirada['protocolo']) ?></strong></p>
    <?php endif; ?>

    <?php if ($erro): ?><p class="erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($mensagem): ?><p class="ok"><?= htmlspecialchars($mensagem) ?></p><?php endif; ?>

    <div class="card">
        <form method="post">
            <div class="scroll-x">
            <table>
                <tr><th>Presente</th><th>Identificação</th><th>Motivo (se faltou)</th><th>Observação</th><?php if (!$somenteLeitura && $podeLancarDispensa): ?><th>Dispensa</th><?php endif; ?></tr>
                <?php foreach ($itens as $item): ?>
                    <tr id="linha_<?= $item['aluno_id'] ?>" class="<?= !$item['presente'] ? 'falta-row' : '' ?>">
                        <td>
                            <input type="checkbox" name="itens[<?= $item['aluno_id'] ?>][presente]"
                                <?= $item['presente'] ? 'checked' : '' ?>
                                <?= $somenteLeitura ? 'disabled' : '' ?>
                                onchange="alternarMotivo(<?= $item['aluno_id'] ?>, this)">
                        </td>
                        <td><?= htmlspecialchars(identificacaoAluno($item)) ?></td>
                        <td>
                            <input type="hidden" id="motivo_<?= $item['aluno_id'] ?>" name="itens[<?= $item['aluno_id'] ?>][motivo_falta_id]" value="<?= htmlspecialchars((string) ($item['motivo_falta_id'] ?? '')) ?>">
                            <input type="hidden" id="servico_<?= $item['aluno_id'] ?>" name="itens[<?= $item['aluno_id'] ?>][servico_id]" value="<?= htmlspecialchars((string) ($item['servico_id'] ?? '')) ?>">
                            <button type="button" class="motivo-botao" id="motivo_botao_<?= $item['aluno_id'] ?>"
                                data-aluno="<?= htmlspecialchars(identificacaoAluno($item)) ?>"
                                onclick="abrirPainelMotivo(<?= $item['aluno_id'] ?>)"
                                <?= $item['presente'] ? 'hidden' : '' ?> <?= $somenteLeitura ? 'disabled' : '' ?>></button>
                        </td>
                        <td>
                            <input type="text" name="itens[<?= $item['aluno_id'] ?>][observacao]"
                                value="<?= htmlspecialchars($item['observacao'] ?? '') ?>" <?= $somenteLeitura ? 'disabled' : '' ?>>
                        </td>
                        <?php if (!$somenteLeitura && $podeLancarDispensa): ?>
                        <td>
                            <button type="button" onclick="alternarDispensa(<?= $item['aluno_id'] ?>)"><span class="i" style="--icon-url:url('../images/icons/medical-cross.svg')"></span>Lançar</button>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php if (!$somenteLeitura && $podeLancarDispensa): ?>
                    <tr id="dispensa_<?= $item['aluno_id'] ?>" hidden>
                        <td colspan="5">
                            <div class="form-linha" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; padding:8px 0;">
                                <label style="font-size:12px; color:var(--text-muted);">Início <input type="date" id="disp_inicio_<?= $item['aluno_id'] ?>" value="<?= date('Y-m-d') ?>" min="<?= $dataInicioMin ?>" max="<?= $dataInicioMax ?>"></label>
                                <label style="font-size:12px; color:var(--text-muted);">Término <input type="date" id="disp_termino_<?= $item['aluno_id'] ?>" value="<?= date('Y-m-d') ?>"></label>
                                <label style="font-size:12px; color:var(--text-muted);">Nº <input type="text" id="disp_numero_<?= $item['aluno_id'] ?>" style="width:70px;"></label>
                                <label style="font-size:12px; color:var(--text-muted);">Motivo <input type="text" id="disp_motivo_<?= $item['aluno_id'] ?>" style="min-width:160px;"></label>
                                <label style="font-size:12px; color:var(--text-muted);">Oficial Médico <input type="text" id="disp_medico_<?= $item['aluno_id'] ?>" maxlength="100" placeholder="Posto e nome" style="min-width:180px;"></label>
                            </div>
                            <div class="form-linha" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; padding:0 0 8px;">
                                <span style="font-size:12px; color:var(--text-muted);">Dispensado de:</span>
                                <?php foreach ($tiposDispensa as $t): ?>
                                    <label class="tag-check"><input type="checkbox" class="disp_tipo_<?= $item['aluno_id'] ?>" value="<?= $t['id'] ?>"> <?= htmlspecialchars($t['nome']) ?></label>
                                <?php endforeach; ?>
                                <input type="text" id="disp_dispensado_de_<?= $item['aluno_id'] ?>" placeholder="Específico (opcional)" style="min-width:160px;">
                                <button type="button" onclick="lancarDispensa(<?= $item['aluno_id'] ?>)"><span class="i" style="--icon-url:url('../images/icons/device-floppy.svg')"></span>Salvar dispensa</button>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </table>
            </div>

            <?php if (!$somenteLeitura): ?>
                <button type="submit"><span class="i" style="--icon-url:url('../images/icons/device-floppy.svg')"></span>Salvar</button>
                <button type="submit" name="enviar" value="1" class="enviar" onclick="return confirm('Enviar a chamada? Depois de enviada não dá mais pra editar.');"><span class="i" style="--icon-url:url('../images/icons/send.svg')"></span>Salvar e enviar chamada</button>
            <?php endif; ?>
        </form>
    </div>
</div>

<dialog id="painelOpcoes" class="painel-opcoes">
    <div class="painel-opcoes-topo">
        <div>
            <h3 id="painelOpcoesTitulo"></h3>
            <p id="painelOpcoesAluno"></p>
        </div>
        <button type="button" class="ghost" onclick="document.getElementById('painelOpcoes').close()">Fechar</button>
    </div>
    <input type="search" id="painelOpcoesBusca" placeholder="Buscar pela sigla ou pelo nome…" aria-label="Buscar opção">
    <div class="painel-opcoes-grade" id="painelOpcoesGrade"></div>
</dialog>

<?php include __DIR__ . '/../../core/rodape_versao.php'; ?>
</body>
</html>
<?php mysqli_close($conexao); ?>
