// Área Funcional — lógica do app. Sem framework/build, igual ao resto do
// projeto: um arquivo, funções diretas, localStorage pra sessão do aluno.

// Vem do env.php, calculado a partir de onde o app está publicado — em
// produção o site vive num subdiretório (ex: /cidit/projetos/11/), então
// um '/api/' fixo apontaria pra raiz do domínio e quebraria o login.
const ARGOS_API_BASE = window.ARGOS_API_BASE || '../../api/';
const CHAVE_SESSAO = 'argos_sessao';

const TIPOS_RETIRADA = {
    '1_jornada': '1ª Jornada',
    '2_jornada': '2ª Jornada',
    'educacao_fisica': 'Educação Física',
    'pernoite': 'Pernoite',
};

// Sigla que vai no selo de cada cartão da lista de retiradas.
const SIGLAS_RETIRADA = {
    '1_jornada': '1ªJ',
    '2_jornada': '2ªJ',
    'educacao_fisica': 'EF',
    'pernoite': 'PN',
};

let sessaoAtual = carregarSessao();
let motivosCache = null;
let itensMarcarAtual = [];
let retiradaMarcarAtual = null;
let somenteLeituraMarcar = false;
let efetivoCache = null;

// ---------- Sessão ----------
function carregarSessao() {
    try {
        const bruto = localStorage.getItem(CHAVE_SESSAO);
        return bruto ? JSON.parse(bruto) : null;
    } catch (e) {
        return null;
    }
}

function salvarSessao(token, aluno) {
    sessaoAtual = { token, aluno };
    localStorage.setItem(CHAVE_SESSAO, JSON.stringify(sessaoAtual));
}

function limparSessao() {
    sessaoAtual = null;
    localStorage.removeItem(CHAVE_SESSAO);
}

// ---------- Cliente da API ----------
async function api(caminho, { method = 'GET', body = null, exigirSessao = true } = {}) {
    const headers = { 'Content-Type': 'application/json' };
    if (window.ARGOS_API_KEY) headers['X-API-Key'] = window.ARGOS_API_KEY;
    if (exigirSessao && sessaoAtual) headers['X-Session-Token'] = sessaoAtual.token;

    const resposta = await fetch(ARGOS_API_BASE + caminho, {
        method,
        headers,
        body: body ? JSON.stringify(body) : null,
    });

    const dados = await resposta.json().catch(() => ({}));

    if (resposta.status === 401 && exigirSessao) {
        limparSessao();
        mostrarView('view-login');
        throw new Error(dados.erro || 'Sessão expirada.');
    }

    if (!resposta.ok) {
        throw new Error(dados.erro || `Erro ${resposta.status}`);
    }

    return dados;
}

// ---------- Views ----------
function mostrarView(id) {
    document.querySelectorAll('.view').forEach((v) => v.classList.remove('ativa'));
    document.getElementById(id).classList.add('ativa');
    document.body.classList.toggle('tela-login', id === 'view-login');
    document.body.classList.toggle('tela-retiradas', id === 'view-retiradas');
    window.scrollTo(0, 0);

    const navInferior = document.getElementById('navInferior');
    const sessaoInfo = document.getElementById('sessaoInfo');

    if (id === 'view-login') {
        navInferior.classList.add('oculta');
        sessaoInfo.style.display = 'none';
        pararCamera();
    } else {
        navInferior.classList.remove('oculta');
        sessaoInfo.style.display = '';
        document.querySelectorAll('.nav-item[data-ir]').forEach((btn) => {
            btn.classList.toggle('ativo', btn.dataset.ir === id);
        });
    }

    if (id === 'view-retiradas') carregarRetiradas();
    if (id === 'view-efetivo') carregarEfetivo();
    // Estas três vivem em js/livro.js.
    if (id === 'view-livro') carregarLivro();
    if (id === 'view-dispensas') carregarDispensas();
    if (id === 'view-nova-dispensa') prepararFormDispensa();
}

