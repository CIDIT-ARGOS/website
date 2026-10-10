---
name: design-por-imagem
description: Cria a direção de design de uma tela ou componente do Argos a partir de imagens de referência (mockups, prints, fotos, logos, esboços). Use quando o usuário mandar imagens e pedir pra criar, melhorar ou redesenhar o visual do sistema, ou pedir "design a partir desta imagem". É a 1ª etapa do fluxo imagem → SVG → site; depois vêm imagem-para-svg e implementar-design.
---

# Design a partir de imagens

Transforma imagens de referência numa especificação de design que dá pra implementar sem adivinhar. Não escreve CSS nem mexe no site — isso é da skill `implementar-design`.

## Entrada

- **Imagens**: caminhos no disco ou coladas na conversa. Leia cada uma com a ferramenta Read (ela mostra a imagem). Sem imagem nenhuma, peça — não invente uma referência.
- **Alvo**: qual tela ou componente. Se não estiver claro, pergunte uma vez. Alvos do Argos:
  - landing — `web/index.html` + `web/css/style.css`
  - Painel de Comando — `web/painel/*.php` (CSS em `<style>` dentro de cada página)
  - Ikarus37 (painel técnico) — `web/ikarus37/*.php` (idem)
  - PWA Área Funcional — `web/app/index.html` + `web/app/css/app.css`

## Passos

1. **Olhe o que existe hoje.** Leia o CSS do alvo e, se der, abra a tela no navegador (`docker compose up -d` → `http://localhost:8080/web/...`) e tire um print. O design novo parte daqui, não do zero.
2. **Leia as referências com atenção.** De cada imagem, tire o que é concreto: cores (em hex, amostradas da imagem), hierarquia tipográfica, espaçamentos, raio de borda, sombras, grade, densidade, o que chama atenção primeiro. Separe o que é *ideia a aproveitar* do que é só detalhe daquela imagem.
3. **Concilie com a identidade do Argos.** O sistema é da EEAR/CIDIT e já tem tokens — mude só o que a referência justifica:
   - cores: `--azul-eear #0a2e5c`, `--accent #2f6fed`, texto `#16202e`, fundo `#f4f7fc`, borda `#dbe3ef`
   - fontes: Barlow Semi Condensed (títulos) e IBM Plex Sans (corpo)
   - ícones: Tabler, monocromáticos, em `web/images/icons/*.svg`, pintados por `mask-image` (herdam a cor do texto)
   - Painel e PWA são usados em serviço, no celular, às vezes com pressa e sol na tela: contraste AA, alvos de toque de 44px ou mais, nada que dependa de hover.
4. **Escreva a especificação** em `docs/design/<alvo>/brief.md`:
   - **Objetivo** — o que melhora e pra quem, em duas ou três frases.
   - **Referências** — cada imagem (copie pra `docs/design/<alvo>/referencias/`) e o que foi aproveitado dela.
   - **Tokens** — tabela antes → depois de cada cor, fonte, raio, sombra e espaçamento que muda. Com o contraste calculado pros pares texto/fundo novos.
   - **Layout** — estrutura da tela no celular (360px) e no desktop, na ordem em que os blocos aparecem.
   - **Componentes** — cada um que muda (botão, cartão, campo, cabeçalho, navegação…), com estados: normal, foco, desabilitado, erro, vazio, carregando.
   - **Ativos a produzir** — lista de logos, ícones e ilustrações que precisam virar SVG, com tamanho de uso e se são monocromáticos. É a entrada da skill `imagem-para-svg`.
   - **Fora do escopo** — o que a referência mostra e *não* vai ser feito agora.
5. **Mostre antes de implementar.** Faça um mockup HTML estático em `docs/design/<alvo>/mockup.html` (CSS embutido, sem depender do backend) com a tela nova, abra no navegador e mande o print pro usuário junto com o resumo das decisões. Espere a aprovação.

## Regras

- Decida. Uma direção bem justificada vale mais que três opções; só ofereça alternativa quando a escolha for realmente de gosto do usuário.
- Não copie marca de terceiros que apareça na referência (logo, ilustração, fonte paga). Aproveite a ideia, não o ativo.
- Dados no mockup: use os alunos fictícios de `database/seed_dev.sql`, nunca nomes reais.
- `docs/` não vai pro servidor (é `export-ignore`), então referências e mockups podem ficar lá à vontade.

## Entrega

`docs/design/<alvo>/brief.md` + `mockup.html` + print, e a lista de ativos pra `imagem-para-svg`. Diga ao usuário qual é o próximo passo.
