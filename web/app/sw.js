// Service worker da Área Funcional — cacheia só o "casco" do app (HTML/CSS/JS/
// ícones), pra abrir instantâneo e funcionar como app instalado. Chamadas pra
// /api/ NUNCA passam pelo cache: retirada de falta é dado vivo, servir uma
// versão velha seria pior que não ter cache nenhum.

const CACHE_NOME = 'argos-app-shell-v1';

const ARQUIVOS_SHELL = [
    'index.html',
    'css/app.css',
    'js/app.js',
    'js/qr-scanner.js',
    'manifest.webmanifest',
    'icons/icon-192.png',
    'icons/icon-512.png',
    'icons/icon-maskable-512.png',
];

self.addEventListener('install', (evento) => {
    evento.waitUntil(
        caches.open(CACHE_NOME).then((cache) => cache.addAll(ARQUIVOS_SHELL))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (evento) => {
    evento.waitUntil(
        caches.keys().then((nomes) =>
            Promise.all(nomes.filter((nome) => nome !== CACHE_NOME).map((nome) => caches.delete(nome)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (evento) => {
    const url = new URL(evento.request.url);

    // Nunca intercepta a API — nem pra ler do cache, nem pra gravar nele.
    if (url.pathname.startsWith('/api/') || url.pathname.includes('/app/env.php')) {
        return;
    }

    if (evento.request.method !== 'GET') {
        return;
    }

    evento.respondWith(
        caches.match(evento.request).then((respostaCache) => {
            if (respostaCache) {
                return respostaCache;
            }
            return fetch(evento.request).then((respostaRede) => {
                const copia = respostaRede.clone();
                caches.open(CACHE_NOME).then((cache) => cache.put(evento.request, copia));
                return respostaRede;
            });
        }).catch(() => caches.match('index.html'))
    );
});
