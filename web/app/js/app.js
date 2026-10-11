// Área Funcional — lógica do app. Sem framework/build, igual ao resto do
// projeto: um arquivo, funções diretas, localStorage pra sessão do aluno.

// Vem do env.php, calculado a partir de onde o app está publicado — em
// produção o site vive num subdiretório (ex: /cidit/projetos/11/), então
// um '/api/' fixo apontaria pra raiz do domínio e quebraria o login.
const ARGOS_API_BASE = window.ARGOS_API_BASE || '../../api/';
const CHAVE_SESSAO = 'argos_sessao';

// Lançamentos do serviço, na ordem em que acontecem (a 1ª Jornada é a do dia
// seguinte). Educação Física só aparece pra retirada antiga.
const TIPOS_RETIRADA = {
    'almoco': 'Almoço',
    '2_jornada': '2ª Jornada',
    'pernoite': 'Pernoite',
    '1_jornada': '1ª Jornada',
    'educacao_fisica': 'Educação Física',
};

// Sigla que vai no selo de cada cartão da lista de retiradas.
const SIGLAS_RETIRADA = {
    'almoco': 'ALM',
    '2_jornada': '2ªJ',
    'pernoite': 'PN',
    '1_jornada': '1ªJ',
    'educacao_fisica': 'EF',
};

// Código do motivo "Serviço": é o que faz a chamada perguntar o posto.
const CODIGO_MOTIVO_SERVICO = 'SV';

let sessaoAtual = carregarSessao();
let motivosCache = null;
let postosServicoCache = null;
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

// `servico` é onde o aluno está de serviço nesta sessão ({ esquadrao,
// esquadrilha }) — fica null até ele confirmar na tela de posto de serviço.
function salvarSessao(token, aluno, servico = null) {
    sessaoAtual = { token, aluno, servico };
    localStorage.setItem(CHAVE_SESSAO, JSON.stringify(sessaoAtual));
}

// O esquadrão que vale pro que o app mostra e lança: o do serviço, não o de origem.
function esquadraoDeServico() {
    return (sessaoAtual.servico || sessaoAtual.aluno).esquadrao;
}

function esquadrilhaDeServico() {
    return (sessaoAtual.servico || sessaoAtual.aluno).esquadrilha;
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
    } else if (id === 'view-servico') {
        // Ainda não dá pra navegar: primeiro ele diz onde está de serviço.
        navInferior.classList.add('oculta');
        sessaoInfo.style.display = '';
    } else {
        navInferior.classList.remove('oculta');
        sessaoInfo.style.display = '';
        document.querySelectorAll('.nav-item[data-ir]').forEach((btn) => {
            btn.classList.toggle('ativo', btn.dataset.ir === id);
        });
    }

    if (id === 'view-servico') prepararPostoDeServico();
    if (id === 'view-nova-retirada') document.getElementById('novaEsquadrilha').value = esquadrilhaDeServico();
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
// O QR que a câmera leu e ainda espera o "Entrar".
let qrLido = null;

function pararCamera() {
    ArgosQrScanner.parar();
    qrLido = null;
    document.getElementById('cameraFrame').classList.remove('ativo', 'lido');
    document.getElementById('cameraAcoes').hidden = true;
    rotuloDoBotaoDaCamera(false);
}

// O mesmo botão abre e fecha a câmera.
function rotuloDoBotaoDaCamera(aberta) {
    const botao = document.getElementById('btnEscanear');
    botao.lastChild.textContent = aberta ? ' Fechar câmera' : ' Escanear QR Code';
    botao.classList.toggle('primario', !aberta);
    botao.classList.toggle('secundario', aberta);
}

async function tentarLogin(qrcodeHash, botao) {
    exibirErro('loginErro', '');
    if (botao) { botao.disabled = true; }

    try {
        const resultado = await api('sessao.php', {
            method: 'POST',
            // O conteúdo do QR vai como foi lido; quem calcula a impressão digital é o servidor.
            body: { qrcode: qrcodeHash },
            exigirSessao: false,
        });
        salvarSessao(resultado.token, resultado.aluno);
        pararCamera();
        atualizarInfoSessao();
        // Antes de qualquer coisa: em qual esquadrão/esquadrilha ele está de serviço.
        mostrarView('view-servico');
    } catch (e) {
        exibirErro('loginErro', e.message);
    } finally {
        if (botao) { botao.disabled = false; }
    }
}

