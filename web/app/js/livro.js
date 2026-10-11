// Área Funcional — Livro do Dia e dispensas médicas. Carregado depois do
// app.js e usa o que ele define (api, sessaoAtual, mostrarView, exibirErro,
// escapeHtml, efetivoCache).

function hojeIso() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function dataBr(iso) {
    const [ano, mes, dia] = String(iso).split('-');
    return `${dia}/${mes}/${ano}`;
}

function exibirOk(elId, mensagem) {
    document.getElementById(elId).innerHTML = `<p class="mensagem-ok">${escapeHtml(mensagem)}</p>`;
}

// ---------- Livro do Dia ----------
// O livro vem pronto da API (/api/livro.php), conforme a função do aluno no
// serviço: o da esquadrilha, o do esquadrão (com os das esquadrilhas pra
// validar) ou o do Corpo de Alunos (com os dos esquadrões pra validar).
// Cada livro é enviado pro nível de cima, que valida ou devolve.
let livroAtual = null;

const EXPLICACAO_DO_LIVRO = {
    esquadrilha: 'Montado sozinho a partir das chamadas enviadas e das dispensas lançadas. Confira, escreva as alterações e envie ao Aluno de Dia ao Esquadrão, que valida.',
    esquadrao: 'Valide os livros das esquadrilhas, confira o do esquadrão, escreva as alterações e envie ao Aluno de Dia ao Corpo de Alunos, que valida.',
    ca: 'Valide os livros dos esquadrões, escreva as alterações e envie o Livro do Dia ao Corpo de Alunos.',
};

function dataHoraBr(dataHora) {
    if (!dataHora) return '';
    const [data, hora] = String(dataHora).split(' ');
    return `${dataBr(data)} ${hora ? hora.slice(0, 5) : ''}`.trim();
}

function seloDoLivro(registro) {
    return `<span class="status-pill livro-${registro.status}">${escapeHtml(registro.status_rotulo)}</span>`;
}

// Quem enviou, quem validou ou devolveu, e o motivo da devolução.
function tramitacaoDoLivro(registro) {
    const linhas = [];
    if (registro.status !== 'rascunho' && registro.enviado_por) {
        linhas.push(`Enviado por ${escapeHtml(registro.enviado_por)} em ${dataHoraBr(registro.enviado_em)}`);
    }
    if (registro.status === 'validado' && registro.validado_por) {
        linhas.push(`Validado por ${escapeHtml(registro.validado_por)} em ${dataHoraBr(registro.validado_em)}`);
    }
    if (registro.status === 'devolvido') {
        linhas.push(`Devolvido${registro.validado_por ? ' por ' + escapeHtml(registro.validado_por) : ''}: <strong>${escapeHtml(registro.devolucao_motivo || '')}</strong>`);
    }
    return linhas.map((l) => `<span>${l}</span>`).join('');
}

