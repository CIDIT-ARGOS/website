---
name: imagem-para-svg
description: Converte uma imagem (logo, ícone, ilustração, brasão, elemento de um mockup) em SVG limpo e otimizado pro site do Argos. Use quando o usuário pedir pra vetorizar, "transformar em SVG", redesenhar um PNG/JPG como vetor, ou quando o brief de design-por-imagem listar ativos a produzir. É a 2ª etapa do fluxo imagem → SVG → site.
---

# Imagem → SVG

Produz um SVG de verdade — formas desenhadas, não um bitmap embrulhado em `<image>`.

## Antes de desenhar: isso deve virar vetor?

| A imagem é… | Faça |
|---|---|
| logo, brasão, ícone, tipografia, ilustração chapada, diagrama | SVG |
| foto, captura de tela, textura, ilustração com degradê pintado | **não vetorize** — entregue PNG/WebP otimizado no tamanho de uso e diga por quê |

Se o ícone pedido já existe no Tabler (o conjunto que o projeto usa em `web/images/icons/`), use o do Tabler em vez de redesenhar.

## Passos

1. **Leia a imagem** com a ferramenta Read e descreva pra você mesmo a construção: formas básicas, simetrias, eixos, quantas cores, espessura de traço, o que é texto.
2. **Escolha o método:**
   - **Redesenhar à mão** (padrão pra ícones, logos geométricos, até uns 30 elementos): escreva o SVG com `<path>`, `<circle>`, `<rect>` numa grade inteira. Dá o arquivo menor e mais limpo.
   - **Traçar por ferramenta** (formas orgânicas, brasões detalhados): veja se há `vtracer`, `potrace` ou `inkscape` instalado (`Get-Command`); se houver, trace e depois limpe o resultado à mão. Se não houver, redesenhe simplificando e avise que o detalhe fino foi reduzido — não instale nada sem perguntar.
3. **Escreva o SVG seguindo o padrão do projeto:**
   - `viewBox` começando em `0 0`, sem `width`/`height` fixos (o CSS decide o tamanho). Ícones: `viewBox="0 0 24 24"`, traço 2, pontas e junções arredondadas, igual ao Tabler.
   - **Monocromático** (ícones, marcas d'água): `stroke="currentColor"` ou `fill="currentColor"`, nenhuma cor fixa. O projeto pinta ícones com `mask-image`, que só usa a forma.
   - **Colorido** (logos, ilustrações): só as cores da paleta do brief; até 6. Se a cor precisa mudar por tema, exponha com `fill="var(--nome, #fallback)"`.
   - Texto vira contorno só se a fonte não for uma das do site (Barlow Semi Condensed, IBM Plex Sans); senão use `<text>`.
   - `<title>` com a descrição quando a imagem tem significado; `aria-hidden="true"` no uso quando é decoração.
   - Sem `<image>`, sem base64, sem `<script>`, sem fonte embutida, sem metadados de editor, sem `id` que não seja usado. Coordenadas com no máximo 2 casas decimais.
4. **Confira contra o original.** Monte uma página temporária no scratchpad com a referência e o SVG lado a lado (em 24px, 64px e 256px, em fundo claro e escuro), abra no navegador e tire o print. Compare proporção, alinhamento e peso. Ajuste e repita até bater — no mínimo uma rodada de comparação antes de entregar.
5. **Otimize.** Se `svgo` estiver disponível, rode; senão limpe à mão (junte paths de mesma cor, tire grupos vazios). Meta: ícone < 1 KB, logo < 10 KB. Passou muito disso, simplifique.
6. **Salve no lugar certo:**
   - ícone de interface → `web/images/icons/<nome-em-kebab>.svg`
   - logo, brasão, ilustração → `web/images/<nome>.svg`
   - ícone da PWA (tela inicial do celular) continua PNG em `web/app/icons/` — gere o PNG a partir do SVG nos tamanhos do `manifest.webmanifest`.

## Regras

- Não troque nem apague o PNG original nesta etapa; quem troca as referências no HTML é a `implementar-design`. Diga quanto o arquivo encolheu (o `logo-cidit.png` tem 4,6 MB, por exemplo).
- Brasões e símbolos oficiais (EEAR, FAB, CIDIT) têm que ficar fiéis: não "melhore" proporção, cor ou elemento heráldico. Na dúvida sobre um detalhe ilegível na imagem, pergunte.
- Nome de arquivo em minúsculas, com hífen, sem acento.

## Entrega

O(s) arquivo(s) `.svg`, o print da comparação com o original, o tamanho antes/depois e onde cada um deve ser usado. Próximo passo: `implementar-design`.