// ---------- Posto de serviço ----------
// Quem tira as faltas de um esquadrão quase sempre é de outro, então ao entrar
// o aluno escolhe onde está de serviço. Vale pra sessão inteira e dá pra
// trocar tocando no cabeçalho.
let opcoesDeServico = [];

function preencherEsquadrilhasDeServico(selecionada) {
    const esquadrao = document.getElementById('servicoEsquadrao').value;
    const opcao = opcoesDeServico.find((o) => o.esquadrao === esquadrao);
    const campo = document.getElementById('servicoEsquadrilha');
    campo.innerHTML = (opcao ? opcao.esquadrilhas : []).map((e) =>
        `<option value="${escapeHtml(e)}">Esquadrilha ${escapeHtml(e)}</option>`
    ).join('');
    if (selecionada && opcao && opcao.esquadrilhas.includes(selecionada)) campo.value = selecionada;
}

async function prepararPostoDeServico() {
    exibirErro('servicoErro', '');
    try {
        const dados = await api('servico.php');
        opcoesDeServico = dados.opcoes;
        const atual = sessaoAtual.servico || dados.servico;

        const campoEsquadrao = document.getElementById('servicoEsquadrao');
        campoEsquadrao.innerHTML = opcoesDeServico.map((o) =>
            `<option value="${escapeHtml(o.esquadrao)}">${escapeHtml(rotuloEsquadrao(o.esquadrao))}</option>`
        ).join('');
        campoEsquadrao.value = atual.esquadrao;
        preencherEsquadrilhasDeServico(atual.esquadrilha);

        document.getElementById('servicoOrigem').textContent =
            `Você é do ${rotuloEsquadrao(dados.origem.esquadrao)}, esquadrilha ${dados.origem.esquadrilha}.`;
    } catch (e) {
        exibirErro('servicoErro', e.message);
    }
}

document.getElementById('servicoEsquadrao').addEventListener('change', () => preencherEsquadrilhasDeServico(null));

document.getElementById('formServico').addEventListener('submit', async (evento) => {
    evento.preventDefault();
    exibirErro('servicoErro', '');
    try {
        const resultado = await api('servico.php', {
            method: 'PUT',
            body: {
                esquadrao: document.getElementById('servicoEsquadrao').value,
                esquadrilha: document.getElementById('servicoEsquadrilha').value,
            },
        });
        salvarSessao(sessaoAtual.token, sessaoAtual.aluno, resultado.servico);
        efetivoCache = null; // o efetivo é o do esquadrão de serviço
        atualizarInfoSessao();
        mostrarView('view-retiradas');
    } catch (e) {
        exibirErro('servicoErro', e.message);
    }
});

document.getElementById('sessaoInfo').addEventListener('click', () => {
    if (sessaoAtual) mostrarView('view-servico');
});

// "Esquadrão Prata" tanto se o cadastro guarda "Prata" quanto "Esquadrão Prata".
function rotuloEsquadrao(esquadrao) {
    return /^esquadr[ãa]o(\s|$)/i.test(esquadrao) ? esquadrao : `Esquadrão ${esquadrao}`;
}

function atualizarInfoSessao() {
    if (!sessaoAtual) return;
    const { aluno, servico } = sessaoAtual;
    const posto = servico
        ? `De serviço: ${escapeHtml(rotuloEsquadrao(servico.esquadrao))} · ${escapeHtml(servico.esquadrilha)} <u>trocar</u>`
        : escapeHtml(aluno.milhao);
    document.getElementById('sessaoInfo').innerHTML = `<strong>${escapeHtml(aluno.nome_guerra)}</strong>${posto}`;
}

