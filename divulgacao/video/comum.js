// Peças comuns aos vídeos do Argos: tempo, sorteio determinístico, o olho, o raio,
// as molduras das telas, os textos e o som.
//
// Regra de todas as peças: desenhar(quadro) é função só do número do quadro — nada de
// Math.random, Date ou estado acumulado — pra prévia no navegador e o render saírem iguais.

const LARGURA = 1920;
const ALTURA = 1080;
const FPS = 30;

const COR = {
    noite: '#030b18',
    azulEscuro: '#061c3a',
    azul: '#0a2e5c',
    azulClaro: '#2f6fed',
    vermelho: '#ff1f2d',
    nucleo: '#fff1f1',
    branco: '#f4f7fc',
    apagado: '#8a9bb8',
};

const PARAMETROS = new URLSearchParams(location.search);

// Onde o olho do Argos está quando a vinheta começa: o fim da peça A termina com o olho
// exatamente aqui, pra uma peça continuar a outra.
const OLHO_DA_VINHETA = { x: LARGURA / 2, y: 430, tamanho: 380 };

const FONTE_TITULO = '"Barlow Semi Condensed", system-ui, sans-serif';
const FONTE_TEXTO = '"IBM Plex Sans", system-ui, sans-serif';

// ---------- tempo ----------
const limitar = (v, a = 0, b = 1) => Math.min(b, Math.max(a, v));
const misturar = (a, b, p) => a + (b - a) * p;
// progresso 0..1 de t dentro do intervalo [a, b]
const trecho = (t, a, b) => limitar((t - a) / (b - a));
const suave = p => p * p * (3 - 2 * p);
const saida = p => 1 - Math.pow(1 - p, 3);
const entradaSaida = p => (p < 0.5 ? 4 * p * p * p : 1 - Math.pow(-2 * p + 2, 3) / 2);
const mola = p => { const c = 1.70158; return 1 + (c + 1) * Math.pow(p - 1, 3) + c * Math.pow(p - 1, 2); };

