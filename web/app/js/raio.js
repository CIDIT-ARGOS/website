// Easter egg da Área Funcional: tocar no olho do Argos faz cair um raio
// vermelho, do olho até o rodapé do app, onde ele estoura.
//
// Mesma técnica de animação quadro a quadro em <canvas> dos sites feitos a
// partir de vídeo: a cena é uma sequência numerada de quadros e
// desenharQuadro(cena, i) pinta o quadro i. A diferença é que aqui os quadros
// não são imagens extraídas de um vídeo — são calculados na hora (o traçado do
// raio é sorteado a cada toque) e quem avança é o relógio, não a rolagem.
// Nada é baixado: sem imagens, sem biblioteca, funciona na intranet.

const ArgosRaio = (() => {
    const QUADROS_POR_SEGUNDO = 60;
    const QUADRO_DO_IMPACTO = 14;   // o raio leva 14 quadros pra chegar no chão
    const TOTAL_DE_QUADROS = 84;    // ~1,4 s
    const VERMELHO = '#ff1f2d';
    const NUCLEO = '#fff1f1';

    let canvas = null;
    let ctx = null;
    let tocando = false;

    // Traçado em zigue-zague entre dois pontos: divide o segmento ao meio e
    // desloca o meio pro lado, cada vez menos.
    function tracado(x0, y0, x1, y1, desvio, nivel = 6) {
        if (nivel === 0) return [[x0, y0], [x1, y1]];
        const mx = (x0 + x1) / 2 + (Math.random() - 0.5) * desvio;
        const my = (y0 + y1) / 2 + (Math.random() - 0.5) * desvio * 0.25;
        const antes = tracado(x0, y0, mx, my, desvio * 0.55, nivel - 1);
        const depois = tracado(mx, my, x1, y1, desvio * 0.55, nivel - 1);
        return antes.concat(depois.slice(1));
    }

    function prepararCanvas() {
        if (!canvas) {
            canvas = document.createElement('canvas');
            canvas.className = 'raio-canvas';
            canvas.setAttribute('aria-hidden', 'true');
            document.body.appendChild(canvas);
            ctx = canvas.getContext('2d');
        }
        const escala = Math.min(window.devicePixelRatio || 1, 2);
        canvas.width = Math.round(window.innerWidth * escala);
        canvas.height = Math.round(window.innerHeight * escala);
        ctx.setTransform(escala, 0, 0, escala, 0, 0);
    }

    // Sorteia a cena inteira de uma vez; os quadros só leem.
    function montarCena(olho) {
        const largura = window.innerWidth;
        const altura = window.innerHeight;
        const caixa = olho.getBoundingClientRect();
        const origem = [caixa.left + caixa.width / 2, caixa.top + caixa.height / 2];

        // O chão é o topo da barra inferior, quando ela está na tela.
        const barra = document.querySelector('.nav-inferior:not(.oculta)');
        const chao = barra ? barra.getBoundingClientRect().top : altura - 6;
        const alvoX = Math.max(40, Math.min(largura - 40, largura * (0.3 + Math.random() * 0.4)));

        const principal = tracado(origem[0], origem[1], alvoX, chao, largura * 0.42);

        // Ramos menores saindo do traçado principal.
        const ramos = [];
        for (let i = 0; i < 4; i++) {
            const inicio = Math.floor(principal.length * (0.2 + Math.random() * 0.55));
            const [x, y] = principal[inicio];
            const lado = Math.random() < 0.5 ? -1 : 1;
            const comprimento = 50 + Math.random() * 90;
            ramos.push({
                inicio,
                pontos: tracado(x, y, x + lado * comprimento * 0.8, Math.min(chao, y + comprimento), 46, 4),
            });
        }

        const faiscas = [];
        for (let i = 0; i < 46; i++) {
            const angulo = -Math.PI * (0.08 + Math.random() * 0.84);
            const forca = 3 + Math.random() * 9;
            faiscas.push({
                vx: Math.cos(angulo) * forca,
                vy: Math.sin(angulo) * forca,
                vida: 22 + Math.floor(Math.random() * 40),
                tamanho: 1 + Math.random() * 2.2,
                clara: Math.random() < 0.35,
            });
        }

        // Tremida do brilho, quadro a quadro, sorteada antes pra ser estável.
        const tremida = Array.from({ length: TOTAL_DE_QUADROS }, () => 0.55 + Math.random() * 0.45);

        return { largura, altura, origem, chao, alvoX, principal, ramos, faiscas, tremida };
    }

    function riscar(pontos, ate, larguraLinha, cor, brilho) {
        if (ate < 2) return;
        ctx.beginPath();
        ctx.moveTo(pontos[0][0], pontos[0][1]);
        for (let i = 1; i < ate; i++) ctx.lineTo(pontos[i][0], pontos[i][1]);
        ctx.lineWidth = larguraLinha;
        ctx.strokeStyle = cor;
        ctx.shadowColor = VERMELHO;
        ctx.shadowBlur = brilho;
        ctx.lineJoin = 'round';
        ctx.lineCap = 'round';
        ctx.stroke();
    }

    function desenharQuadro(cena, i) {
        const { largura, altura, chao, alvoX, principal, ramos, faiscas, tremida } = cena;
        ctx.clearRect(0, 0, largura, altura);
        ctx.shadowBlur = 0;

        const depois = i - QUADRO_DO_IMPACTO; // quadros desde o impacto (negativo = ainda caindo)

        // ---- O raio: desce até o impacto, depois pisca e some ----
        const forcaDoRaio = depois < 0 ? 1 : Math.max(0, 1 - depois / 16) * tremida[i];
        if (forcaDoRaio > 0.02) {
            const descida = depois < 0 ? Math.pow((i + 1) / QUADRO_DO_IMPACTO, 1.6) : 1;
            const ate = Math.max(2, Math.ceil(principal.length * descida));

            ctx.globalAlpha = forcaDoRaio;
            riscar(principal, ate, 9, 'rgba(255, 31, 45, .35)', 34);
            riscar(principal, ate, 4.5, VERMELHO, 22);
            riscar(principal, ate, 1.8, NUCLEO, 8);
            ramos.forEach((ramo) => {
                if (ate <= ramo.inicio) return;
                const parte = Math.min(ramo.pontos.length, Math.ceil((ate - ramo.inicio) * 1.4));
                riscar(ramo.pontos, parte, 2.4, VERMELHO, 14);
                riscar(ramo.pontos, parte, 0.9, NUCLEO, 4);
            });

            // A ponta, acesa, enquanto ainda está caindo.
            if (depois < 0) {
                const [px, py] = principal[ate - 1];
                const ponta = ctx.createRadialGradient(px, py, 0, px, py, 26);
                ponta.addColorStop(0, 'rgba(255, 241, 241, .95)');
                ponta.addColorStop(1, 'rgba(255, 31, 45, 0)');
                ctx.shadowBlur = 0;
                ctx.fillStyle = ponta;
                ctx.fillRect(px - 26, py - 26, 52, 52);
            }
            ctx.globalAlpha = 1;
        }
        ctx.shadowBlur = 0;

        if (depois < 0) return;

        // ---- O estouro no rodapé ----
        // Clarão vermelho na tela inteira, forte e curto.
        const clarao = Math.max(0, 1 - depois / 9);
        if (clarao > 0) {
            ctx.fillStyle = `rgba(255, 31, 45, ${0.34 * clarao})`;
            ctx.fillRect(0, 0, largura, altura);
        }

        // Bola de luz no ponto do impacto, que cresce e apaga.
        const explosao = Math.max(0, 1 - depois / 26);
        if (explosao > 0) {
            const raio = 40 + (1 - explosao) * largura * 0.55;
            const luz = ctx.createRadialGradient(alvoX, chao, 0, alvoX, chao, raio);
            luz.addColorStop(0, `rgba(255, 241, 241, ${0.95 * explosao})`);
            luz.addColorStop(0.25, `rgba(255, 31, 45, ${0.75 * explosao})`);
            luz.addColorStop(1, 'rgba(255, 31, 45, 0)');
            ctx.fillStyle = luz;
            ctx.fillRect(alvoX - raio, chao - raio, raio * 2, raio * 2);
        }

        // Onda de choque correndo pelo chão.
        const onda = depois / 30;
        if (onda < 1) {
            ctx.beginPath();
            ctx.ellipse(alvoX, chao, 20 + onda * largura * 0.7, 6 + onda * 34, 0, 0, Math.PI * 2);
            ctx.lineWidth = 5 * (1 - onda) + 0.5;
            ctx.strokeStyle = `rgba(255, 90, 100, ${1 - onda})`;
            ctx.shadowColor = VERMELHO;
            ctx.shadowBlur = 16;
            ctx.stroke();
            ctx.shadowBlur = 0;
        }

        // Faíscas: sobem, perdem força e caem.
        faiscas.forEach((f) => {
            if (depois >= f.vida) return;
            const t = depois;
            const x = alvoX + f.vx * t;
            const y = Math.min(chao, chao + f.vy * t + 0.22 * t * t);
            const xAntes = alvoX + f.vx * (t - 1.6);
            const yAntes = Math.min(chao, chao + f.vy * (t - 1.6) + 0.22 * (t - 1.6) * (t - 1.6));
            ctx.globalAlpha = 1 - t / f.vida;
            ctx.beginPath();
            ctx.moveTo(xAntes, yAntes);
            ctx.lineTo(x, y);
            ctx.lineWidth = f.tamanho;
            ctx.lineCap = 'round';
            ctx.strokeStyle = f.clara ? NUCLEO : VERMELHO;
            ctx.stroke();
        });
        ctx.globalAlpha = 1;

        // Brasa no chão, a última coisa a apagar.
        const brasa = Math.max(0, 1 - depois / (TOTAL_DE_QUADROS - QUADRO_DO_IMPACTO));
        const marca = ctx.createRadialGradient(alvoX, chao, 0, alvoX, chao, 70);
        marca.addColorStop(0, `rgba(255, 60, 70, ${0.7 * brasa})`);
        marca.addColorStop(1, 'rgba(255, 31, 45, 0)');
        ctx.fillStyle = marca;
        ctx.fillRect(alvoX - 70, chao - 30, 140, 60);
    }

    function tocar(olho) {
        if (tocando || !olho) return;

        // Quem pediu menos movimento ganha só uma piscada vermelha do olho.
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            olho.classList.add('olho-aceso');
            setTimeout(() => olho.classList.remove('olho-aceso'), 700);
            return;
        }

        tocando = true;
        prepararCanvas();
        const cena = montarCena(olho);
        olho.classList.add('olho-aceso');
        canvas.classList.add('ativo');

        const inicio = performance.now();
        let ultimo = -1;
        let tremeu = false;

        function passo(agora) {
            // O relógio escolhe o quadro: se o aparelho engasgar, pula quadro mas não atrasa.
            const quadro = Math.floor((agora - inicio) / 1000 * QUADROS_POR_SEGUNDO);
            if (quadro >= TOTAL_DE_QUADROS) {
                ctx.clearRect(0, 0, cena.largura, cena.altura);
                canvas.classList.remove('ativo');
                olho.classList.remove('olho-aceso');
                tocando = false;
                return;
            }
            if (quadro !== ultimo) {
                ultimo = quadro;
                desenharQuadro(cena, quadro);
                if (!tremeu && quadro >= QUADRO_DO_IMPACTO) {
                    tremeu = true;
                    document.body.classList.add('raio-impacto');
                    setTimeout(() => document.body.classList.remove('raio-impacto'), 420);
                    if (navigator.vibrate) navigator.vibrate([40, 30, 70]);
                }
            }
            requestAnimationFrame(passo);
        }
        requestAnimationFrame(passo);
    }

    // O olho é pequeno: vale o toque nele ou bem perto, dentro da marca.
    document.addEventListener('click', (evento) => {
        const marca = evento.target.closest ? evento.target.closest('.marca-argos') : null;
        const olho = marca && marca.querySelector('.olho');
        if (!olho) return;
        const caixa = olho.getBoundingClientRect();
        const folga = 12;
        if (evento.clientX >= caixa.left - folga && evento.clientX <= caixa.right + folga
            && evento.clientY >= caixa.top - folga && evento.clientY <= caixa.bottom + folga) {
            tocar(olho);
        }
    });

    return { tocar };
})();