// A câmera fica aberta até ler. Quando lê, a imagem congela, aparece
// "QR identificado" e o app espera o "Entrar" — nada é enviado sozinho.
document.getElementById('btnEscanear').addEventListener('click', async () => {
    const frame = document.getElementById('cameraFrame');
    const estavaAberta = ArgosQrScanner.ligado();
    pararCamera();
    exibirErro('loginErro', '');
    if (estavaAberta) return; // era um "Fechar câmera"

    frame.classList.add('ativo');
    rotuloDoBotaoDaCamera(true);

    await ArgosQrScanner.iniciar(
        document.getElementById('videoQr'),
        (valor) => {
            qrLido = valor;
            frame.classList.add('lido');
            document.getElementById('cameraResumo').textContent =
                `Li um QR de ${valor.length} caracteres. Toque em Entrar para confirmar.`;
            document.getElementById('cameraAcoes').hidden = false;
        },
        (mensagem) => { pararCamera(); exibirErro('loginErro', mensagem); }
    );
});

document.getElementById('btnLerDeNovo').addEventListener('click', () => {
    exibirErro('loginErro', '');
    qrLido = null;
    document.getElementById('cameraFrame').classList.remove('lido');
    document.getElementById('cameraAcoes').hidden = true;
    // Se a câmera já tiver sido desligada, abre de novo.
    if (!ArgosQrScanner.retomar()) {
        pararCamera();
        document.getElementById('btnEscanear').click();
    }
});

document.getElementById('btnEntrarComQr').addEventListener('click', (evento) => {
    if (qrLido) tentarLogin(qrLido, evento.currentTarget);
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
        // A API já limita ao esquadrão em que ele está de serviço.
        const retiradas = await api('retiradas.php');
        const dataDe = (r) => new Date(r.data_hora.replace(' ', 'T'));
        const hoje = new Date().toDateString();

        document.getElementById('retiradasData').textContent =
            new Date().toLocaleDateString('pt-BR', { weekday: 'short', day: '2-digit', month: 'short' }).replace(/\./g, '');
        document.getElementById('resumoPendentes').textContent = retiradas.filter((r) => r.status !== 'enviada').length;
        document.getElementById('resumoEnviadas').textContent =
            retiradas.filter((r) => r.status === 'enviada' && dataDe(r).toDateString() === hoje).length;

        if (retiradas.length === 0) {
            lista.innerHTML = '<p class="vazio">Nenhuma retirada ainda para este esquadrão.</p>';
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
                esquadrao: esquadraoDeServico(),
            },
        });
        await abrirMarcar(resultado.id);
    } catch (e) {
        exibirErro('novaRetiradaErro', e.message);
    }
});

// ---------- Painel de opções ----------
// Sobe do rodapé com as opções em botões (motivo da falta, posto de serviço).
// Devolve o `valor` da opção tocada, ou undefined se fecharam sem escolher.
//   opcoes: [{ valor, nome, sigla?, destaque?, discreta? }]
function semAcento(texto) {
    return String(texto).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
}

