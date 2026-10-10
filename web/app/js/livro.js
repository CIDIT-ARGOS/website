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
// O livro vem pronto da API (/api/livro.php): seções por tipo de chamada,
// dispensas médicas e o texto padronizado pra enviar ao Aluno de Dia ao CA.
let livroAtual = null;

async function carregarLivro() {
    const conteudo = document.getElementById('livroConteudo');
    const campoData = document.getElementById('livroData');
    if (!campoData.value) campoData.value = hojeIso();
    campoData.max = hojeIso();

    conteudo.innerHTML = '<p class="vazio">Carregando…</p>';
    document.getElementById('rodapeLivro').style.display = 'none';
    exibirErro('livroMensagem', '');
    document.getElementById('livroEsquadrao').textContent = rotuloEsquadrao(esquadraoDeServico());

    try {
        livroAtual = await api(`livro.php?data=${encodeURIComponent(campoData.value)}`);
    } catch (e) {
        exibirErro('livroMensagem', e.message);
        conteudo.innerHTML = '';
        return;
    }

    const secoes = livroAtual.secoes.map((s) => {
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

    const dispensas = livroAtual.dispensas.length === 0
        ? '<p class="nao-ha">Não há.</p>'
        : '<ul>' + livroAtual.dispensas.map((d) => `
            <li>
                <strong>${escapeHtml(d.identificacao)}</strong>
                <span>${dataBr(d.data_inicio)} a ${dataBr(d.data_termino)}${d.numero ? ' · nº ' + escapeHtml(d.numero) : ''}</span>
                <span>${escapeHtml(d.motivo)}${d.dispensado_de ? ' — dispensado de: ' + escapeHtml(d.dispensado_de) : ''}</span>
                ${d.medico_responsavel ? `<span>Oficial Médico: ${escapeHtml(d.medico_responsavel)}</span>` : ''}
            </li>`).join('') + '</ul>';
    secoes.push(`<section class="livro-secao"><h2>Ocorrências médicas — dispensa médica</h2>${dispensas}</section>`);

    conteudo.innerHTML = `<p class="livro-cabecalho">Resumo do dia <strong>${escapeHtml(livroAtual.data_extenso)}</strong></p>` + secoes.join('');
    document.getElementById('rodapeLivro').style.display = 'block';
}

document.getElementById('livroData').addEventListener('change', carregarLivro);

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
    exibirOk('livroMensagem', 'Texto do livro copiado. Cole na mensagem ou no e-mail.');
});

document.getElementById('btnEnviarLivro').addEventListener('click', async () => {
    if (!livroAtual) return;
    if (navigator.share) {
        try {
            await navigator.share({
                title: `Livro do Dia — ${rotuloEsquadrao(livroAtual.esquadrao)} — ${livroAtual.data_extenso}`,
                text: livroAtual.texto,
            });
        } catch (e) {
            // O usuário fechou a folha de compartilhamento — nada a fazer.
        }
        return;
    }
    await copiarTexto(livroAtual.texto);
    exibirOk('livroMensagem', 'Este aparelho não tem compartilhamento direto: o texto foi copiado, é só colar na mensagem.');
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