function desenharLivro() {
    const livro = livroAtual;
    const registro = livro.livro;

    document.getElementById('livroEsquadrao').textContent = registro.rotulo;
    document.getElementById('livroExplicacao').textContent = EXPLICACAO_DO_LIVRO[livro.funcao];

    // Situação do próprio livro.
    const aviso = {
        rascunho: 'Ainda não enviado.',
        enviado: registro.pode_reabrir ? 'Livro do Dia ao Corpo de Alunos enviado.' : `Aguardando a validação do ${livro.livro.destino}.`,
        validado: 'Validado — este livro está fechado.',
        devolvido: 'Devolvido pra correção. Ajuste e envie de novo.',
    }[registro.status];
    document.getElementById('livroSituacao').innerHTML = `
        <div class="livro-situacao ${registro.status}">
            <div class="linha-topo"><strong>Meu livro — ${escapeHtml(registro.rotulo)}</strong>${seloDoLivro(registro)}</div>
            <span>${escapeHtml(aviso)}</span>
            ${tramitacaoDoLivro(registro)}
            ${registro.pode_reabrir ? '<button type="button" class="secundario" id="btnReabrirLivro">Reabrir para corrigir</button>' : ''}
        </div>`;

    // Livros recebidos, pra validar ou devolver.
    const recebidos = livro.recebidos.map((r, indice) => `
        <section class="livro-secao livro-recebido ${r.status}" data-indice="${indice}">
            <div class="linha-topo"><h2>${escapeHtml(r.rotulo)}</h2>${seloDoLivro(r)}</div>
            <div class="tramitacao">${tramitacaoDoLivro(r) || '<span>O livro ainda não foi enviado.</span>'}</div>
            <details>
                <summary>Ver o livro${r.status === 'rascunho' || r.status === 'devolvido' ? ' (dados do sistema, ainda não enviado)' : ''}</summary>
                <pre>${escapeHtml(r.texto)}</pre>
            </details>
            ${r.pode_validar ? `
                <div class="acoes-duplas">
                    <button type="button" class="secundario btn-devolver">Devolver</button>
                    <button type="button" class="primario btn-validar">Validar</button>
                </div>
                <div class="devolucao" hidden>
                    <label>Motivo da devolução</label>
                    <input type="text" class="input-devolucao" maxlength="500" placeholder="O que precisa ser corrigido">
                    <button type="button" class="secundario btn-confirmar-devolucao">Confirmar devolução</button>
                </div>` : ''}
        </section>`);
    document.getElementById('livroRecebidos').innerHTML = recebidos.length
        ? `<p class="livro-cabecalho">${livro.funcao === 'ca' ? 'Livros dos esquadrões' : 'Livros das esquadrilhas'} — <strong>${livro.recebidos.filter((r) => r.status === 'validado').length} de ${livro.recebidos.length} validados</strong></p>` + recebidos.join('')
        : '';

    // O corpo do livro (o CA só reúne os dos esquadrões, então não tem seções próprias).
    const secoes = livro.secoes.map((s) => {
        let corpo;
        if (s.chamadas_enviadas === 0) {
            corpo = '<p class="sem-chamada">Chamada não enviada.</p>';
        } else if (s.ausencias.length === 0) {
            corpo = '<p class="nao-ha">Não há.</p>';
        } else {
            corpo = '<ul>' + s.ausencias.map((a) =>
                `<li><strong>${escapeHtml(a.identificacao)}</strong><span>${escapeHtml(a.situacao)}</span></li>`
            ).join('') + '</ul>';
        }
        return `<section class="livro-secao"><h2>${escapeHtml(s.rotulo)}</h2>${corpo}</section>`;
    });

    if (livro.funcao !== 'ca') {
        const dispensas = livro.dispensas.length === 0
            ? '<p class="nao-ha">Não há.</p>'
            : '<ul>' + livro.dispensas.map((d) => `
                <li>
                    <strong>${escapeHtml(d.identificacao)}</strong>
                    <span>${dataBr(d.data_inicio)} a ${dataBr(d.data_termino)}${d.numero ? ' · nº ' + escapeHtml(d.numero) : ''}</span>
                    <span>${escapeHtml(d.motivo)}${d.dispensado_de ? ' — dispensado de: ' + escapeHtml(d.dispensado_de) : ''}</span>
                    ${d.medico_responsavel ? `<span>Oficial Médico: ${escapeHtml(d.medico_responsavel)}</span>` : ''}
                </li>`).join('') + '</ul>';
        secoes.push(`<section class="livro-secao"><h2>Ocorrências médicas — dispensa médica</h2>${dispensas}</section>`);
    }

    document.getElementById('livroConteudo').innerHTML =
        `<p class="livro-cabecalho">${livro.funcao === 'ca' ? 'Livro do dia' : 'Resumo do dia'} <strong>${escapeHtml(livro.data_extenso)}</strong></p>` + secoes.join('');

    // Alterações e observações: só o dono escreve, e só antes de enviar.
    const campo = document.getElementById('livroObservacoes');
    campo.value = registro.observacoes || '';
    campo.disabled = !registro.pode_editar;
    document.getElementById('livroObservacoesBloco').hidden = false;
    document.getElementById('livroObservacoesAviso').textContent = registro.pode_editar
        ? 'Gravado ao sair do campo e ao enviar.'
        : 'O livro já foi enviado: não dá mais pra alterar.';

    document.getElementById('btnEnviarLivroTexto').textContent = registro.status === 'devolvido' ? 'Enviar de novo' : 'Enviar';
    document.getElementById('btnEnviarLivro').disabled = !registro.pode_enviar;
    document.getElementById('rodapeLivro').style.display = 'block';
}

