<?php

// Tela de controle dos QR codes dos alunos — a mesma no Painel de Comando
// (web/painel/qrcodes.php) e no Ikarus37 (web/ikarus37/qrcodes.php). Cada um
// cuida do próprio login, permissão e barra superior, e chama as duas funções
// daqui: uma processa o formulário, a outra desenha o miolo da página.
//
// O conteúdo do QR só passa por aqui no POST: vira impressão digital em
// core/qrcodes_core.php e nunca é gravado nem devolvido na tela.

require_once __DIR__ . '/qrcodes_core.php';
require_once __DIR__ . '/alunos_core.php';

const QR_TELA_LIMITE_LISTA = 100;

/**
 * Processa o POST da tela. $escopo: esquadrão a que o operador está limitado
 * (null = todos).
 *
 * @return array{mensagem: ?string, erro: ?string, conferencia: ?array, lote: ?array}
 */
function processarControleQr($conexao, $escopo) {
    $retorno = ['mensagem' => null, 'erro' => null, 'conferencia' => null, 'lote' => null];
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return $retorno;
    }

    $acao = $_POST['acao'] ?? '';
    $conteudo = is_string($_POST['conteudo'] ?? null) ? $_POST['conteudo'] : '';

    // Aluno alvo, já conferindo o escopo de quem está operando.
    $alunoAlvo = function () use ($conexao, $escopo) {
        $aluno = buscarAlunoPorId($conexao, (int) ($_POST['aluno_id'] ?? 0));
        if (!$aluno || ($escopo !== null && $aluno['esquadrao'] !== $escopo)) {
            return null;
        }
        return $aluno;
    };

    if ($acao === 'definir') {
        $aluno = $alunoAlvo();
        if (!$aluno) {
            $retorno['erro'] = 'Aluno não encontrado.';
        } else {
            $resultado = definirQrDoAluno($conexao, (int) $aluno['id'], $conteudo);
            if ($resultado['ok']) {
                $retorno['mensagem'] = 'QR ' . ($resultado['trocou'] ? 'trocado' : 'cadastrado') . ' para ' . identificacaoAluno($aluno) . '.'
                    . ($resultado['trocou'] ? ' Quem estava logado com o QR antigo foi desconectado.' : '');
            } else {
                $retorno['erro'] = $resultado['erro'];
            }
        }
    }

    if ($acao === 'remover') {
        $aluno = $alunoAlvo();
        if (!$aluno) {
            $retorno['erro'] = 'Aluno não encontrado.';
        } else {
            removerQrDoAluno($conexao, (int) $aluno['id']);
            $retorno['mensagem'] = 'QR removido de ' . identificacaoAluno($aluno) . '. Ele não entra mais no app até cadastrar outro.';
        }
    }

    if ($acao === 'conferir') {
        $impressao = impressaoDoQr($conteudo);
        if ($impressao === null) {
            $retorno['erro'] = 'QR vazio ou grande demais (máximo de ' . QR_CONTEUDO_MAX . ' caracteres).';
        } else {
            $lido = trim($conteudo);
            $dono = buscarAlunoPorImpressaoQr($conexao, $impressao);
            // Fora do escopo do operador, só diz que o QR é de outro esquadrão.
            $donoVisivel = $dono && ($escopo === null || $dono['esquadrao'] === $escopo);
            $retorno['conferencia'] = [
                'caracteres' => mb_strlen($lido),
                'ja_e_codigo' => (bool) preg_match('/^[a-f0-9]{64}$/i', $lido),
                'impressao' => substr($impressao, 0, 12),
                'dono' => $donoVisivel ? $dono : null,
                'de_outro_esquadrao' => $dono && !$donoVisivel,
            ];
        }
    }

    if ($acao === 'importar') {
        $retorno['lote'] = importarQrsEmLote($conexao, $_POST['lote'] ?? '', $escopo);
        $retorno['mensagem'] = $retorno['lote']['gravados'] . ' QR(s) cadastrado(s) em lote.';
    }

    return $retorno;
}

/**
 * Desenha o miolo da página (o que vai dentro de .container).
 * $caminhoLeitor: URL do js/qr-scanner.js da PWA, relativa à página que inclui.
 */