function exibirErro(elId, mensagem) {
    const el = document.getElementById(elId);
    el.innerHTML = mensagem ? `<p class="mensagem-erro">${escapeHtml(mensagem)}</p>` : '';
}

function escapeHtml(texto) {
    const div = document.createElement('div');
    div.textContent = texto;
    return div.innerHTML;
}

// ---------- Login ----------
function pararCamera() {
    ArgosQrScanner.parar();
    document.getElementById('cameraFrame').classList.remove('ativo');
}

async function tentarLogin(qrcodeHash, botao) {
    exibirErro('loginErro', '');
    if (botao) { botao.disabled = true; }

    try {
        const resultado = await api('sessao.php', {
            method: 'POST',
            body: { qrcode_hash: qrcodeHash },
            exigirSessao: false,
        });
        salvarSessao(resultado.token, resultado.aluno);
        pararCamera();
        atualizarInfoSessao();
        mostrarView('view-retiradas');
    } catch (e) {
        exibirErro('loginErro', e.message);
    } finally {
        if (botao) { botao.disabled = false; }
    }
}

// "Esquadrão Prata" tanto se o cadastro guarda "Prata" quanto "Esquadrão Prata".
function rotuloEsquadrao(esquadrao) {
    return /^esquadr[ãa]o(\s|$)/i.test(esquadrao) ? esquadrao : `Esquadrão ${esquadrao}`;
}

function atualizarInfoSessao() {
    if (!sessaoAtual) return;
    const { aluno } = sessaoAtual;
    document.getElementById('sessaoInfo').innerHTML =
        `<strong>${escapeHtml(aluno.nome_guerra)}</strong>${escapeHtml(aluno.milhao)} · ${escapeHtml(rotuloEsquadrao(aluno.esquadrao))}`;
}

document.getElementById('btnEscanear').addEventListener('click', async () => {
    const frame = document.getElementById('cameraFrame');
    const video = document.getElementById('videoQr');
    frame.classList.add('ativo');
    exibirErro('loginErro', '');

    await ArgosQrScanner.iniciar(
        video,
        (valor) => tentarLogin(valor, null),
        (mensagem) => { exibirErro('loginErro', mensagem); frame.classList.remove('ativo'); }
    );
});

document.getElementById('formColar').addEventListener('submit', (evento) => {
    evento.preventDefault();
    const campo = document.getElementById('qrColado');
    const valor = campo.value.trim();
    if (!valor) return;
    tentarLogin(valor, evento.submitter);
});

document.getElementById('btnSair').addEventListener('click', () => {
    limparSessao();
    mostrarView('view-login');
});

// ---------- Navegação inferior ----------
document.querySelectorAll('.nav-item[data-ir]').forEach((btn) => {
    btn.addEventListener('click', () => mostrarView(btn.dataset.ir));
});

document.querySelectorAll('.btn-voltar[data-voltar]').forEach((btn) => {
    btn.addEventListener('click', () => mostrarView(btn.dataset.voltar));
});

