// Leitor de QR Code do Argos — usado pela Área Funcional e pela tela de QR
// Codes do Painel e do Ikarus37.
//
// Tudo local: o decodificador (jsQR, em ../vendor/jsQR.js) é servido pelo
// próprio sistema, nada vem da internet — o Argos roda em intranet. Quando o
// navegador tem BarcodeDetector nativo que lê QR ele é usado primeiro, por ser
// mais rápido, e o jsQR confere em paralelo; se o nativo falhar, fica só o jsQR.
//
// A câmera fica aberta até alguém mandar parar. Quando um QR é lido o leitor
// PAUSA (congela a imagem) e avisa quem chamou, que mostra o que foi lido e
// espera a confirmação — nada é enviado sozinho. `retomar()` volta a procurar.
//
//   ArgosQrScanner.iniciar(video, aoLer, aoFalhar)   abre a câmera e procura
//   ArgosQrScanner.retomar()                          volta a procurar depois de uma leitura
//   ArgosQrScanner.parar()                            desliga a câmera
//   ArgosQrScanner.ligado()                           a câmera está aberta?

const ArgosQrScanner = (() => {
    // Endereço do jsQR relativo a ESTE arquivo, pra funcionar de qualquer
    // página que o inclua (web/app/, web/painel/, web/ikarus37/).
    const URL_JSQR = new URL('../vendor/jsQR.js', document.currentScript.src).href;
    const FALHAS_ATE_DESISTIR_DO_NATIVO = 15;
    const LADO_MAXIMO_JSQR = 1024;

    let stream = null;
    let video = null;
    let canvas = null;
    let loopId = null;
    let detectorNativo = null;
    let falhasDoNativo = 0;
    let carregandoJsQr = null;
    let aoLer = null;
    let ignorarAte = 0;
    let proximoJsQr = 0;

    function carregarJsQr() {
        if (window.jsQR) return Promise.resolve();
        if (!carregandoJsQr) {
            carregandoJsQr = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = URL_JSQR;
                script.onload = resolve;
                script.onerror = () => { carregandoJsQr = null; reject(new Error('jsQR não carregou')); };
                document.head.appendChild(script);
            });
        }
        return carregandoJsQr;
    }

    async function iniciar(videoEl, onDetectado, onErro) {
        parar();
        video = videoEl;
        aoLer = onDetectado;
        canvas = canvas || document.createElement('canvas');

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            // Fora de HTTPS (ou de localhost) o navegador nem oferece a câmera.
            onErro('Este navegador não liberou a câmera — ela só funciona em endereço seguro (https). Use o campo de colar o conteúdo do QR.');
            return false;
        }

        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
                audio: false,
            });
        } catch (e) {
            onErro('Não foi possível acessar a câmera. Confira a permissão do navegador ou use o campo de colar o conteúdo do QR.');
            return false;
        }

        video.srcObject = stream;
        try {
            await video.play();
        } catch (e) {
            // Alguns navegadores só tocam depois de um gesto; o autoplay do <video> cobre.
        }

        detectorNativo = null;
        falhasDoNativo = 0;
        ignorarAte = 0;
        proximoJsQr = 0;
        if ('BarcodeDetector' in window) {
            // Tem navegador que expõe o BarcodeDetector mas não lê QR (ou não lê nada):
            // só vale se ele disser que suporta.
            try {
                const formatos = await window.BarcodeDetector.getSupportedFormats();
                if (formatos.includes('qr_code')) {
                    detectorNativo = new window.BarcodeDetector({ formats: ['qr_code'] });
                }
            } catch (e) {
                detectorNativo = null;
            }
        }
        if (!stream) return false; // fecharam a câmera enquanto isso

        // O jsQR é carregado sempre: é o plano B se o detector nativo não funcionar.
        try {
            await carregarJsQr();
        } catch (e) {
            if (!detectorNativo) {
                onErro('Não consegui carregar o leitor de QR Code (arquivo vendor/jsQR.js). Use o campo de colar o conteúdo do QR.');
                parar();
                return false;
            }
        }

        procurar();
        return true;
    }

    function lerComJsQr() {
        if (!window.jsQR || !video.videoWidth) return null;
        // Quadro inteiro de câmera de celular é pesado demais pro jsQR; reduzido
        // ele lê do mesmo jeito e a imagem não trava.
        const escala = Math.min(1, LADO_MAXIMO_JSQR / Math.max(video.videoWidth, video.videoHeight));
        canvas.width = Math.round(video.videoWidth * escala);
        canvas.height = Math.round(video.videoHeight * escala);
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        const imagem = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const resultado = window.jsQR(imagem.data, imagem.width, imagem.height, { inversionAttempts: 'dontInvert' });
        return resultado && resultado.data ? resultado.data : null;
    }

    function procurar() {
        cancelarLoop();

        async function passo() {
            if (!stream) return;

            let valor = null;
            if (video.readyState === video.HAVE_ENOUGH_DATA && performance.now() >= ignorarAte) {
                try {
                    if (detectorNativo) {
                        const codigos = await detectorNativo.detect(video);
                        falhasDoNativo = 0;
                        if (codigos.length > 0) valor = codigos[0].rawValue;
                    }
                    // Sem detector nativo o jsQR lê sozinho; com ele, o jsQR confere
                    // de tempos em tempos, pro caso de o nativo não enxergar este QR.
                    if (!valor && stream && performance.now() >= proximoJsQr) {
                        proximoJsQr = performance.now() + (detectorNativo ? 400 : 80);
                        valor = lerComJsQr();
                    }
                } catch (e) {
                    // Detector nativo que existe mas não funciona neste aparelho: troca pro jsQR.
                    if (detectorNativo && ++falhasDoNativo >= FALHAS_ATE_DESISTIR_DO_NATIVO && window.jsQR) {
                        detectorNativo = null;
                    }
                }
            }

            if (!stream) return;
            if (valor && valor.trim() !== '') {
                // Leu: congela a imagem e entrega. Quem chamou decide se confirma ou lê de novo.
                loopId = null;
                video.pause();
                aoLer(valor.trim());
                return;
            }
            loopId = requestAnimationFrame(passo);
        }

        loopId = requestAnimationFrame(passo);
    }

    function retomar() {
        if (!stream) return false;
        // A imagem estava congelada no QR que acabou de ser lido: espera a câmera
        // voltar a andar (e dá tempo de tirar o QR da frente) antes de procurar de novo.
        ignorarAte = performance.now() + 900;
        video.play().catch(() => {});
        procurar();
        return true;
    }

    function cancelarLoop() {
        if (loopId) cancelAnimationFrame(loopId);
        loopId = null;
    }

    function parar() {
        cancelarLoop();
        if (stream) {
            stream.getTracks().forEach((t) => t.stop());
            stream = null;
        }
        if (video) video.srcObject = null;
    }

    function ligado() {
        return stream !== null;
    }

    return { iniciar, retomar, parar, ligado };
})();