async function carregarLivro() {
    const campoData = document.getElementById('livroData');
    if (!campoData.value) campoData.value = hojeIso();
    campoData.max = hojeIso();

    document.getElementById('livroConteudo').innerHTML = '<p class="vazio">Carregando…</p>';
    document.getElementById('livroSituacao').innerHTML = '';
    document.getElementById('livroRecebidos').innerHTML = '';
    document.getElementById('livroObservacoesBloco').hidden = true;
    document.getElementById('rodapeLivro').style.display = 'none';
    exibirErro('livroMensagem', '');
    document.getElementById('livroEsquadrao').textContent = '';

    try {
        livroAtual = await api(`livro.php?data=${encodeURIComponent(campoData.value)}`);
        desenharLivro();
    } catch (e) {
        exibirErro('livroMensagem', e.message);
        document.getElementById('livroConteudo').innerHTML = '';
    }
}

// Toda ação devolve o livro como ficou; é só redesenhar.
async function acaoNoLivro(metodo, corpo, mensagemOk) {
    exibirErro('livroMensagem', '');
    try {
        livroAtual = await api(`livro.php?data=${encodeURIComponent(livroAtual.data)}`, { method: metodo, body: corpo });
        desenharLivro();
        if (mensagemOk) exibirOk('livroMensagem', mensagemOk);
        return true;
    } catch (e) {
        exibirErro('livroMensagem', e.message);
        document.getElementById('livroMensagem').scrollIntoView({ block: 'center' });
        return false;
    }
}

async function salvarObservacoesDoLivro() {
    const campo = document.getElementById('livroObservacoes');
    if (!livroAtual || !livroAtual.livro.pode_editar) return true;
    if (campo.value.trim() === (livroAtual.livro.observacoes || '')) return true;
    return acaoNoLivro('PUT', { observacoes: campo.value }, null);
}

document.getElementById('livroData').addEventListener('change', carregarLivro);
document.getElementById('livroObservacoes').addEventListener('blur', salvarObservacoesDoLivro);

document.getElementById('btnEnviarLivro').addEventListener('click', async () => {
    if (!livroAtual) return;
    const pendentes = livroAtual.recebidos.filter((r) => r.status !== 'validado');
    if (pendentes.length > 0) {
        const nomes = pendentes.map((r) => `${r.rotulo} (${r.status_rotulo.toLowerCase()})`).join(', ');
        if (!confirm(`Ainda há livro sem validar: ${nomes}.\n\nEnviar assim mesmo? O seu livro vai registrar essa situação.`)) return;
    }
    if (!(await salvarObservacoesDoLivro())) return;
    const destino = livroAtual.livro.destino;
    await acaoNoLivro('POST', { acao: 'enviar' }, destino ? `Livro enviado ao ${destino}.` : 'Livro do Dia ao Corpo de Alunos enviado.');
});

document.getElementById('livroSituacao').addEventListener('click', (evento) => {
    if (evento.target.id === 'btnReabrirLivro') {
        acaoNoLivro('POST', { acao: 'reabrir' }, 'Livro reaberto. Corrija e envie de novo.');
    }
});

document.getElementById('livroRecebidos').addEventListener('click', async (evento) => {
    const cartao = evento.target.closest('.livro-recebido');
    if (!cartao || !livroAtual) return;
    const recebido = livroAtual.recebidos[Number(cartao.dataset.indice)];
    const alvo = { esquadrao: recebido.esquadrao, esquadrilha: recebido.esquadrilha };

    if (evento.target.classList.contains('btn-validar')) {
        await acaoNoLivro('POST', { acao: 'validar', ...alvo }, `Livro da ${recebido.rotulo} validado.`.replace('da Esquadrão', 'do Esquadrão'));
    } else if (evento.target.classList.contains('btn-devolver')) {
        cartao.querySelector('.devolucao').hidden = false;
        cartao.querySelector('.input-devolucao').focus();
    } else if (evento.target.classList.contains('btn-confirmar-devolucao')) {
        const motivo = cartao.querySelector('.input-devolucao').value.trim();
        if (!motivo) {
            cartao.querySelector('.input-devolucao').focus();
            return;
        }
        await acaoNoLivro('POST', { acao: 'devolver', motivo, ...alvo }, `Livro devolvido: ${recebido.rotulo}.`);
    }
});

async function copiarTexto(texto) {
    try {
        await navigator.clipboard.writeText(texto);
    } catch (e) {
        // Sem permissão de área de transferência (ou fora de HTTPS): cai no jeito antigo.
        const area = document.createElement('textarea');
        area.value = texto;
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        document.execCommand('copy');
        area.remove();
    }
}

document.getElementById('btnCopiarLivro').addEventListener('click', async () => {
    if (!livroAtual) return;
    await copiarTexto(livroAtual.texto);
    exibirOk('livroMensagem', 'Texto do livro copiado.');
});