// ---------- Retiradas ----------
async function carregarRetiradas() {
    const lista = document.getElementById('listaRetiradas');
    lista.innerHTML = '<p class="vazio">Carregando…</p>';
    exibirErro('retiradasErro', '');

    try {
        const retiradas = await api(`retiradas.php?esquadrao=${encodeURIComponent(sessaoAtual.aluno.esquadrao)}`);
        const dataDe = (r) => new Date(r.data_hora.replace(' ', 'T'));
        const hoje = new Date().toDateString();

        document.getElementById('retiradasData').textContent =
            new Date().toLocaleDateString('pt-BR', { weekday: 'short', day: '2-digit', month: 'short' }).replace(/\./g, '');
        document.getElementById('resumoPendentes').textContent = retiradas.filter((r) => r.status !== 'enviada').length;
        document.getElementById('resumoEnviadas').textContent =
            retiradas.filter((r) => r.status === 'enviada' && dataDe(r).toDateString() === hoje).length;

        if (retiradas.length === 0) {
            lista.innerHTML = '<p class="vazio">Nenhuma retirada ainda para o seu esquadrão.</p>';
            return;
        }

        lista.innerHTML = '';
        retiradas.slice(0, 20).forEach((r) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'card retirada-item';
            const data = dataDe(r);
            const hora = data.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
            const dia = data.toDateString() === hoje ? 'hoje' : data.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
            item.innerHTML = `
                <div class="selo">
                    <b>${escapeHtml(SIGLAS_RETIRADA[r.tipo] || '—')}</b>
                    <small>${hora}</small>
                </div>
                <div class="info">
                    <strong>${escapeHtml(TIPOS_RETIRADA[r.tipo] || r.tipo)}</strong>
                    <small>Esquadrilha ${escapeHtml(r.agrupamento_valor)} · ${dia}${r.protocolo ? ' · ' + escapeHtml(r.protocolo) : ''}</small>
                </div>
                <div class="lado">
                    <span class="status-pill ${r.status}">${r.status === 'enviada' ? 'Enviada' : 'Pendente'}</span>
                    <span class="i seta" style="--icon-url:url('../../images/icons/arrow-left.svg')" aria-hidden="true"></span>
                </div>
            `;
            item.addEventListener('click', () => abrirMarcar(r.id));
            lista.appendChild(item);
        });
    } catch (e) {
        exibirErro('retiradasErro', e.message);
        lista.innerHTML = '';
    }
}

document.getElementById('btnNovaRetirada').addEventListener('click', () => mostrarView('view-nova-retirada'));

document.getElementById('formNovaRetirada').addEventListener('submit', async (evento) => {
    evento.preventDefault();
    exibirErro('novaRetiradaErro', '');
    const tipo = document.getElementById('novaTipo').value;
    const esquadrilha = document.getElementById('novaEsquadrilha').value;

    try {
        const resultado = await api('retiradas.php', {
            method: 'POST',
            body: {
                tipo,
                agrupamento_tipo: 'esquadrilha',
                agrupamento_valor: esquadrilha,
                esquadrao: sessaoAtual.aluno.esquadrao,
            },
        });
        await abrirMarcar(resultado.id);
    } catch (e) {
        exibirErro('novaRetiradaErro', e.message);
    }
});

// ---------- Marcar ----------
async function carregarMotivos() {
    if (motivosCache) return motivosCache;
    motivosCache = await api('motivos.php');
    return motivosCache;
}

async function abrirMarcar(retiradaId) {
    mostrarView('view-marcar');
    const lista = document.getElementById('listaMarcar');
    lista.innerHTML = '<p class="vazio">Carregando…</p>';
    document.getElementById('marcarBusca').value = '';
    document.getElementById('rodapeEnviar').style.display = 'none';
    exibirErro('marcarMensagem', '');

    try {
        const [retirada, itens, motivos] = await Promise.all([
            api(`retiradas.php?id=${retiradaId}`),
            api(`retirada_itens.php?retirada_id=${retiradaId}`),
            carregarMotivos(),
        ]);

        itensMarcarAtual = itens;
        retiradaMarcarAtual = retiradaId;
        somenteLeituraMarcar = retirada.status === 'enviada';

        renderizarMarcar(itens, motivos, '', somenteLeituraMarcar);
        atualizarPlacar();

        document.getElementById('marcarTitulo').textContent =
            `${TIPOS_RETIRADA[retirada.tipo] || retirada.tipo} · Esq. ${retirada.agrupamento_valor}`;
        document.getElementById('rodapeEnviar').style.display = somenteLeituraMarcar ? 'none' : 'block';
    } catch (e) {
        exibirErro('marcarMensagem', e.message);
        lista.innerHTML = '';
    }
}

