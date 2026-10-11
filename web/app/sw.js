// Service worker da Área Funcional — guarda o "casco" do app (HTML/CSS/JS/
// ícones), pra abrir instantâneo e funcionar como app instalado. Chamadas pra
// /api/ NUNCA passam pelo cache: retirada de falta é dado vivo, servir uma
// versão velha seria pior que não ter cache nenhum.
//
// A versão vem do deploy (scripts/release/gerar-pacote.sh troca o marcador
// abaixo pela tag da release). Ela entra no nome do cache e no endereço dos
// arquivos (?v=…), então cada release busca tudo de novo — a hospedagem guarda
// .js e .css por 7 dias, e sem isso o app novo ficava com script antigo.

const VERSAO = '__VERSAO__';
// No repositório o marcador não é trocado: aí nada fica preso no cache.
const EM_DESENVOLVIMENTO = VERSAO.startsWith('__');
const CACHE_NOME = 'argos-app-shell-' + VERSAO;
const PAGINA = 'index.html';

// Entram no cache com a versão no endereço, igual ao que o index.html pede.
const ARQUIVOS_VERSIONADOS = [
    'css/app.css',
    'js/app.js',
    'js/livro.js',
    'js/qr-scanner.js',
    'vendor/jsQR.js',
];
const ARQUIVOS_FIXOS = [
    'manifest.webmanifest',
    'icons/icon-192.png',
    'icons/icon-512.png',
    'icons/icon-maskable-512.png',
];

self.addEventListener('install', (evento) => {
    evento.waitUntil(
        caches.open(CACHE_NOME).then((cache) => cache.addAll([
            PAGINA,
            ...ARQUIVOS_VERSIONADOS.map((arquivo) => `${arquivo}?v=${VERSAO}`),
            ...ARQUIVOS_FIXOS,
        ]))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (evento) => {
    evento.waitUntil((async () => {
        const antigos = (await caches.keys()).filter((nome) => nome !== CACHE_NOME);
        await Promise.all(antigos.map((nome) => caches.delete(nome)));
        await self.clients.claim();

        // Tinha versão anterior aberta: recarrega, pra ninguém ficar com a
        // tela antiga falando com a API nova.
        if (antigos.length > 0) {
            const janelas = await self.clients.matchAll({ type: 'window' });
            janelas.forEach((janela) => janela.navigate(janela.url).catch(() => {}));
        }
    })());
});

function guardar(pedido, resposta) {
    if (resposta.ok) {
        const copia = resposta.clone();
        caches.open(CACHE_NOME).then((cache) => cache.put(pedido, copia));
    }
    return resposta;
}

self.addEventListener('fetch', (evento) => {
    const pedido = evento.request;
    const url = new URL(pedido.url);

    // Nunca intercepta a API — nem pra ler do cache, nem pra gravar nele.
    if (url.pathname.includes('/api/') || url.pathname.endsWith('/env.php')) {
        return;
    }
    if (pedido.method !== 'GET') {
        return;
    }

    // A página vem sempre da rede (é ela que diz qual versão dos arquivos
    // carregar); o cache só entra sem conexão.
    if (pedido.mode === 'navigate') {
        evento.respondWith(
            fetch(pedido).then((resposta) => guardar(PAGINA, resposta)).catch(() => caches.match(PAGINA))
        );
        return;
    }

    if (EM_DESENVOLVIMENTO) {
        evento.respondWith(
            fetch(pedido).then((resposta) => guardar(pedido, resposta)).catch(() => caches.match(pedido))
        );
        return;
    }

    // O resto tem a versão no endereço: o que está no cache é o certo.
    evento.respondWith(
        caches.match(pedido).then((doCache) => doCache || fetch(pedido).then((resposta) => guardar(pedido, resposta)))
    );
});
