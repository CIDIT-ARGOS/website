#!/usr/bin/env node
// Monta o vídeo completo: a peça A, a vinheta B continuando dela e o banner continuando da
// vinheta, num MP4 só, com a música de verdade no lugar da trilha sintetizada. O vídeo termina
// parado no banner, e o banner também sai em PNG (saida/argos-banner.png).
//
//   node ferramentas/montar.mjs [música]      (rodar dentro de divulgacao/video)
//
// Como a música casa com a imagem:
//   - a peça A foi feita a 120 BPM; ela é acelerada pra 130 BPM (o andamento da música), e assim
//     todo corte dela cai numa batida;
//   - o rock entra (a 1ª pancada forte) no corte do caderno pro sistema;
//   - na vinheta, a música para numa batida logo antes do "respiro" e, uma batida depois, no BOOM,
//     entra a última pancada da música, cujo acorde soa até o fim, sobre o ARGOS e o logo do CIDIT;
//   - por cima, os efeitos das páginas (rabiscos, toques, piscadas, trovão), sem a trilha sintetizada.
import { execFileSync } from 'node:child_process';
import { existsSync, writeFileSync, mkdirSync, renameSync } from 'node:fs';

const MUSICA = process.argv[2] || '../../.tmp/Nocturinii - Free Rock Intro music - 1 minute (no copyright).opus';

// Medido na música (Nocturinii, "Free Rock Intro music"): se trocar a música, meça de novo.
const BATIDA = 0.46153;     // 130 BPM
const ENTRADA = 2.540;      // 1ª pancada forte (antes dela, só a introdução baixinha)
const FINAL = 54.232;       // última pancada; depois dela o acorde vai sumindo até ~61 s

// Das páginas
const FPS = 30;
const ESCALA = 0.5 / BATIDA;            // a peça A foi feita com meia batida = 0,5 s (120 BPM)
const DURACAO_A = 36;                   // no tempo da peça A
const CORTES_A = [4, 10, 16, 22, 24, 28, 32, 34];
const IMPACTO_B = 3 + 7 / FPS;          // o raio chega no chão (peca-b-boom.html)
const RESPIRO_B = 2.7;                  // a tela começa a escurecer antes do BOOM
// A vinheta continua a peça A: pula o começo dela (o olho abrindo) e entra com o olho já aceso,
// no ponto em que o BOOM fica 3 batidas depois da emenda — assim ele cai numa batida.
const INICIO_B = IMPACTO_B - 3 * BATIDA;
// Depois da vinheta, o banner (banner.html): entra em 2,6 s e fica parado no fim do vídeo.
const DURACAO_BANNER = 2.6 + 4;
const DURACAO_B = 9.5;

if (!existsSync(MUSICA)) { console.error(`música não encontrada: ${MUSICA}`); process.exit(1); }
mkdirSync('saida', { recursive: true });

const rodar = (cmd, args) => execFileSync(cmd, args, { stdio: 'inherit' });
const render = (pagina, saida, query) => rodar('node', ['ferramentas/render.mjs', pagina, saida, '--som', 'EFEITOS', ...(query ? ['--query', query] : [])]);

render('peca-a-solucao.html', 'saida/a-com-musica.mp4', `escala=${ESCALA}&continua`);
render('peca-b-boom.html', 'saida/b-com-musica.mp4', `inicio=${INICIO_B}&relogio=${DURACAO_A - INICIO_B}&semfim`);

const duracaoA = Math.round(DURACAO_A / ESCALA * FPS) / FPS;   // = FRAMES da página / FPS
const duracaoB = Math.round((DURACAO_B - INICIO_B) * FPS) / FPS;
// o banner continua a grade do fundo de onde a vinheta parou
render('banner.html', 'saida/banner-video.mp4', `relogio=${DURACAO_A + duracaoB}`);
// e o banner parado, em PNG, pra usar sozinho (telão, slides)
const ultimoQuadro = Math.round(DURACAO_BANNER * FPS) - 1;
rodar('node', ['ferramentas/render.mjs', 'banner.html', 'saida/argos-banner', '--stills', String(ultimoQuadro), '--png']);
renameSync(`saida/argos-banner-${String(ultimoQuadro).padStart(4, '0')}.png`, 'saida/argos-banner.png');
const total = duracaoA + duracaoB + DURACAO_BANNER;
const primeiroCorte = CORTES_A[0] / ESCALA;
const atraso = primeiroCorte - ENTRADA;            // onde o 0 da música fica no vídeo
if (atraso < 0) { console.error('a música começaria antes do vídeo'); process.exit(1); }
// a música para na primeira batida depois que o respiro começa
const batidas = k => primeiroCorte + k * BATIDA;
let k = 0;
while (batidas(k) < duracaoA + RESPIRO_B - INICIO_B) k++;
const silencio = batidas(k);
const impacto = duracaoA + IMPACTO_B - INICIO_B;
console.log(`peça A: ${duracaoA.toFixed(3)} s (escala ${ESCALA.toFixed(4)}); música entra em ${atraso.toFixed(3)} s`);
console.log(`música para em ${silencio.toFixed(3)} s; BOOM em ${impacto.toFixed(3)} s (${((impacto - silencio) / BATIDA).toFixed(2)} batida depois)`);

const ms = s => Math.round(s * 1000);
const corpo = silencio - atraso;   // trecho da música até a parada
const filtro = [
    '[0:v][1:v][3:v]concat=n=3:v=1:a=0[v]',
    `[2:a]atrim=0:${corpo.toFixed(3)},asetpts=PTS-STARTPTS,afade=t=out:st=${(corpo - 0.06).toFixed(3)}:d=0.06,adelay=${ms(atraso)}:all=1[corpo]`,
    `[2:a]atrim=start=${(FINAL - 0.012).toFixed(3)},asetpts=PTS-STARTPTS,afade=t=in:d=0.006,adelay=${ms(impacto - 0.012)}:all=1[final]`,
    '[0:a]volume=0.35[efeitosA]',
    `[1:a]volume=0.8,adelay=${ms(duracaoA)}:all=1[efeitosB]`,
    `[corpo][final][efeitosA][efeitosB]amix=inputs=4:normalize=0:duration=longest,atrim=0:${total.toFixed(3)},afade=t=out:st=${(total - 0.8).toFixed(3)}:d=0.8,alimiter=limit=0.85:level=disabled[a]`,
].join(';');
const saida = 'saida/argos-video-completo.mp4';
rodar('ffmpeg', ['-y', '-loglevel', 'error', '-i', 'saida/a-com-musica.mp4', '-i', 'saida/b-com-musica.mp4', '-i', MUSICA, '-i', 'saida/banner-video.mp4',
    '-filter_complex', filtro, '-map', '[v]', '-map', '[a]', '-t', total.toFixed(3),
    '-c:v', 'libx264', '-crf', '18', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '192k', '-movflags', '+faststart', saida]);

// cortes pra conferência de sincronia: os da peça A e o BOOM
const cues = [...CORTES_A.map(c => c / ESCALA), impacto];
writeFileSync('saida/argos-video-completo.cues.json', JSON.stringify(cues));
console.log(`pronto: ${saida} (${total.toFixed(2)} s) e saida/argos-banner.png`);
console.log('confira: node ferramentas/sync-check.mjs saida/argos-video-completo.mp4 --cues-file saida/argos-video-completo.cues.json');