function renderizarMarcar(itens, motivos, filtro = '', somenteLeitura = somenteLeituraMarcar) {
    const lista = document.getElementById('listaMarcar');
    const termo = filtro.trim().toLowerCase();
    lista.innerHTML = '';

    const filtrados = itens.filter((it) =>
        !termo || it.nome_guerra.toLowerCase().includes(termo) || String(it.milhao).includes(termo)
    );

    if (filtrados.length === 0) {
        lista.innerHTML = '<p class="vazio">Nenhum aluno encontrado.</p>';
        return;
    }

    filtrados.forEach((item) => {
        const presente = Number(item.presente) === 1;
        const el = document.createElement('div');
        el.className = 'aluno-item' + (presente ? '' : ' em-falta');
        el.dataset.alunoId = item.aluno_id;

        const opcoesMotivo = motivos.map((m) =>
            `<option value="${m.id}" ${Number(item.motivo_falta_id) === Number(m.id) ? 'selected' : ''}>${escapeHtml(m.nome)}</option>`
        ).join('');

        el.innerHTML = `
            <div class="linha-topo">
                <div>
                    <div class="nome">${escapeHtml(item.posto_exibicao || item.posto_graduacao || '')} ${escapeHtml(item.nome_guerra)}</div>
                    <div class="milhao">${escapeHtml(item.milhao)}</div>
                </div>
                <div class="toggle-presenca">
                    <button type="button" class="btn-presente ${presente ? 'ativo presente' : ''}" ${somenteLeitura ? 'disabled' : ''}>Presente</button>
                    <button type="button" class="btn-falta ${!presente ? 'ativo falta' : ''}" ${somenteLeitura ? 'disabled' : ''}>Falta</button>
                </div>
            </div>
            <div class="bloco-motivo ${!presente ? 'ativo' : ''}">
                <select class="select-motivo" ${somenteLeitura ? 'disabled' : ''}>${opcoesMotivo}</select>
                <input type="text" class="input-observacao" placeholder="Observação (opcional)" value="${escapeHtml(item.observacao || '')}" ${somenteLeitura ? 'disabled' : ''}>
            </div>
        `;

        const btnPresente = el.querySelector('.btn-presente');
        const btnFalta = el.querySelector('.btn-falta');
        const blocoMotivo = el.querySelector('.bloco-motivo');
        const selectMotivo = el.querySelector('.select-motivo');
        const inputObs = el.querySelector('.input-observacao');

        function marcar(presenteNovo) {
            if (somenteLeitura) return;
            btnPresente.classList.toggle('ativo', presenteNovo);
            btnPresente.classList.toggle('presente', presenteNovo);
            btnFalta.classList.toggle('ativo', !presenteNovo);
            btnFalta.classList.toggle('falta', !presenteNovo);
            blocoMotivo.classList.toggle('ativo', !presenteNovo);
            el.classList.toggle('em-falta', !presenteNovo);
            item.presente = presenteNovo ? 1 : 0;
            atualizarPlacar();
            enviarMarcacao(item.aluno_id, presenteNovo, presenteNovo ? null : Number(selectMotivo.value), inputObs.value);
        }

        btnPresente.addEventListener('click', () => marcar(true));
        btnFalta.addEventListener('click', () => marcar(false));
        selectMotivo.addEventListener('change', () => {
            if (!btnPresente.classList.contains('ativo')) {
                enviarMarcacao(item.aluno_id, false, Number(selectMotivo.value), inputObs.value);
            }
        });
        inputObs.addEventListener('blur', () => {
            if (!btnPresente.classList.contains('ativo')) {
                enviarMarcacao(item.aluno_id, false, Number(selectMotivo.value), inputObs.value);
            }
        });

        lista.appendChild(el);
    });
}

// Placar acima do botão de enviar: quantos presentes e quantas faltas até agora.
function atualizarPlacar() {
    const faltas = itensMarcarAtual.filter((it) => Number(it.presente) !== 1).length;
    document.getElementById('placarChamada').innerHTML =
        `<span class="presentes">${itensMarcarAtual.length - faltas} presentes</span><span class="faltas">${faltas} ${faltas === 1 ? 'falta' : 'faltas'}</span>`;
}

