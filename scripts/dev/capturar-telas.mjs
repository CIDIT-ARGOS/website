// Tira as capturas de tela do Argos usadas na Wiki do projeto e na página
// inicial (web/images/hero-app.png e tela-login.png), a partir do ambiente de
// demonstração — só dados fictícios do seed de desenvolvimento.
//
//   node scripts/dev/capturar-telas.mjs [endereço] [pasta-de-saída]
//   (padrão: http://localhost:8090  build/telas)
//
// Antes: suba o demo (docs/dev/COMO-TESTAR.md) e rode o
// scripts/dev/simular-atividade.php pra ter chamadas do dia. As senhas do
// Painel e do Ikarus37 vêm do ambiente — as do demo estão em
// docs/dev/SEED-QRCODES.md:
//
//   ARGOS_PAINEL_USUARIO (padrão dev.ca)   ARGOS_PAINEL_SENHA
//   ARGOS_IKARUS_USUARIO (padrão admin)    ARGOS_IKARUS_SENHA
//
// Sem dependência nenhuma: abre o Edge/Chrome do próprio computador sem
// janela e conversa com ele pelo protocolo de depuração (DevTools). O
// navegador pode ser trocado com ARGOS_NAVEGADOR=<caminho do executável>.
//
// Também prepara o que as telas precisam mostrar (postos de serviço com
// alguém de serviço, uma dispensa médica, livros em tramitação) — por isso,
// rode só contra o demo local, nunca contra produção.

import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { crc32, deflateSync, inflateSync } from 'node:zlib';

const BASE = (process.argv[2] || 'http://localhost:8090').replace(/\/$/, '');
const SAIDA = process.argv[3] || 'build/telas';
if (!/^https?:\/\/(localhost|127\.0\.0\.1|\[::1\]|[^/]*\.localhost|[^/]*\.test)(:\d+)?$/.test(BASE)) {
    console.error('Só aceita endereço local (o script cria dados de exemplo): ex. http://localhost:8090');
    process.exit(2);
}
const PAINEL = { usuario: process.env.ARGOS_PAINEL_USUARIO || 'dev.ca', senha: process.env.ARGOS_PAINEL_SENHA };
const IKARUS = { usuario: process.env.ARGOS_IKARUS_USUARIO || 'admin', senha: process.env.ARGOS_IKARUS_SENHA };
if (!PAINEL.senha || !IKARUS.senha) {
    console.error('Defina ARGOS_PAINEL_SENHA e ARGOS_IKARUS_SENHA (as do demo estão em docs/dev/SEED-QRCODES.md).');
    process.exit(2);
}
// QR de um aluno fictício do seed (o 1º da lista gerada em docs/dev/).
const QR_ALUNO = readFileSync('docs/dev/qrcodes-seed.html', 'utf8').match(/<code>([0-9a-f]{64})<\/code>/)[1];

const esperar = (ms) => new Promise((r) => setTimeout(r, ms));

