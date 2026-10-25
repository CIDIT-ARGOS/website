# Vídeo promocional do Argos

Vídeo e vinheta para a apresentação do Argos na feira de projetos científicos. O roteiro, as decisões e o andamento ficam na [issue 60](https://cosmos.simioni.dev.br/cidit/projeto-argos/issues/60).

Cada peça é uma página HTML com um `<canvas>` onde todo quadro é calculado em código. O `ferramentas/render.mjs` abre a página num navegador sem janela, tira os quadros um a um e monta o MP4 com o ffmpeg. A trilha também é gerada em código (Web Audio), sem música de terceiros. Abrir a página no navegador mostra a prévia em loop; um clique recomeça com som.

| Peça | Arquivo | Duração | Onde entra no roteiro |
|---|---|---|---|
| **A — vídeo da solução** | `peca-a-solucao.html` | 36 s | Parte 2: como resolver |
| **B — vinheta BOOM** | `peca-b-boom.html` | 8 s | Parte 3: o olho, o raio e o logo do CIDIT |

## Renderizar

Precisa de Node 18+, ffmpeg no PATH e Chrome ou Edge instalado.

```bash
cd divulgacao/video
npm install
npm run render:a     # saida/peca-a-solucao.mp4
npm run render:b     # saida/peca-b-boom.mp4
npm run folha:a      # folha de contato (um quadro por segundo) pra revisar
```

Para conferir se os cortes caem no tempo da música:

```bash
node ferramentas/sync-check.mjs saida/peca-a-solucao.mp4 --cues-file saida/peca-a-solucao.cues.json
```

### O que a peça A usa e não está no repositório

- **As telas do sistema** (`build/telas/`): gere com o `scripts/dev/capturar-telas.mjs` a partir do ambiente de demonstração (veja a página Desenvolvimento da Wiki). Os dados são fictícios.
- **As fotos dos cadernos** (`.tmp/video/fotos/caderno-1.jpg` a `caderno-7.jpg`): fotos reais de anotações de alunos, **com nomes, milhões e telefone borrados**. Elas ficam fora do git porque mostram a letra e as anotações de pessoas reais, e o repositório é público. Peça a pasta ao encarregado do projeto.

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
| 5–8 s | O logo do CIDIT entra: "Um projeto do CIDIT · Corpo de Alunos da EEAR" | Acorde aberto e um sino |

O salto de volume no BOOM é proposital; a conferência de sincronia aponta esse corte como "STARTLE".

## Arquivos

| Arquivo | O que é |
|---|---|
| `comum.js` | O que as duas peças usam: tempo e suavizações, o olho do Argos (o mesmo desenho de `web/images/argos-olho.svg`), o raio (o mesmo traçado do *easter egg* do app), molduras de celular e monitor, textos e blocos de som |
| `peca-a-solucao.html`, `peca-b-boom.html` | As peças |
| `ferramentas/render.mjs`, `ferramentas/sync-check.mjs` | Render e conferência de sincronia, adaptados das [skills de animação do iart-ai](https://github.com/iart-ai/javascript-animation-skills) (licença MIT) |
| `saida/` | Vídeos gerados (fora do git) |