let marcacaoEmAndamento = new Map();
async function enviarMarcacao(alunoId, presente, motivoFaltaId, observacao) {
    const chave = alunoId;
    if (marcacaoEmAndamento.get(chave)) return;
    marcacaoEmAndamento.set(chave, true);

    try {
        await api(`retirada_itens.php?retirada_id=${retiradaMarcarAtual}&aluno_id=${alunoId}`, {
            method: 'PUT',
            body: { presente: presente ? 1 : 0, motivo_falta_id: motivoFaltaId, observacao: observacao || null },
        });
    } catch (e) {
        exibirErro('marcarMensagem', e.message);
    } finally {
        marcacaoEmAndamento.set(chave, false);
    }
}

document.getElementById('marcarBusca').addEventListener('input', (evento) => {
    carregarMotivos().then((motivos) => renderizarMarcar(itensMarcarAtual, motivos, evento.target.value, somenteLeituraMarcar));
});

document.getElementById('btnEnviarChamada').addEventListener('click', async () => {
    exibirErro('marcarMensagem', '');
    if (!confirm('Enviar a chamada? Depois de enviada não é mais possível editar.')) return;

    try {
        const resultado = await api(`retiradas.php?id=${retiradaMarcarAtual}&acao=enviar`, { method: 'PUT' });
        document.getElementById('marcarMensagem').innerHTML =
            `<p class="mensagem-ok">Chamada enviada. Protocolo: ${escapeHtml(resultado.protocolo)}</p>`;
        document.getElementById('rodapeEnviar').style.display = 'none';
        setTimeout(() => mostrarView('view-retiradas'), 1400);
    } catch (e) {
        exibirErro('marcarMensagem', e.message);
    }
});

// ---------- Efetivo ----------
async function carregarEfetivo() {
    const lista = document.getElementById('listaEfetivo');
    lista.innerHTML = '<p class="vazio">Carregando…</p>';

    try {
        efetivoCache = await api('alunos.php');
        renderizarEfetivo(efetivoCache, '');
    } catch (e) {
        lista.innerHTML = `<p class="mensagem-erro">${escapeHtml(e.message)}</p>`;
    }
}

function renderizarEfetivo(alunos, filtro) {
    const lista = document.getElementById('listaEfetivo');
    const termo = filtro.trim().toLowerCase();
    document.getElementById('efetivoTotal').textContent = `${alunos.length} alunos`;
    const filtrados = alunos.filter((a) =>
        !termo || a.nome_guerra.toLowerCase().includes(termo) || String(a.milhao).includes(termo)
    );

    if (filtrados.length === 0) {
        lista.innerHTML = '<p class="vazio">Nenhum aluno encontrado.</p>';
        return;
    }

    lista.innerHTML = filtrados.map((a) => `
        <div class="aluno-item">
            <div class="linha-topo">
                <div>
                    <div class="nome">${escapeHtml(a.posto_graduacao || '')} ${escapeHtml(a.nome_guerra)}</div>
                    <div class="milhao">${escapeHtml(a.milhao)} · Esquadrilha ${escapeHtml(a.esquadrilha || '—')}</div>
                </div>
            </div>
        </div>
    `).join('');
}

document.getElementById('efetivoBusca').addEventListener('input', (evento) => {
    if (efetivoCache) renderizarEfetivo(efetivoCache, evento.target.value);
});

// ---------- Inicialização ----------
document.querySelectorAll('.versao-app').forEach((el) => {
    el.textContent = window.ARGOS_VERSAO ? `Argos ${window.ARGOS_VERSAO}` : '';
});

if (sessaoAtual && sessaoAtual.token) {
    atualizarInfoSessao();
    mostrarView('view-retiradas');
} else {
    mostrarView('view-login');
}

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js').catch(() => {});
    });
}