// ---------------------------------------------------------------- navegador
function acharNavegador() {
    const candidatos = [
        process.env.ARGOS_NAVEGADOR,
        'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
        'C:/Program Files/Google/Chrome/Application/chrome.exe',
        '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    ].filter(Boolean);
    const achado = candidatos.find((c) => existsSync(c));
    if (!achado) throw new Error('Nenhum Edge/Chrome encontrado. Informe ARGOS_NAVEGADOR.');
    return achado;
}

async function abrirNavegador() {
    const porta = 9300 + Math.floor(Math.random() * 500);
    const perfil = mkdtempSync(join(tmpdir(), 'argos-telas-'));
    const processo = spawn(acharNavegador(), [
        '--headless=new', `--remote-debugging-port=${porta}`, `--user-data-dir=${perfil}`,
        '--no-first-run', '--no-default-browser-check', '--hide-scrollbars', '--force-color-profile=srgb',
        '--lang=pt-BR', '--disable-features=Translate,MediaRouter', 'about:blank',
    ], { stdio: 'ignore' });

    for (let i = 0; i < 50; i++) {
        try {
            await fetch(`http://127.0.0.1:${porta}/json/version`);
            break;
        } catch {
            await esperar(200);
        }
    }
    return {
        porta,
        async novaAba() {
            const alvo = await (await fetch(`http://127.0.0.1:${porta}/json/new?about:blank`, { method: 'PUT' })).json();
            return Aba.conectar(alvo.webSocketDebuggerUrl);
        },
        fechar() {
            processo.kill();
            setTimeout(() => { try { rmSync(perfil, { recursive: true, force: true }); } catch { /* o navegador ainda segura */ } }, 1500);
        },
    };
}

class Aba {
    static async conectar(url) {
        const aba = new Aba();
        aba.ws = new WebSocket(url);
        aba.id = 0;
        aba.pendentes = new Map();
        aba.ouvintes = [];
        aba.ws.onmessage = (m) => {
            const msg = JSON.parse(m.data);
            if (msg.id && aba.pendentes.has(msg.id)) {
                const { resolve, reject } = aba.pendentes.get(msg.id);
                aba.pendentes.delete(msg.id);
                msg.error ? reject(new Error(msg.error.message)) : resolve(msg.result);
            } else if (msg.method) {
                aba.ouvintes = aba.ouvintes.filter((o) => !(o.metodo === msg.method && (o.resolve(msg.params), true)));
            }
        };
        await new Promise((r) => { aba.ws.onopen = r; });
        await aba.cmd('Page.enable');
        await aba.cmd('Runtime.enable');
        return aba;
    }

    cmd(method, params = {}) {
        const id = ++this.id;
        this.ws.send(JSON.stringify({ id, method, params }));
        return new Promise((resolve, reject) => this.pendentes.set(id, { resolve, reject }));
    }

    evento(metodo, prazo = 20000) {
        return new Promise((resolve, reject) => {
            this.ouvintes.push({ metodo, resolve });
            setTimeout(() => reject(new Error(`esperei ${metodo} e não veio`)), prazo);
        });
    }

    async tela(largura, altura, celular) {
        await this.cmd('Emulation.setDeviceMetricsOverride', { width: largura, height: altura, deviceScaleFactor: 2, mobile: celular });
        await this.cmd('Emulation.setTouchEmulationEnabled', { enabled: celular });
    }

    async ir(caminho) {
        const carregou = this.evento('Page.loadEventFired');
        await this.cmd('Page.navigate', { url: BASE + caminho });
        await carregou;
        await this.js('document.fonts.ready.then(() => true)');
        await esperar(500);
    }

    // Roda JS na página e devolve o resultado (promessas são esperadas).
    async js(codigo) {
        const r = await this.cmd('Runtime.evaluate', { expression: codigo, awaitPromise: true, returnByValue: true });
        if (r.exceptionDetails) throw new Error(`JS na página: ${r.exceptionDetails.exception?.description || r.exceptionDetails.text}`);
        return r.result.value;
    }

    // Ação que troca de página (enviar um formulário): espera a nova carregar.
    async navegandoCom(codigo) {
        const carregou = this.evento('Page.loadEventFired');
        await this.js(codigo);
        await carregou;
        await this.js('document.fonts.ready.then(() => true)');
        await esperar(400);
    }

    async foto(nome, { cantos = 0 } = {}) {
        await esperar(350);
        const { data } = await this.cmd('Page.captureScreenshot', { format: 'png' });
        let png = Buffer.from(data, 'base64');
        if (cantos) png = arredondarCantos(png, cantos);
        writeFileSync(join(SAIDA, `${nome}.png`), png);
        console.log(`  ${nome}.png`);
    }
}

// ---------------------------------------------------------------- PNG
// Cantos arredondados e transparentes, como num celular — direto nos bytes do
// PNG, sem biblioteca de imagem (só o zlib do Node).
function arredondarCantos(png, raio) {
    let pos = 8;
    let largura, altura, tipoCor;
    const idat = [];
    while (pos < png.length) {
        const tamanho = png.readUInt32BE(pos);
        const tipo = png.toString('ascii', pos + 4, pos + 8);
        const dados = png.subarray(pos + 8, pos + 8 + tamanho);
        if (tipo === 'IHDR') {
            largura = dados.readUInt32BE(0);
            altura = dados.readUInt32BE(4);
            tipoCor = dados[9];
            if (dados[8] !== 8 || dados[12] !== 0 || (tipoCor !== 2 && tipoCor !== 6)) return png; // formato inesperado: deixa como está
        } else if (tipo === 'IDAT') {
            idat.push(dados);
        }
        pos += 12 + tamanho;
    }

    const bpp = tipoCor === 6 ? 4 : 3;
    const bruto = inflateSync(Buffer.concat(idat));
    const linha = largura * bpp;
    const rgba = Buffer.alloc(largura * altura * 4);
    let anterior = Buffer.alloc(linha);
    for (let y = 0; y < altura; y++) {
        const filtro = bruto[y * (linha + 1)];
        const atual = Buffer.from(bruto.subarray(y * (linha + 1) + 1, (y + 1) * (linha + 1)));
        for (let i = 0; i < linha; i++) {
            const a = i >= bpp ? atual[i - bpp] : 0;
            const b = anterior[i];
            const c = i >= bpp ? anterior[i - bpp] : 0;
            let somar = 0;
            if (filtro === 1) somar = a;
            else if (filtro === 2) somar = b;
            else if (filtro === 3) somar = (a + b) >> 1;
            else if (filtro === 4) {
                const p = a + b - c;
                const pa = Math.abs(p - a), pb = Math.abs(p - b), pc = Math.abs(p - c);
                somar = pa <= pb && pa <= pc ? a : pb <= pc ? b : c;
            }
            atual[i] = (atual[i] + somar) & 0xff;
        }
        for (let x = 0; x < largura; x++) {
            const o = (y * largura + x) * 4;
            atual.copy(rgba, o, x * bpp, x * bpp + 3);
            rgba[o + 3] = bpp === 4 ? atual[x * bpp + 3] : 255;
        }
        anterior = atual;
    }

    for (let y = 0; y < altura; y++) {
        for (let x = 0; x < largura; x++) {
            const dx = x < raio ? raio - x - 0.5 : x >= largura - raio ? x - (largura - raio) + 0.5 : 0;
            const dy = y < raio ? raio - y - 0.5 : y >= altura - raio ? y - (altura - raio) + 0.5 : 0;
            if (!dx || !dy) continue;
            const cobertura = Math.max(0, Math.min(1, raio - Math.hypot(dx, dy) + 0.5));
            const o = (y * largura + x) * 4 + 3;
            rgba[o] = Math.round(rgba[o] * cobertura);
        }
    }

    const semFiltro = Buffer.alloc((largura * 4 + 1) * altura);
    for (let y = 0; y < altura; y++) rgba.copy(semFiltro, y * (largura * 4 + 1) + 1, y * largura * 4, (y + 1) * largura * 4);
    const pedaco = (tipo, dados) => {
        const cabeca = Buffer.alloc(8);
        cabeca.writeUInt32BE(dados.length, 0);
        cabeca.write(tipo, 4, 'ascii');
        const crc = Buffer.alloc(4);
        crc.writeUInt32BE(crc32(Buffer.concat([cabeca.subarray(4), dados])) >>> 0, 0);
        return Buffer.concat([cabeca, dados, crc]);
    };
    const ihdr = Buffer.alloc(13);
    ihdr.writeUInt32BE(largura, 0);
    ihdr.writeUInt32BE(altura, 4);
    ihdr.set([8, 6, 0, 0, 0], 8);
    return Buffer.concat([png.subarray(0, 8), pedaco('IHDR', ihdr), pedaco('IDAT', deflateSync(semFiltro, { level: 9 })), pedaco('IEND', Buffer.alloc(0))]);
}

// ---------------------------------------------------------------- app
// Barra de status de celular por cima do app (hora, entalhe, bateria) e o
// indicador de início embaixo — o app real, só emoldurado.
const MOLDURA_CELULAR = `(() => {
    if (document.getElementById('molduraStatus')) return true;
    const estilo = document.createElement('style');
    estilo.textContent = \`
        :root { --safe-bottom: 20px; }
        .topbar { padding-top: 62px !important; }
        body.tela-login { padding-top: 48px; }
        .versao-app { display: none !important; }
        #molduraStatus { position: fixed; top: 0; left: 0; right: 0; height: 48px; z-index: 9999; display: flex; align-items: center;
            justify-content: space-between; padding: 6px 30px 0 34px; font: 600 16px/1 -apple-system, 'Segoe UI', sans-serif; pointer-events: none; }
        #molduraStatus .entalhe { position: absolute; left: 50%; top: 11px; width: 122px; height: 34px; margin-left: -61px; border-radius: 20px; background: #000; }
        #molduraStatus svg { display: block; }
        #molduraInicio { position: fixed; left: 50%; bottom: 7px; width: 134px; height: 5px; margin-left: -67px; border-radius: 3px; z-index: 9999; pointer-events: none; }
    \`;
    document.head.appendChild(estilo);
    const barra = document.createElement('div');
    barra.id = 'molduraStatus';
    barra.innerHTML = '<span>9:41</span><span class="entalhe"></span><svg width="70" height="13" viewBox="0 0 70 13" fill="currentColor"><rect x="0" y="8" width="3.5" height="5" rx="1"/><rect x="5" y="6" width="3.5" height="7" rx="1"/><rect x="10" y="3.5" width="3.5" height="9.5" rx="1"/><rect x="15" y="1" width="3.5" height="12" rx="1"/><path d="M31 3.2a9.6 9.6 0 0 1 13 0l-1.4 1.5a7.6 7.6 0 0 0-10.2 0zm2.8 3a5.4 5.4 0 0 1 7.4 0l-1.5 1.5a3.3 3.3 0 0 0-4.4 0zm3.7 3.3 1.6 1.7-1.6 1.6-1.6-1.6z"/><rect x="47.5" y="1" width="20" height="11" rx="3" fill="none" stroke="currentColor" stroke-width="1.2" opacity=".5"/><rect x="49.5" y="3" width="16" height="7" rx="1.6"/><rect x="68.4" y="4.2" width="1.6" height="4.6" rx=".8" opacity=".5"/></svg>';
    document.body.appendChild(barra);
    const inicio = document.createElement('div');
    inicio.id = 'molduraInicio';
    document.body.appendChild(inicio);
    return true;
})()`;

// Cor da barra de status conforme a tela: escura na entrada (fundo branco), clara com o cabeçalho azul.
const corDaMoldura = (aba) => aba.js(`(() => {
    const clara = !document.body.classList.contains('tela-login');
    document.getElementById('molduraStatus').style.color = clara ? '#fff' : '#16202e';
    document.getElementById('molduraInicio').style.background = clara ? 'rgba(22,32,46,.55)' : 'rgba(22,32,46,.75)';
    return true;
})()`);

async function fotoDoApp(aba, nome) {
    await aba.js(MOLDURA_CELULAR);
    await corDaMoldura(aba);
    await aba.js('window.scrollTo(0, 0), true');
    await aba.foto(nome, { cantos: 96 });
}

// Chamada à API como o próprio app faz (mesma sessão); erro de regra (409 —
// "já enviado") é aceito, pra dar pra rodar o script mais de uma vez.
const apiDoApp = (aba, caminho, metodo = 'GET', corpo = null) => aba.js(`
    api(${JSON.stringify(caminho)}, ${JSON.stringify({ method: metodo, ...(corpo ? { body: corpo } : {}) })}).catch((e) => ({ erro: e.message }))
`);

async function telasDoApp(navegador) {
    console.log('Área Funcional (app)');
    const aba = await navegador.novaAba();
    await aba.tela(402, 874, true);
    await aba.ir('/web/app/index.html');
    await aba.js('localStorage.clear(), true');
    await aba.ir('/web/app/index.html');

    await fotoDoApp(aba, 'app-01-entrada');
    await aba.js(`document.getElementById('btnInstalar').click(), true`);
    await aba.js(`document.getElementById('instalarBloco').scrollIntoView({ block: 'end' }), true`);
    await esperar(300);
    await aba.js(MOLDURA_CELULAR);
    await corDaMoldura(aba);
    await aba.foto('app-02-instalar', { cantos: 96 });

    await aba.js(`(document.getElementById('qrColado').value = ${JSON.stringify(QR_ALUNO)}, document.getElementById('formColar').requestSubmit(), true)`);
    await esperar(1500);
    await aba.js(`(document.getElementById('servicoFuncao').value = 'esquadrilha', true)`);
    await fotoDoApp(aba, 'app-03-posto-de-servico');

    await aba.js(`document.getElementById('formServico').requestSubmit(), true`);
    await esperar(1500);
    await fotoDoApp(aba, 'app-04-retiradas');

    await aba.js(`document.getElementById('btnNovaRetirada').click(), true`);
    await esperar(800);
    await fotoDoApp(aba, 'app-05-nova-retirada');

    // Uma chamada ainda aberta, com a lista do efetivo.
    await aba.js(`mostrarView('view-retiradas'), true`);
    await esperar(1200);
    await aba.js(`(() => { const pendente = [...document.querySelectorAll('.retirada-item')].find((r) => /pendente/i.test(r.textContent)); pendente.click(); return true; })()`);
    await esperar(1500);
    await fotoDoApp(aba, 'app-06-chamada');

    await aba.js(`(() => { const presente = [...document.querySelectorAll('#listaMarcar .aluno-item')].find((i) => !i.classList.contains('em-falta')); presente.querySelector('.btn-falta').click(); return true; })()`);
    await esperar(600);
    await fotoDoApp(aba, 'app-07-motivo-da-falta');
    await aba.js(`document.getElementById('folhaOpcoesFechar').click(), true`);

    // Uma dispensa médica de exemplo, se ainda não houver.
    const dispensas = await apiDoApp(aba, 'dispensas.php');
    if (Array.isArray(dispensas) && dispensas.length === 0) {
        const alunos = await apiDoApp(aba, 'alunos.php');
        const tipos = await apiDoApp(aba, 'dispensas.php?tipos=1');
        const hoje = new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Sao_Paulo' });
        const termino = new Date(Date.now() + 3 * 864e5).toLocaleDateString('sv-SE', { timeZone: 'America/Sao_Paulo' });
        await apiDoApp(aba, 'dispensas.php', 'POST', {
            aluno_id: Number(alunos[2].id), data_inicio: hoje, data_termino: termino, numero: '112/26',
            motivo: 'Entorse de tornozelo', medico_responsavel: 'Cap Med Fictício',
            dispensado_de: '', dispensa_tipo_ids: tipos.slice(0, 2).map((t) => Number(t.id)),
        });
    }
    await aba.js(`document.querySelector('[data-ir=view-dispensas]').click(), true`);
    await esperar(1200);
    await fotoDoApp(aba, 'app-08-dispensas');

    await aba.js(`document.querySelector('[data-ir=view-efetivo]').click(), true`);
    await esperar(1200);
    await fotoDoApp(aba, 'app-09-efetivo');

    // Livro do Dia: a esquadrilha escreve e envia...
    await apiDoApp(aba, 'livro.php', 'PUT', { observacoes: 'Sem alterações além das chamadas.' });
    await apiDoApp(aba, 'livro.php', 'POST', { acao: 'enviar' });
    await aba.js(`document.querySelector('[data-ir=view-livro]').click(), true`);
    await esperar(1500);
    await fotoDoApp(aba, 'app-10-livro-esquadrilha');

    // ...o Aluno de Dia ao Esquadrão vê o livro pra validar...
    const trocarFuncao = async (funcao) => {
        const servico = await apiDoApp(aba, 'servico.php', 'PUT', { esquadrao: 'Esquadrão Prata', esquadrilha: 'A', funcao });
        await aba.js(`(salvarSessao(sessaoAtual.token, sessaoAtual.aluno, ${JSON.stringify(servico.servico)}), atualizarInfoSessao(), carregarLivro().then(() => true))`);
        await esperar(1200);
    };
    await trocarFuncao('esquadrao');
    await aba.js(`document.getElementById('livroRecebidos').scrollIntoView(), true`);
    await fotoDoApp(aba, 'app-11-livro-esquadrao');

    // ...valida, envia o do esquadrão, e o Aluno de Dia ao CA recebe.
    await apiDoApp(aba, 'livro.php', 'POST', { acao: 'validar', esquadrilha: 'A' });
    await apiDoApp(aba, 'livro.php', 'POST', { acao: 'enviar' });
    await trocarFuncao('ca');
    await fotoDoApp(aba, 'app-12-livro-ca');

    // Easter egg: o raio do olho, no meio do estouro.
    await trocarFuncao('esquadrilha');
    await aba.js(`mostrarView('view-retiradas'), true`);
    await esperar(1000);
    await aba.js(MOLDURA_CELULAR);
    await corDaMoldura(aba);
    await aba.js(`(ArgosRaio.tocar(document.querySelector('.topbar .marca-argos .olho') || document.querySelector('.marca-argos .olho')), true)`);
    await esperar(260);
    const { data } = await aba.cmd('Page.captureScreenshot', { format: 'png' });
    writeFileSync(join(SAIDA, 'app-13-raio.png'), arredondarCantos(Buffer.from(data, 'base64'), 96));
    console.log('  app-13-raio.png');
}

// ---------------------------------------------------------------- Painel e Ikarus37
async function entrar(aba, caminho, { usuario, senha }) {
    await aba.ir(caminho);
    await aba.navegandoCom(`(() => {
        const form = document.querySelector('input[name=usuario]').form;
        form.usuario.value = ${JSON.stringify(usuario)};
        form.senha.value = ${JSON.stringify(senha)};
        form.submit();
        return true;
    })()`);
}

// Postos de serviço com alguém de serviço, se ainda não houver.
async function prepararPostos(aba) {
    await aba.ir('/web/painel/postos_servico.php');
    const postar = (campos) => aba.js(`fetch('postos_servico.php', { method: 'POST', body: new URLSearchParams(${JSON.stringify(campos)}) }).then((r) => r.ok)`);
    const html = await aba.js('document.body.innerText');
    if (!html.includes('Aluno de Dia ao Esquadrão Prata')) {
        await postar({ acao: 'criar_posto', nome: 'Aluno de Dia ao Esquadrão Prata', sigla: 'ADE-PRATA' });
        await postar({ acao: 'criar_posto', nome: 'Plantão da Hora', sigla: 'PH' });
        await aba.ir('/web/painel/postos_servico.php');
        const postos = await aba.js(`[...document.querySelectorAll('form input[name=acao][value=escalar]')].map((i) => i.form.querySelector('input[name=posto_id]').value)`);
        const milhoes = await aba.js(`[...document.querySelectorAll('#lista_alunos option')].map((o) => o.value)`);
        for (let i = 0; i < postos.length; i++) {
            await postar({ acao: 'escalar', posto_id: postos[i], milhao_aluno: milhoes[1 + i * 4] });
        }
    }
}

async function telasDoPainel(navegador) {
    console.log('Painel de Comando');
    const aba = await navegador.novaAba();
    await aba.tela(1280, 800, false);
    await aba.ir('/web/painel/index.php');
    await aba.foto('painel-01-entrada');
    await entrar(aba, '/web/painel/index.php', PAINEL);
    await prepararPostos(aba);
    return aba;
}

async function fotosDoPainel(aba) {
    const paginas = [
        ['painel-02-inicio', 'index.php'],
        ['painel-03-retiradas', 'retiradas.php'],
        ['painel-05-efetivo', 'efetivo.php'],
        ['painel-06-situacao', 'situacao.php'],
        ['painel-07-graficos', 'dashboard.php'],
        ['painel-08-relatorios', 'relatorios.php'],
        ['painel-09-livro-do-dia', 'livro_do_dia.php?esquadrao=todos'],
        ['painel-10-dispensas', 'dispensas.php'],
        ['painel-11-postos-de-servico', 'postos_servico.php'],
        ['painel-12-qrcodes', 'qrcodes.php'],
    ];
    for (const [nome, pagina] of paginas) {
        await aba.ir(`/web/painel/${pagina}`);
        await aba.foto(nome);
    }

    // A chamada pelo Painel, com o painel de motivos aberto.
    await aba.ir('/web/painel/retiradas.php');
    const chamada = await aba.js(`(() => { const linha = [...document.querySelectorAll('tr')].find((tr) => /pendente/i.test(tr.textContent) && tr.querySelector('a[href*="retirada_marcar"]')); return linha ? linha.querySelector('a[href*="retirada_marcar"]').getAttribute('href') : null; })()`);
    if (chamada) {
        await aba.ir(`/web/painel/${chamada}`);
        await aba.js(`(() => { const caixa = [...document.querySelectorAll('tr[id^=linha_] input[type=checkbox]')].find((c) => c.checked); caixa.click(); return true; })()`);
        await esperar(400);
        await aba.foto('painel-04-chamada');
    }
}

async function telasDoIkarus(navegador) {
    console.log('Ikarus37');
    const aba = await navegador.novaAba();
    await aba.tela(1280, 800, false);
    await aba.ir('/web/ikarus37/index.php');
    await aba.foto('ikarus-01-entrada');
    await entrar(aba, '/web/ikarus37/index.php', IKARUS);
    for (const [nome, pagina] of [
        ['ikarus-02-inicio', 'index.php'],
        ['ikarus-03-api', 'api.php'],
        ['ikarus-04-qrcodes', 'qrcodes.php'],
        ['ikarus-05-backup', 'backup.php'],
        ['ikarus-06-usuarios', 'usuarios.php'],
        ['ikarus-07-banco', 'banco.php'],
    ]) {
        await aba.ir(`/web/ikarus37/${pagina}`);
        await aba.foto(nome);
    }
    await telasDaApi(aba);
}

// A página "API" da Wiki: gerar uma chave pela tela, usar e ver no log. A
// chave de exemplo só existe no demo, aparece mascarada na imagem e é
// excluída no fim. As respostas de verdade da API vão pra api-*.json, de
// onde a Wiki tira os exemplos.
async function telasDaApi(aba) {
    const NOME = 'Planilha do Comando (exemplo)';
    const rolarAte = (titulo) => aba.js(`(() => {
        const alvo = [...document.querySelectorAll('h4')].find((h) => h.textContent.includes(${JSON.stringify(titulo)}));
        alvo.closest('.card').scrollIntoView({ block: 'start' });
        window.scrollBy(0, -16);
        return true;
    })()`);

    await aba.ir('/web/ikarus37/api.php');
    await aba.js(`(() => {
        const form = document.querySelector('input[name=acao][value=criar]').form;
        form.nome.value = ${JSON.stringify(NOME)};
        form.escopo.value = 'admin';
        form.scrollIntoView({ block: 'center' });
        return true;
    })()`);
    await aba.foto('ikarus-08-gerar-chave');

    await aba.navegandoCom(`document.querySelector('input[name=acao][value=criar]').form.submit(), true`);
    const chave = await aba.js(`document.querySelector('.chave-nova').textContent.trim()`);
    await aba.js(`(() => {
        const mascara = 'a1b2c3d4… (a chave de verdade aparece aqui, uma única vez) …e5f6';
        document.querySelector('.chave-nova').textContent = mascara;
        const uso = [...document.querySelectorAll('code')].find((c) => c.textContent.includes(${JSON.stringify(chave)}));
        if (uso) uso.textContent = 'X-API-Key: ' + mascara;
        document.querySelector('.chave-nova').closest('.card').scrollIntoView({ block: 'center' });
        return true;
    })()`);
    await aba.foto('ikarus-09-chave-gerada');

    // Os exemplos da Wiki, com a chave recém-gerada.
    const exemplos = {
        'api-situacao': '/api/situacao.php?esquadrao=Esquadr%C3%A3o%20Prata&somente_ausentes',
        'api-relatorios': '/api/relatorios.php?esquadrao=Esquadr%C3%A3o%20Prata',
        'api-retiradas': '/api/retiradas.php?esquadrao=Esquadr%C3%A3o%20Prata&status=enviada',
        'api-motivos-sem-sessao': '/api/motivos.php',
    };
    for (const [nome, caminho] of Object.entries(exemplos)) {
        const resposta = await fetch(BASE + caminho, { headers: { 'X-API-Key': chave } });
        writeFileSync(join(SAIDA, `${nome}.json`), JSON.stringify({ status: resposta.status, corpo: await resposta.json() }, null, 2));
        console.log(`  ${nome}.json (${resposta.status})`);
    }

    await aba.ir('/web/ikarus37/api.php');
    await rolarAte('Log de requisições');
    await aba.foto('ikarus-10-log');
    await rolarAte('Documentação dos endpoints');
    await aba.foto('ikarus-11-documentacao');

    // Sai a chave de exemplo.
    await aba.js(`(() => {
        const linha = [...document.querySelectorAll('tr')].find((tr) => tr.textContent.includes(${JSON.stringify(NOME)}));
        const form = linha && linha.querySelector('input[name=acao][value=excluir]')?.form;
        return form ? fetch('api.php', { method: 'POST', body: new FormData(form) }).then((r) => r.ok) : false;
    })()`);
}

// ---------------------------------------------------------------- execução
mkdirSync(SAIDA, { recursive: true });
const navegador = await abrirNavegador();
try {
    const painel = await telasDoPainel(navegador);
    await telasDoApp(navegador);
    await fotosDoPainel(painel);
    await telasDoIkarus(navegador);
    console.log(`Pronto: ${SAIDA}`);
} finally {
    navegador.fechar();
}
process.exit(0);