let fecharFolhaAberta = null;
function abrirFolhaDeOpcoes({ titulo, subtitulo = '', opcoes, selecionado = null, comBusca = false }) {
    if (fecharFolhaAberta) fecharFolhaAberta();

    const fundo = document.getElementById('folhaOpcoes');
    const grade = document.getElementById('folhaOpcoesGrade');
    const busca = document.getElementById('folhaOpcoesBusca');
    document.getElementById('folhaOpcoesTitulo').textContent = titulo;
    document.getElementById('folhaOpcoesSubtitulo').textContent = subtitulo;
    busca.value = '';
    busca.hidden = !comBusca;

    function desenhar() {
        const termo = semAcento(busca.value);
        const botoes = opcoes.map((o, indice) => {
            if (termo && !semAcento(`${o.sigla || ''} ${o.nome}`).includes(termo)) return '';
            const classes = ['opcao'];
            if (o.destaque) classes.push('destaque');
            if (o.discreta) classes.push('discreta');
            if (selecionado !== null && o.valor === selecionado) classes.push('escolhida');
            return `<button type="button" class="${classes.join(' ')}" data-indice="${indice}">
                ${o.sigla ? `<b class="sigla">${escapeHtml(o.sigla)}</b>` : ''}<span>${escapeHtml(o.nome)}</span>
            </button>`;
        }).join('');
        grade.innerHTML = botoes || '<p class="vazio">Nenhuma opção com esse nome.</p>';
    }

    return new Promise((resolve) => {
        function fechar(valor) {
            fecharFolhaAberta = null;
            document.removeEventListener('keydown', aoTeclar);
            fundo.hidden = true;
            document.body.classList.remove('folha-aberta');
            resolve(valor);
        }
        function aoTeclar(evento) {
            if (evento.key === 'Escape') fechar(undefined);
        }
        fecharFolhaAberta = () => fechar(undefined);

        busca.oninput = desenhar;
        grade.onclick = (evento) => {
            const botao = evento.target.closest('.opcao');
            if (botao) fechar(opcoes[Number(botao.dataset.indice)].valor);
        };
        fundo.onclick = (evento) => { if (evento.target === fundo) fechar(undefined); };
        document.getElementById('folhaOpcoesFechar').onclick = () => fechar(undefined);
        document.addEventListener('keydown', aoTeclar);

        desenhar();
        fundo.hidden = false;
        document.body.classList.add('folha-aberta');
        grade.scrollTop = 0;
        const escolhida = grade.querySelector('.escolhida');
        if (escolhida) escolhida.scrollIntoView({ block: 'center' });
    });
}

// Motivos como opções do painel: "Falta" (a falta de verdade) vem primeiro e em destaque.
function opcoesDeMotivo(motivos) {
    return motivos
        .map((m) => ({ valor: Number(m.id), sigla: m.codigo || '', nome: m.nome, destaque: m.classificacao === 'falta' }))
        .sort((a, b) => Number(b.destaque) - Number(a.destaque));
}