// ---------- Dispensas médicas ----------
async function carregarDispensas() {
    const lista = document.getElementById('listaDispensas');
    lista.innerHTML = '<p class="vazio">Carregando…</p>';

    try {
        const dispensas = await api('dispensas.php');
        document.getElementById('dispensasTotal').textContent = `${dispensas.length} ${dispensas.length === 1 ? 'ativa' : 'ativas'}`;

        if (dispensas.length === 0) {
            lista.innerHTML = '<p class="vazio">Nenhuma dispensa médica ativa hoje.</p>';
            return;
        }

        lista.innerHTML = dispensas.map((d) => {
            const dispensadoDe = [d.tags_nomes, d.dispensado_de].filter(Boolean).join(', ');
            return `
                <div class="aluno-item">
                    <div class="nome">${escapeHtml(d.posto_exibicao || d.posto_graduacao || '')} ${escapeHtml(d.nome_guerra)}</div>
                    <div class="milhao">${escapeHtml(d.milhao)} · Esquadrilha ${escapeHtml(d.esquadrilha || '—')}</div>
                    <dl class="ficha">
                        <dt>Período</dt><dd>${dataBr(d.data_inicio)} a ${dataBr(d.data_termino)}${d.numero ? ' · nº ' + escapeHtml(d.numero) : ''}</dd>
                        <dt>Motivo</dt><dd>${escapeHtml(d.motivo)}</dd>
                        ${d.medico_responsavel ? `<dt>Oficial Médico</dt><dd>${escapeHtml(d.medico_responsavel)}</dd>` : ''}
                        ${dispensadoDe ? `<dt>Dispensado de</dt><dd>${escapeHtml(dispensadoDe)}</dd>` : ''}
                    </dl>
                </div>`;
        }).join('');
    } catch (e) {
        lista.innerHTML = `<p class="mensagem-erro">${escapeHtml(e.message)}</p>`;
    }
}

document.getElementById('btnNovaDispensa').addEventListener('click', () => {
    document.getElementById('dispensasMensagem').innerHTML = '';
    mostrarView('view-nova-dispensa');
});

async function prepararFormDispensa() {
    exibirErro('novaDispensaErro', '');
    document.getElementById('formNovaDispensa').reset();
    document.getElementById('dispInicio').value = hojeIso();
    document.getElementById('dispTermino').value = hojeIso();

    try {
        const [alunos, tipos] = await Promise.all([
            efetivoCache ? Promise.resolve(efetivoCache) : api('alunos.php'),
            api('dispensas.php?tipos=1'),
        ]);
        efetivoCache = alunos;

        document.getElementById('dispAluno').innerHTML = '<option value="">Escolha o aluno…</option>' + alunos.map((a) =>
            `<option value="${a.id}">${escapeHtml(a.nome_guerra)} — ${escapeHtml(a.milhao)} (esq. ${escapeHtml(a.esquadrilha || '—')})</option>`
        ).join('');

        document.getElementById('dispTipos').innerHTML = tipos.map((t) =>
            `<label class="tag-check"><input type="checkbox" value="${t.id}"><span>${escapeHtml(t.nome)}</span></label>`
        ).join('');
    } catch (e) {
        exibirErro('novaDispensaErro', e.message);
    }
}

document.getElementById('formNovaDispensa').addEventListener('submit', async (evento) => {
    evento.preventDefault();
    exibirErro('novaDispensaErro', '');
    const botao = evento.submitter;
    if (botao) botao.disabled = true;

    try {
        await api('dispensas.php', {
            method: 'POST',
            body: {
                aluno_id: Number(document.getElementById('dispAluno').value),
                data_inicio: document.getElementById('dispInicio').value,
                data_termino: document.getElementById('dispTermino').value,
                numero: document.getElementById('dispNumero').value.trim(),
                motivo: document.getElementById('dispMotivo').value.trim(),
                medico_responsavel: document.getElementById('dispMedico').value.trim(),
                dispensado_de: document.getElementById('dispOutros').value.trim(),
                dispensa_tipo_ids: [...document.querySelectorAll('#dispTipos input:checked')].map((c) => Number(c.value)),
            },
        });
        mostrarView('view-dispensas');
        exibirOk('dispensasMensagem', 'Dispensa médica lançada.');
    } catch (e) {
        exibirErro('novaDispensaErro', e.message);
    } finally {
        if (botao) botao.disabled = false;
    }
});
