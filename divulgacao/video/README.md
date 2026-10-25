# Vídeo promocional do Argos

Vídeo e vinheta para a apresentação do Argos na feira de projetos científicos. O roteiro, as decisões e o andamento ficam na [issue 60](https://cosmos.simioni.dev.br/cidit/projeto-argos/issues/60).

Cada peça é uma página HTML com um `<canvas>` onde todo quadro é calculado em código. O `ferramentas/render.mjs` abre a página num navegador sem janela, tira os quadros um a um e monta o MP4 com o ffmpeg. A trilha também é gerada em código (Web Audio), sem música de terceiros. Abrir a página no navegador mostra a prévia em loop; um clique recomeça com som.

| Peça | Arquivo | Duração | Onde entra no roteiro |
|---|---|---|---|
| **A — vídeo da solução** | `peca-a-solucao.html` | 36 s (33,2 s no vídeo completo) | Parte 2: como resolver |
| **B — vinheta BOOM** | `peca-b-boom.html` | 9,5 s | Parte 3: o olho, o raio e o logo do CIDIT |

**O vídeo da apresentação é um arquivo só:** a peça A e, emendada no fim dela, a vinheta B, com a música *Free Rock Intro music* (Nocturinii, sem direitos autorais), em `saida/argos-video-completo.mp4` (40,9 s). Nele a vinheta **continua** a peça A: o olho do fim da A sobe e cresce até o lugar em que está na vinheta, e a vinheta entra já com esse olho aceso (pulando a própria abertura), sem apagar nem cortar.

## Renderizar

Precisa de Node 18+, ffmpeg no PATH e Chrome ou Edge instalado.

```bash
cd divulgacao/video
npm install
npm run montar       # saida/argos-video-completo.mp4 — A + B com a música (o vídeo da apresentação)
npm run render:a     # saida/peca-a-solucao.mp4 (só a peça A, com a trilha sintetizada)
npm run render:b     # saida/peca-b-boom.mp4
npm run folha:a      # folha de contato (um quadro por segundo) pra revisar
```

Para conferir se os cortes caem no tempo da música:

```bash
node ferramentas/sync-check.mjs saida/peca-a-solucao.mp4 --cues-file saida/peca-a-solucao.cues.json
```

### Como a música casa com a imagem (`ferramentas/montar.mjs`)

A música tem 130 BPM. A peça A foi feita a 120 BPM e, no vídeo completo, roda 130/120 mais rápida, então todo corte dela cai numa batida. Medidas da música, anotadas no script:

- **A introdução baixinha** toca sob os cadernos. A primeira pancada forte (2,54 s da música) entra no corte do caderno para o sistema: o problema em silêncio, a solução com o rock.
- **Na emenda**, a vinheta entra no ponto em que o BOOM fica 3 batidas depois.
- **No respiro da vinheta**, a música para numa batida.
- **Uma batida depois, no BOOM**, entra a última pancada da música (54,23 s), e o acorde final soa sobre o ARGOS e o logo do CIDIT.
- **Os efeitos das páginas** (rabiscos, toques, piscadas, trovão) entram por cima. A trilha sintetizada fica só nas peças avulsas.

Se trocar a música, meça de novo o andamento, a primeira pancada forte e a última, e ajuste as constantes no começo do `montar.mjs`. Na conferência de sincronia, a entrada do rock aparece como "STARTLE" (é proposital), e o corte de 31,4 s como "OFF-BEAT": ele está na batida, mas o detector prefere uma pancada mais forte da virada, logo antes.

### O que não está no repositório

- **As telas do sistema** (`build/telas/`): gere com o `scripts/dev/capturar-telas.mjs` a partir do ambiente de demonstração (veja a página Desenvolvimento da Wiki). Os dados são fictícios.
- **As fotos dos cadernos** (`.tmp/video/fotos/caderno-1.jpg` a `caderno-7.jpg`): fotos reais de anotações de alunos, **com nomes, milhões e telefone borrados**. Elas ficam fora do git porque mostram a letra e as anotações de pessoas reais, e o repositório é público. Peça a pasta ao encarregado do projeto.
- **A música** (`.tmp/Nocturinii - Free Rock Intro music - 1 minute (no copyright).opus`): também fica fora do git; outro caminho pode ser passado ao `montar.mjs` (`node ferramentas/montar.mjs caminho/da/musica`).

## Roteiro quadro a quadro

Andamento de 120 BPM: cada compasso tem 2 s, e todo corte cai no começo de um compasso.

### Peça A — vídeo da solução (36 s)

| Tempo | Cena | O que acontece | Palavra na tela |
|---|---|---|---|
| 0–4 s | Os cadernos | As fotos dos cadernos caem na mesa, uma a uma; uma linha vermelha varre a tela e leva para o sistema | Anotado à mão · Passado a limpo · Repassado por mensagem |
| 4–10 s | Entrada e chamada | O aluno toca em *Escanear QR Code*, o leitor lê a identidade, aparece a chamada pronta e ele marca uma falta | **Tecnologia** |
| 10–16 s | Motivo e envio | Um toque escolhe o motivo; a chamada enviada aparece com o protocolo destacado | **Eficiência** |
| 16–22 s | O livro sobe | Os três livros (esquadrilha → esquadrão → Corpo de Alunos) aparecem em degraus, com as setas de validação | **Controle de informações** |
| 22–28 s | Painel de Comando | A situação do efetivo agora; depois, zoom nos gráficos | **Integração** |
| 28–34 s | Tudo ligado | App, Painel e Ikarus37 ligados ao olho do Argos, com pulsos correndo nas linhas; no fim, tudo entra no olho | **Conectar os sistemas** · **Inovação** |
| 34–36 s | Fim | O olho pisca em vermelho e apaga, e quem apresenta segue a fala | — |

### Peça B — vinheta BOOM (8 s)

| Tempo | O que acontece | Som |
|---|---|---|
| 0–1,1 s | Tela escura; o olho do Argos aparece fechado | Zumbido grave crescendo |
| 1,1–2,7 s | O olho abre, acende em vermelho e pisca duas vezes | Estalos das piscadas e a carga subindo |
| 2,75–3,0 s | Respiro: tudo escurece | Silêncio |
| 3,0–3,23 s | O raio sai do olho e cai até o chão | Estalo do raio |
| 3,23 s | **BOOM**: clarão, tremor, onda de choque e faíscas | Bumbo e trovão |
| 3,5–5 s | O olho vira o "O" do letreiro **ARGOS** | Acorde que acende |
| 5–9,5 s | O logo do CIDIT entra: "Um projeto do CIDIT · Corpo de Alunos da EEAR" | Acorde aberto e um sino |

O salto de volume no BOOM é proposital; a conferência de sincronia aponta esse corte como "STARTLE".

## Arquivos

| Arquivo | O que é |
|---|---|
| `comum.js` | O que as duas peças usam: tempo e suavizações, o olho do Argos (o mesmo desenho de `web/images/argos-olho.svg`), o raio (o mesmo traçado do *easter egg* do app), molduras de celular e monitor, textos e blocos de som |
| `peca-a-solucao.html`, `peca-b-boom.html` | As peças |
| `ferramentas/montar.mjs` | Monta o vídeo completo: A + B + a música + os efeitos |
| `ferramentas/render.mjs`, `ferramentas/sync-check.mjs` | Render e conferência de sincronia, adaptados das [skills de animação do iart-ai](https://github.com/iart-ai/javascript-animation-skills) (licença MIT) |
| `saida/` | Vídeos gerados (fora do git) |