function renderizarControleQr($conexao, $escopo, array $estado, $caminhoLeitor) {
    $cobertura = coberturaDeQr($conexao, $escopo);
    $filtros = [
        'esquadrao' => $escopo ?? ($_GET['esquadrao'] ?? null),
        'situacao' => in_array($_GET['situacao'] ?? '', ['com', 'sem'], true) ? $_GET['situacao'] : '',
        'busca' => trim($_GET['busca'] ?? ''),
        'limite' => QR_TELA_LIMITE_LISTA,
    ];
    [$alunos, $totalFiltrado] = listarAlunosComSituacaoDoQr($conexao, $filtros);
    $percentual = $cobertura['total'] > 0 ? round($cobertura['com_qr'] / $cobertura['total'] * 100) : 0;
    $link = fn(array $q) => '?' . http_build_query(array_filter($q, fn($v) => $v !== null && $v !== '')) . '#lista';
    $e = fn($texto) => htmlspecialchars((string) $texto);
    $conferencia = $estado['conferencia'];
    ?>
    <h2>QR Codes dos alunos <?= $escopo ? '— Esquadrão ' . $e($escopo) : '' ?></h2>
    <p style="color: var(--text-muted); font-size: 13px; margin-top: -8px;">
        O QR da identidade é o que o aluno usa pra entrar na Área Funcional. Aqui se vê quem já tem e se cadastra o de quem falta.
        O sistema aceita QR com qualquer conteúdo e guarda só a impressão digital dele — o conteúdo em si nunca é gravado.
    </p>

    <?php if ($estado['erro']): ?><p class="erro"><?= $e($estado['erro']) ?></p><?php endif; ?>
    <?php if ($estado['mensagem']): ?><p class="ok"><?= $e($estado['mensagem']) ?></p><?php endif; ?>

    <?php if ($estado['lote'] && $estado['lote']['erros']): ?>
        <div class="card">
            <h4 style="margin-top:0;">Linhas do lote que não entraram (<?= count($estado['lote']['erros']) ?>)</h4>
            <ul style="margin:0; padding-left:18px; font-size:13px;">
                <?php foreach ($estado['lote']['erros'] as $linhaErro): ?><li><?= $e($linhaErro) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($conferencia): ?>
        <div class="card qr-conferencia">
            <h4 style="margin-top:0;">Resultado da conferência</h4>
            <dl class="qr-ficha">
                <dt>De quem é</dt>
                <dd>
                    <?php if ($conferencia['dono']): ?>
                        <strong><?= $e(identificacaoAluno($conferencia['dono'])) ?></strong>
                        — <?= $e($conferencia['dono']['esquadrao']) ?>/<?= $e($conferencia['dono']['esquadrilha']) ?>
                        <?php if (empty($conferencia['dono']['ativo'])): ?><span class="badge inativo">aluno inativo</span><?php endif; ?>
                    <?php elseif ($conferencia['de_outro_esquadrao']): ?>
                        Já está cadastrado para um aluno de outro esquadrão.
                    <?php else: ?>
                        <strong>Ninguém</strong> — esse QR ainda não está cadastrado.
                    <?php endif; ?>
                </dd>
                <dt>Tamanho do conteúdo</dt>
                <dd><?= (int) $conferencia['caracteres'] ?> caracteres<?= $conferencia['ja_e_codigo'] ? ' — já é um código de 64 caracteres hexadecimais (vale como está)' : ' — o sistema usa a impressão digital dele' ?></dd>
                <dt>Impressão digital</dt>
                <dd><code><?= $e($conferencia['impressao']) ?>…</code></dd>
            </dl>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="kpis">
            <a class="kpi kpi-link" href="<?= $e($link([])) ?>"><div class="valor"><?= $cobertura['total'] ?></div><div class="rotulo">Alunos na ativa</div></a>
            <a class="kpi kpi-link" href="<?= $e($link(['situacao' => 'com'])) ?>"><div class="valor" style="color:var(--ok)"><?= $cobertura['com_qr'] ?></div><div class="rotulo">Com QR cadastrado</div></a>
            <a class="kpi kpi-link kpi-pane" href="<?= $e($link(['situacao' => 'sem'])) ?>"><div class="valor"><?= $cobertura['sem_qr'] ?></div><div class="rotulo">Sem QR — não entram no app</div></a>
            <div class="kpi"><div class="valor"><?= $percentual ?>%</div><div class="rotulo">Cobertura</div></div>
        </div>

        <?php if ($escopo === null && $cobertura['por_esquadrao']): ?>
            <h3 class="subtitulo" style="margin-top:18px;">Por esquadrão</h3>
            <div class="scroll-x">
            <table>
                <tr><th>Esquadrão</th><th class="num">Alunos</th><th class="num">Com QR</th><th class="num">Sem QR</th></tr>
                <?php foreach ($cobertura['por_esquadrao'] as $linha): ?>
                    <tr>
                        <td><a class="filtro-link" href="<?= $e($link(['esquadrao' => $linha['esquadrao']])) ?>"><?= $e($linha['esquadrao']) ?></a></td>
                        <td class="num"><?= $linha['total'] ?></td>
                        <td class="num"><a class="filtro-link" href="<?= $e($link(['esquadrao' => $linha['esquadrao'], 'situacao' => 'com'])) ?>"><?= $linha['com_qr'] ?></a></td>
                        <td class="num"><a class="filtro-link" href="<?= $e($link(['esquadrao' => $linha['esquadrao'], 'situacao' => 'sem'])) ?>"><?= $linha['sem_qr'] ?></a></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h4 style="margin-top:0;">Conferir um QR</h4>
        <p style="color: var(--text-muted); font-size: 13px; margin-top:-6px;">Leia qualquer QR pra ver de quem é e quantos caracteres ele tem. Não grava nada.</p>
        <button type="button" onclick="abrirLeitorQr({ acao: 'conferir', titulo: 'Conferir um QR' })"><span class="i" style="--icon-url:url('../images/icons/qrcode.svg')"></span>Ler um QR</button>
    </div>

    <div class="card" id="lista">
        <form method="get" class="form-linha" action="#lista">
            <?php if ($escopo === null): ?>
                <select name="esquadrao" aria-label="Esquadrão">
                    <option value="">Todos os esquadrões</option>
                    <?php foreach ($cobertura['por_esquadrao'] as $linha): ?>
                        <option value="<?= $e($linha['esquadrao']) ?>" <?= ($filtros['esquadrao'] ?? '') === $linha['esquadrao'] ? 'selected' : '' ?>><?= $e($linha['esquadrao']) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <select name="situacao" aria-label="Situação do QR">
                <option value="">Com e sem QR</option>
                <option value="sem" <?= $filtros['situacao'] === 'sem' ? 'selected' : '' ?>>Só sem QR</option>
                <option value="com" <?= $filtros['situacao'] === 'com' ? 'selected' : '' ?>>Só com QR</option>
            </select>
            <input type="text" name="busca" placeholder="Nome ou milhão" value="<?= $e($filtros['busca']) ?>">
            <button type="submit"><span class="i" style="--icon-url:url('../images/icons/filter.svg')"></span>Filtrar</button>
        </form>

        <p style="color: var(--text-muted); font-size: 13px;">
            <?= $totalFiltrado ?> aluno(s)<?= $totalFiltrado > count($alunos) ? ' — mostrando os primeiros ' . count($alunos) . '; filtre por esquadrão, situação ou nome pra achar os demais' : '' ?>.
        </p>

        <div class="scroll-x">
        <table>
            <tr><th>Aluno</th><th>Esquadrão / Esquadrilha</th><th>QR</th><th>Ações</th></tr>
            <?php foreach ($alunos as $a): ?>
                <?php $identificacao = identificacaoAluno($a); ?>
                <tr>
                    <td><strong><?= $e($identificacao) ?></strong></td>
                    <td><?= $e($a['esquadrao']) ?> / <?= $e($a['esquadrilha']) ?></td>
                    <td><span class="badge <?= $a['tem_qr'] ? 'ativo' : 'inativo' ?>"><?= $a['tem_qr'] ? 'cadastrado' : 'sem QR' ?></span></td>
                    <td style="white-space:nowrap;">
                        <button type="button" onclick='abrirLeitorQr(<?= htmlspecialchars(json_encode(['acao' => 'definir', 'alunoId' => (int) $a['id'], 'titulo' => ($a['tem_qr'] ? 'Trocar o QR de ' : 'Cadastrar o QR de ') . $identificacao], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)'>
                            <span class="i" style="--icon-url:url('../images/icons/qrcode.svg')"></span><?= $a['tem_qr'] ? 'Trocar QR' : 'Cadastrar QR' ?>
                        </button>
                        <?php if ($a['tem_qr']): ?>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Remover o QR deste aluno? Ele deixa de conseguir entrar no app.');">
                                <input type="hidden" name="acao" value="remover">
                                <input type="hidden" name="aluno_id" value="<?= (int) $a['id'] ?>">
                                <button type="submit" class="ghost">Remover</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($alunos)): ?>
                <tr><td colspan="4" style="color: var(--text-muted);">Nenhum aluno com esse filtro.</td></tr>
            <?php endif; ?>
        </table>
        </div>
    </div>

    <div class="card">
        <details>
            <summary>Cadastrar em lote</summary>
            <p style="color: var(--text-muted); font-size: 13px;">
                Uma linha por aluno, no formato <code>milhão;conteúdo do QR</code>. Serve pra quando os QR já foram lidos numa planilha.
                Quem já tinha QR tem o dele trocado.
            </p>
            <form method="post">
                <input type="hidden" name="acao" value="importar">
                <textarea name="lote" rows="6" style="width:100%;" placeholder="26/3138;conteúdo lido do QR&#10;26/3119;conteúdo lido do QR" spellcheck="false" required></textarea>
                <p><button type="submit"><span class="i" style="--icon-url:url('../images/icons/device-floppy.svg')"></span>Cadastrar o lote</button></p>
            </form>
        </details>
    </div>

    <!-- Leitor: câmera, leitor de mesa (que "digita" o conteúdo) ou colar. -->
    <dialog id="leitorQr" class="leitor-qr">
        <form method="post" id="formLeitorQr">
            <input type="hidden" name="acao" id="leitorAcao">
            <input type="hidden" name="aluno_id" id="leitorAlunoId">
            <h3 id="leitorTitulo" style="margin-top:0;"></h3>
            <div class="leitor-video"><video id="leitorVideo" autoplay playsinline muted></video></div>
            <p id="leitorStatus" class="leitor-status">Aponte a câmera para o QR.</p>
            <label style="display:block; font-size:12px; color:var(--text-muted);">
                Sem câmera? Use um leitor de mesa ou cole o conteúdo do QR aqui:
                <input type="text" name="conteudo" id="leitorConteudo" autocomplete="off" spellcheck="false" maxlength="<?= QR_CONTEUDO_MAX ?>" style="width:100%; margin-top:4px;">
            </label>
            <div class="form-linha" style="margin-top:14px; justify-content:flex-end;">
                <button type="button" class="ghost" onclick="fecharLeitorQr()">Cancelar</button>
                <button type="submit">Confirmar</button>
            </div>
        </form>
    </dialog>

    <script src="<?= $e($caminhoLeitor) ?>"></script>
    <script>
        function abrirLeitorQr({ acao, alunoId = '', titulo }) {
            document.getElementById('leitorAcao').value = acao;
            document.getElementById('leitorAlunoId').value = alunoId;
            document.getElementById('leitorTitulo').textContent = titulo;
            document.getElementById('leitorConteudo').value = '';
            const status = document.getElementById('leitorStatus');
            status.textContent = 'Aponte a câmera para o QR.';
            document.getElementById('leitorQr').showModal();

            ArgosQrScanner.iniciar(
                document.getElementById('leitorVideo'),
                (valor) => {
                    // Leu: preenche e envia. O conteúdo vai direto pro servidor, que só guarda a impressão digital.
                    document.getElementById('leitorConteudo').value = valor;
                    status.textContent = 'QR lido. Enviando…';
                    document.getElementById('formLeitorQr').submit();
                },
                () => { status.textContent = 'Não consegui abrir a câmera. Use um leitor de mesa ou cole o conteúdo do QR no campo abaixo.'; }
            );
        }

        function fecharLeitorQr() {
            ArgosQrScanner.parar();
            document.getElementById('leitorQr').close();
        }

        document.getElementById('leitorQr').addEventListener('close', () => ArgosQrScanner.parar());
        document.getElementById('formLeitorQr').addEventListener('submit', (evento) => {
            if (!document.getElementById('leitorConteudo').value.trim()) {
                evento.preventDefault();
                document.getElementById('leitorStatus').textContent = 'Ainda não li nenhum QR — aponte a câmera ou cole o conteúdo.';
            }
        });
    </script>
    <?php
}