// ---------- Marcar ----------
// Motivos de falta e postos de serviço, buscados juntos uma vez por sessão.
async function carregarMotivos() {
    if (motivosCache) return motivosCache;
    [motivosCache, postosServicoCache] = await Promise.all([api('motivos.php'), api('postos_servico.php')]);
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

        const postos = postosServicoCache || [];
        const identificacao = `${item.posto_exibicao || item.posto_graduacao || ''} ${item.nome_guerra}`.trim();

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
                <button type="button" class="motivo-escolhido" ${somenteLeitura ? 'disabled' : ''}></button>
                <input type="text" class="input-observacao" placeholder="Observação (opcional)" value="${escapeHtml(item.observacao || '')}" ${somenteLeitura ? 'disabled' : ''}>
            </div>
        `;

        const btnPresente = el.querySelector('.btn-presente');
        const btnFalta = el.querySelector('.btn-falta');
        const blocoMotivo = el.querySelector('.bloco-motivo');
        const btnMotivo = el.querySelector('.motivo-escolhido');
        const inputObs = el.querySelector('.input-observacao');

        // O botão do cartão mostra o motivo escolhido; tocar nele reabre o painel.
        function pintar() {
            const emFalta = Number(item.presente) !== 1;
            btnPresente.classList.toggle('ativo', !emFalta);
            btnPresente.classList.toggle('presente', !emFalta);
            btnFalta.classList.toggle('ativo', emFalta);
            btnFalta.classList.toggle('falta', emFalta);
            blocoMotivo.classList.toggle('ativo', emFalta);
            el.classList.toggle('em-falta', emFalta);

            const motivo = motivos.find((m) => Number(m.id) === Number(item.motivo_falta_id));
            const posto = postos.find((p) => Number(p.id) === Number(item.servico_id));
            btnMotivo.innerHTML = motivo
                ? `${motivo.codigo ? `<b class="sigla">${escapeHtml(motivo.codigo)}</b>` : ''}
                   <span class="texto"><strong>${escapeHtml(motivo.nome)}</strong>${posto ? `<small>Posto: ${escapeHtml(posto.nome)}</small>` : ''}</span>
                   ${somenteLeitura ? '' : '<span class="trocar">Trocar</span>'}`
                : `<span class="texto"><strong>Sem motivo informado</strong></span>${somenteLeitura ? '' : '<span class="trocar">Escolher</span>'}`;
        }

        // Abre o painel de motivos; se o motivo for "Serviço" (e houver postos
        // cadastrados), pergunta o posto em seguida. Só grava depois da escolha:
        // fechar o painel deixa o aluno como estava.
        async function escolherMotivo() {
            if (somenteLeitura) return;
            const motivoId = await abrirFolhaDeOpcoes({
                titulo: 'Motivo da falta',
                subtitulo: identificacao,
                opcoes: opcoesDeMotivo(motivos),
                selecionado: Number(item.presente) !== 1 ? Number(item.motivo_falta_id) : null,
                comBusca: true,
            });
            if (motivoId === undefined) return;

            let postoId = null;
            const motivo = motivos.find((m) => Number(m.id) === motivoId);
            if (motivo.codigo === CODIGO_MOTIVO_SERVICO && postos.length > 0) {
                postoId = await abrirFolhaDeOpcoes({
                    titulo: 'Qual posto de serviço?',
                    subtitulo: identificacao,
                    opcoes: [
                        ...postos.map((p) => ({ valor: Number(p.id), nome: p.nome })),
                        { valor: null, nome: 'Não informar o posto', discreta: true },
                    ],
                    selecionado: item.servico_id ? Number(item.servico_id) : null,
                    comBusca: postos.length > 8,
                });
                if (postoId === undefined) return;
            }

            item.presente = 0;
            item.motivo_falta_id = motivoId;
            item.servico_id = postoId;
            pintar();
            atualizarPlacar();
            enviarMarcacao(item.aluno_id, false, motivoId, inputObs.value, postoId);
        }

        function marcarPresente() {
            if (somenteLeitura || Number(item.presente) === 1) return;
            item.presente = 1;
            item.motivo_falta_id = null;
            item.servico_id = null;
            pintar();
            atualizarPlacar();
            enviarMarcacao(item.aluno_id, true, null, inputObs.value, null);
        }

        pintar();
        btnPresente.addEventListener('click', marcarPresente);
        btnFalta.addEventListener('click', escolherMotivo);
        btnMotivo.addEventListener('click', escolherMotivo);
        inputObs.addEventListener('blur', () => {
            if (Number(item.presente) !== 1) {
                enviarMarcacao(item.aluno_id, false, item.motivo_falta_id ? Number(item.motivo_falta_id) : null, inputObs.value, item.servico_id ? Number(item.servico_id) : null);
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

// Uma gravação por aluno de cada vez. Se ele mexer de novo enquanto a anterior
// ainda está indo (marca falta, troca o motivo, escolhe o posto), a última
// versão fica na fila e vai em seguida — antes ela era descartada em silêncio.
let marcacaoEmAndamento = new Map();
let marcacaoNaFila = new Map();
async function enviarMarcacao(alunoId, presente, motivoFaltaId, observacao, servicoId = null) {
    const chave = alunoId;
    if (marcacaoEmAndamento.get(chave)) {
        marcacaoNaFila.set(chave, [alunoId, presente, motivoFaltaId, observacao, servicoId]);
        return;
    }
    marcacaoEmAndamento.set(chave, true);

    try {
        await api(`retirada_itens.php?retirada_id=${retiradaMarcarAtual}&aluno_id=${alunoId}`, {
            method: 'PUT',
            body: { presente: presente ? 1 : 0, motivo_falta_id: motivoFaltaId, servico_id: servicoId, observacao: observacao || null },
        });
    } catch (e) {
        exibirErro('marcarMensagem', e.message);
    } finally {
        marcacaoEmAndamento.set(chave, false);
        const pendente = marcacaoNaFila.get(chave);
        if (pendente) {
            marcacaoNaFila.delete(chave);
            enviarMarcacao(...pendente);
        }
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
    // Sessão guardada sem posto de serviço (ou de antes dessa escolha existir): pergunta.
    mostrarView(sessaoAtual.servico ? 'view-retiradas' : 'view-servico');
} else {
    mostrarView('view-login');
}

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js').catch(() => {});
    });
}
