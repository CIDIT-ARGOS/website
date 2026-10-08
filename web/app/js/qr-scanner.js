// Leitor de QR Code fino: usa BarcodeDetector nativo quando disponível;
// senão carrega jsQR (CDN) e decodifica frame a frame via canvas. Sem
// dependência de build — só um wrapper em volta de getUserMedia.

const ArgosQrScanner = (() => {
    let stream = null;
    let video = null;
    let canvas = null;
    let loopId = null;
    let barcodeDetector = null;
    let jsQrCarregado = false;

    function carregarJsQr() {
        if (jsQrCarregado) return Promise.resolve();
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/jsQR/1.4.0/jsQR.min.js';
            script.onload = () => { jsQrCarregado = true; resolve(); };
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    async function iniciar(videoEl, onDetectado, onErro) {
        video = videoEl;
        canvas = document.createElement('canvas');

        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'environment' },
                audio: false,
            });
        } catch (e) {
            onErro('Não foi possível acessar a câmera. Use o campo "colar código" abaixo.');
            return;
        }

        video.srcObject = stream;
        await video.play();

        if ('BarcodeDetector' in window) {
            try {
                barcodeDetector = new window.BarcodeDetector({ formats: ['qr_code'] });
            } catch (e) {
                barcodeDetector = null;
            }
        }

        if (!barcodeDetector) {
            try {
                await carregarJsQr();
            } catch (e) {
                onErro('Não foi possível carregar o leitor de QR Code.');
                parar();
                return;
            }
        }

        loop(onDetectado, onErro);
    }

    function loop(onDetectado, onErro) {
        const ctx = canvas.getContext('2d');

        async function passo() {
            if (!stream) return;

            if (video.readyState === video.HAVE_ENOUGH_DATA) {
                try {
                    if (barcodeDetector) {
                        const codigos = await barcodeDetector.detect(video);
                        if (codigos.length > 0) {
                            finalizar(codigos[0].rawValue, onDetectado);
                            return;
                        }
                    } else if (window.jsQR) {
                        canvas.width = video.videoWidth;
                        canvas.height = video.videoHeight;
                        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                        const imagem = ctx.getImageData(0, 0, canvas.width, canvas.height);
                        const resultado = window.jsQR(imagem.data, imagem.width, imagem.height);
                        if (resultado && resultado.data) {
                            finalizar(resultado.data, onDetectado);
                            return;
                        }
                    }
                } catch (e) {
                    // frame ilegível — ignora e tenta o próximo
                }
            }
            loopId = requestAnimationFrame(passo);
        }

        loopId = requestAnimationFrame(passo);
    }

    function finalizar(valor, onDetectado) {
        parar();
        onDetectado(valor.trim());
    }

    function parar() {
        if (loopId) cancelAnimationFrame(loopId);
        loopId = null;
        if (stream) {
            stream.getTracks().forEach((t) => t.stop());
            stream = null;
        }
    }

    return { iniciar, parar };
})();