// ---------- sorteio determinístico ----------
function sorteio(semente) {
    let a = semente >>> 0;
    return () => {
        a = (a + 0x6d2b79f5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

// ---------- imagens e fontes ----------
const _esperas = [];
function imagem(src) {
    const img = new Image();
    _esperas.push(new Promise(ok => { img.onload = ok; img.onerror = () => { console.warn('faltou', src); ok(); }; }));
    img.src = src;
    return img;
}
function fonte(familia, arquivo, peso) {
    const f = new FontFace(familia, `url(${arquivo})`, { weight: String(peso) });
    _esperas.push(f.load().then(f => document.fonts.add(f)).catch(() => console.warn('faltou', arquivo)));
}
const PASTA_FONTES = '../../web/fonts/';
fonte('Barlow Semi Condensed', PASTA_FONTES + 'barlow-semi-condensed-700-latin.woff2', 700);
fonte('Barlow Semi Condensed', PASTA_FONTES + 'barlow-semi-condensed-600-latin.woff2', 600);
fonte('IBM Plex Sans', PASTA_FONTES + 'ibm-plex-sans-400-latin.woff2', 400);
fonte('IBM Plex Sans', PASTA_FONTES + 'ibm-plex-sans-600-latin.woff2', 600);
const imagemOk = img => img.complete && img.naturalWidth > 0;

// ---------- o raio ----------
// Mesmo traçado do easter egg do app (web/app/js/raio.js), mas sorteado com semente.
function tracadoDoRaio(sortear, x0, y0, x1, y1, desvio, nivel = 7) {
    if (nivel === 0) return [[x0, y0], [x1, y1]];
    const mx = (x0 + x1) / 2 + (sortear() - 0.5) * desvio;
    const my = (y0 + y1) / 2 + (sortear() - 0.5) * desvio * 0.25;
    const antes = tracadoDoRaio(sortear, x0, y0, mx, my, desvio * 0.55, nivel - 1);
    const depois = tracadoDoRaio(sortear, mx, my, x1, y1, desvio * 0.55, nivel - 1);
    return antes.concat(depois.slice(1));
}

// Raio inteiro: o tronco e alguns galhos saindo dele.
function montarRaio(semente, x0, y0, x1, y1) {
    const sortear = sorteio(semente);
    const tronco = tracadoDoRaio(sortear, x0, y0, x1, y1, Math.abs(y1 - y0) * 0.35);
    const galhos = [];
    for (let i = 0; i < 5; i++) {
        const k = Math.floor((0.2 + sortear() * 0.6) * tronco.length);
        const [gx, gy] = tronco[k];
        const lado = sortear() < 0.5 ? -1 : 1;
        const comprimento = (y1 - y0) * (0.15 + sortear() * 0.2);
        galhos.push({ k, pontos: tracadoDoRaio(sortear, gx, gy, gx + lado * comprimento * 0.8, gy + comprimento, comprimento * 0.5, 5) });
    }
    return { tronco, galhos };
}

// p: quanto do raio já desceu (0..1); forca: brilho (0..1)
function desenharRaio(ctx, raio, p, forca) {
    if (forca <= 0) return;
    const trilha = (pontos, ate) => {
        const n = Math.max(2, Math.floor(pontos.length * ate));
        ctx.beginPath();
        ctx.moveTo(pontos[0][0], pontos[0][1]);
        for (let i = 1; i < n; i++) ctx.lineTo(pontos[i][0], pontos[i][1]);
    };
    const camadas = [[34, 'rgba(255,31,45,0.18)', 60], [14, 'rgba(255,31,45,0.55)', 30], [5, COR.vermelho, 12], [2, COR.nucleo, 0]];
    ctx.save();
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.globalAlpha = forca;
    for (const [largura, cor, sombra] of camadas) {
        ctx.lineWidth = largura;
        ctx.strokeStyle = cor;
        ctx.shadowColor = COR.vermelho;
        ctx.shadowBlur = sombra;
        trilha(raio.tronco, p);
        ctx.stroke();
        for (const g of raio.galhos) {
            const inicio = g.k / raio.tronco.length;
            if (p <= inicio) continue;
            ctx.lineWidth = largura * 0.45;
            trilha(g.pontos, limitar((p - inicio) / (1 - inicio)));
            ctx.stroke();
            ctx.lineWidth = largura;
        }
    }
    ctx.restore();
}

// Faíscas do estouro, todas calculadas a partir do tempo desde o impacto.
function desenharFaiscas(ctx, semente, x, y, dt, quantas = 70) {
    if (dt < 0 || dt > 1.6) return;
    const sortear = sorteio(semente);
    ctx.save();
    ctx.globalCompositeOperation = 'lighter';
    for (let i = 0; i < quantas; i++) {
        const ang = Math.PI + sortear() * Math.PI;   // pra cima e pros lados
        const vel = 500 + sortear() * 1300;
        const vida = 0.5 + sortear() * 1.0;
        if (dt > vida) continue;
        const px = x + Math.cos(ang) * vel * dt;
        const py = y + Math.sin(ang) * vel * dt + 900 * dt * dt;
        const a = 1 - dt / vida;
        ctx.strokeStyle = `rgba(255,${Math.round(80 + 140 * a)},${Math.round(80 * a)},${a})`;
        ctx.lineWidth = 2 + 3 * a;
        ctx.beginPath();
        ctx.moveTo(px, py);
        ctx.lineTo(px - Math.cos(ang) * vel * 0.02, py - (Math.sin(ang) * vel + 1800 * dt) * 0.02);
        ctx.stroke();
    }
    ctx.restore();
}

// ---------- o olho do Argos ----------
// Mesmo desenho de web/images/argos-olho.svg (24 unidades), centrado em (x, y).
// abertura 0 = fechado, 1 = aberto; brilho 0..1 acende o vermelho.
function desenharOlho(ctx, x, y, tamanho, abertura, brilho, cor = COR.branco) {
    const s = tamanho / 24;
    const a = Math.max(0.04, abertura);
    ctx.save();
    ctx.translate(x, y);
    ctx.scale(s, s);
    const contorno = () => {
        ctx.beginPath();
        ctx.moveTo(-10, 0);
        ctx.bezierCurveTo(-7.4, -4.7 * a, -4, -7 * a, 0, -7 * a);
        ctx.bezierCurveTo(4, -7 * a, 7.4, -4.7 * a, 10, 0);
        ctx.bezierCurveTo(7.4, 4.7 * a, 4, 7 * a, 0, 7 * a);
        ctx.bezierCurveTo(-4, 7 * a, -7.4, 4.7 * a, -10, 0);
        ctx.closePath();
    };
    if (brilho > 0) {
        const halo = ctx.createRadialGradient(0, 0, 0, 0, 0, 16);
        halo.addColorStop(0, `rgba(255,31,45,${0.55 * brilho})`);
        halo.addColorStop(1, 'rgba(255,31,45,0)');
        ctx.fillStyle = halo;
        ctx.fillRect(-18, -18, 36, 36);
    }
    ctx.save();
    contorno();
    ctx.clip();
    const iris = brilho > 0 ? misturarCor(cor, COR.vermelho, brilho) : cor;
    ctx.fillStyle = iris;
    ctx.shadowColor = COR.vermelho;
    ctx.shadowBlur = 6 * brilho;
    ctx.beginPath();
    ctx.arc(0, 0, 3.6, 0, Math.PI * 2);
    ctx.arc(1.4, -1, 1.1, 0, Math.PI * 2, true);
    ctx.fill('evenodd');
    ctx.restore();
    contorno();
    ctx.lineWidth = 2;
    ctx.strokeStyle = brilho > 0 ? misturarCor(cor, COR.vermelho, brilho * 0.8) : cor;
    ctx.shadowColor = COR.vermelho;
    ctx.shadowBlur = 8 * brilho;
    ctx.stroke();
    ctx.restore();
}

function misturarCor(c1, c2, p) {
    const n = c => [1, 3, 5].map(i => parseInt(c.slice(i, i + 2), 16));
    const [a, b] = [n(c1), n(c2)];
    return `rgb(${a.map((v, i) => Math.round(misturar(v, b[i], p))).join(',')})`;
}

// ---------- fundo ----------
// Noite azul com uma grade que anda devagar (a "rede" da Escola).
function desenharFundo(ctx, t, tom = 1) {
    const g = ctx.createRadialGradient(LARGURA / 2, ALTURA * 0.45, 100, LARGURA / 2, ALTURA / 2, LARGURA * 0.75);
    g.addColorStop(0, misturarCor(COR.noite, COR.azulEscuro, tom));
    g.addColorStop(1, COR.noite);
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, LARGURA, ALTURA);
    const passo = 80;
    const desloca = (t * 12) % passo;
    ctx.save();
    ctx.strokeStyle = `rgba(47,111,237,${0.07 * tom})`;
    ctx.lineWidth = 1;
    ctx.beginPath();
    for (let x = -passo + desloca; x < LARGURA + passo; x += passo) { ctx.moveTo(x, 0); ctx.lineTo(x, ALTURA); }
    for (let y = -passo + desloca; y < ALTURA + passo; y += passo) { ctx.moveTo(0, y); ctx.lineTo(LARGURA, y); }
    ctx.stroke();
    ctx.restore();
}

// ---------- telas ----------
// Captura do app (já vem com a moldura de celular e os cantos arredondados).
function desenharCelular(ctx, img, cx, cy, altura, alfa = 1, giro = 0) {
    if (!imagemOk(img) || alfa <= 0) return;
    const largura = altura * img.naturalWidth / img.naturalHeight;
    ctx.save();
    ctx.globalAlpha = alfa;
    ctx.translate(cx, cy);
    ctx.rotate(giro);
    ctx.shadowColor = 'rgba(0,0,0,0.55)';
    ctx.shadowBlur = 50;
    ctx.shadowOffsetY = 24;
    ctx.drawImage(img, -largura / 2, -altura / 2, largura, altura);
    ctx.restore();
}

// Captura do Painel num monitor; recorte = [x, y, l, a] em fração da imagem (pra dar zoom).
function desenharMonitor(ctx, img, cx, cy, largura, alfa = 1, recorte = [0, 0, 1, 1]) {
    if (!imagemOk(img) || alfa <= 0) return;
    const altura = largura * (img.naturalHeight * recorte[3]) / (img.naturalWidth * recorte[2]);
    const borda = 14;
    ctx.save();
    ctx.globalAlpha = alfa;
    ctx.translate(cx - largura / 2, cy - altura / 2);
    ctx.shadowColor = 'rgba(0,0,0,0.6)';
    ctx.shadowBlur = 60;
    ctx.shadowOffsetY = 30;
    ctx.fillStyle = '#111a2b';
    retanguloRedondo(ctx, -borda, -borda, largura + borda * 2, altura + borda * 2, 18);
    ctx.fill();
    ctx.shadowColor = 'transparent';
    ctx.save();
    retanguloRedondo(ctx, 0, 0, largura, altura, 6);
    ctx.clip();
    const [rx, ry, rl, ra] = recorte;
    ctx.drawImage(img, rx * img.naturalWidth, ry * img.naturalHeight, rl * img.naturalWidth, ra * img.naturalHeight, 0, 0, largura, altura);
    ctx.restore();
    ctx.restore();
}

function retanguloRedondo(ctx, x, y, l, a, r) {
    ctx.beginPath();
    ctx.roundRect(x, y, l, a, r);
}

// Toque do dedo: um círculo que aparece e se abre.
function desenharToque(ctx, x, y, dt) {
    if (dt < 0 || dt > 0.6) return;
    const p = dt / 0.6;
    ctx.save();
    ctx.fillStyle = `rgba(255,255,255,${0.45 * (1 - p)})`;
    ctx.beginPath();
    ctx.arc(x, y, 26, 0, Math.PI * 2);
    ctx.fill();
    ctx.strokeStyle = `rgba(255,31,45,${1 - p})`;
    ctx.lineWidth = 4;
    ctx.beginPath();
    ctx.arc(x, y, 26 + 50 * saida(p), 0, Math.PI * 2);
    ctx.stroke();
    ctx.restore();
}

// ---------- textos ----------
// Palavra-chave: entra subindo, com um traço vermelho embaixo; some no fim.
function palavraChave(ctx, texto, x, y, tamanho, t, inicio, fim, alinhamento = 'left') {
    const entra = saida(trecho(t, inicio, inicio + 0.45));
    const sai = 1 - trecho(t, fim - 0.3, fim);
    const alfa = Math.min(entra, sai);
    if (alfa <= 0) return;
    ctx.save();
    ctx.globalAlpha = alfa;
    ctx.font = `700 ${tamanho}px ${FONTE_TITULO}`;
    ctx.letterSpacing = `${Math.round(tamanho * 0.04)}px`;
    ctx.textAlign = alinhamento;
    ctx.textBaseline = 'alphabetic';
    ctx.fillStyle = COR.branco;
    ctx.fillText(texto.toUpperCase(), x, y + 40 * (1 - entra));
    const largura = ctx.measureText(texto.toUpperCase()).width;
    const x0 = alinhamento === 'center' ? x - largura / 2 : alinhamento === 'right' ? x - largura : x;
    ctx.fillStyle = COR.vermelho;
    ctx.shadowColor = COR.vermelho;
    ctx.shadowBlur = 16;
    ctx.fillRect(x0, y + tamanho * 0.18, largura * saida(trecho(t, inicio + 0.15, inicio + 0.7)), Math.max(4, tamanho * 0.06));
    ctx.restore();
}

function legenda(ctx, texto, x, y, tamanho, t, inicio, fim, alinhamento = 'left', cor = COR.apagado) {
    const alfa = Math.min(saida(trecho(t, inicio, inicio + 0.4)), 1 - trecho(t, fim - 0.3, fim));
    if (alfa <= 0) return;
    ctx.save();
    ctx.globalAlpha = alfa;
    ctx.font = `400 ${tamanho}px ${FONTE_TEXTO}`;
    ctx.letterSpacing = '0px';
    ctx.textAlign = alinhamento;
    ctx.fillStyle = cor;
    ctx.fillText(texto, x, y + 16 * (1 - alfa));
    ctx.restore();
}

// ---------- som ----------
// Blocos de síntese pro window.SCORE (Web Audio; o render toca offline).
function somNota(ac, destino, { t, freq, dur, tipo = 'sine', vol = 0.2, ataque = 0.01, filtro = null }) {
    const o = ac.createOscillator();
    const g = ac.createGain();
    o.type = tipo;
    o.frequency.setValueAtTime(freq, t);
    g.gain.setValueAtTime(0, t);
    g.gain.linearRampToValueAtTime(vol, t + ataque);
    g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    let saidaNo = g;
    if (filtro) {
        const f = ac.createBiquadFilter();
        f.type = 'lowpass';
        f.frequency.value = filtro;
        g.connect(f);
        saidaNo = f;
    }
    o.connect(g);
    saidaNo.connect(destino);
    o.start(t);
    o.stop(t + dur + 0.05);
}

function somRuido(ac, destino, { t, dur, vol = 0.2, tipo = 'lowpass', freq = 1000, q = 0.7, ataque = 0.005, semente = 1 }) {
    const n = Math.ceil(ac.sampleRate * dur);
    const buf = ac.createBuffer(1, n, ac.sampleRate);
    const dados = buf.getChannelData(0);
    const sortear = sorteio(semente);
    for (let i = 0; i < n; i++) dados[i] = sortear() * 2 - 1;
    const s = ac.createBufferSource();
    s.buffer = buf;
    const f = ac.createBiquadFilter();
    f.type = tipo;
    f.frequency.value = freq;
    f.Q.value = q;
    const g = ac.createGain();
    g.gain.setValueAtTime(0, t);
    g.gain.linearRampToValueAtTime(vol, t + ataque);
    g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    s.connect(f);
    f.connect(g);
    g.connect(destino);
    s.start(t);
    return { fonte: s, filtro: f, ganho: g };
}

function somBumbo(ac, destino, t, vol = 0.8, grave = 48) {
    const o = ac.createOscillator();
    const g = ac.createGain();
    o.frequency.setValueAtTime(150, t);
    o.frequency.exponentialRampToValueAtTime(grave, t + 0.12);
    g.gain.setValueAtTime(vol, t);
    g.gain.exponentialRampToValueAtTime(0.0001, t + 0.45);
    o.connect(g);
    g.connect(destino);
    o.start(t);
    o.stop(t + 0.5);
}

// Saída com um compressor leve e um eco curto, pra tudo soar como uma peça só.
function mesaDeSom(ac) {
    const comp = ac.createDynamicsCompressor();
    comp.threshold.value = -16;
    comp.ratio.value = 3;
    comp.connect(ac.destination);
    const eco = ac.createDelay(1);
    eco.delayTime.value = 0.375;
    const volta = ac.createGain();
    volta.gain.value = 0.28;
    const envio = ac.createGain();
    envio.gain.value = 0.35;
    envio.connect(eco);
    eco.connect(volta);
    volta.connect(eco);
    volta.connect(comp);
    return { seco: comp, eco: envio };
}

// ---------- prévia ao vivo ----------
// Sem ?render, a página toca sozinha em loop; um clique recomeça com som.
function iniciarPrevia() {
    const canvas = document.querySelector('canvas');
    const ctx = canvas.getContext('2d');
    window.ctx = ctx;
    if (new URLSearchParams(location.search).has('render')) return;
    let comeco = performance.now();
    let audio = null;
    canvas.addEventListener('click', () => {
        if (audio) audio.close();
        audio = window.SCORE ? new AudioContext() : null;
        if (audio) window.SCORE(audio);
        comeco = performance.now();
    });
    const passo = () => {
        const quadro = Math.floor((performance.now() - comeco) / 1000 * FPS);
        if (quadro >= window.FRAMES) comeco = performance.now();
        window.draw(Math.min(quadro, window.FRAMES - 1));
        requestAnimationFrame(passo);
    };
    window.PRONTO.then(() => requestAnimationFrame(passo));
}

window.PRONTO = new Promise(ok => setTimeout(() => Promise.all(_esperas).then(ok), 0));
