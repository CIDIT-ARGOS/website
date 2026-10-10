---
name: implementar-design
description: Implementa no site do Argos um design já definido — aplica o brief de docs/design/, os tokens de CSS e os SVGs produzidos — na landing, no Painel, no Ikarus37 ou na PWA, e confere o resultado no navegador. Use quando o usuário pedir pra "aplicar o design", "colocar o SVG no site", "implementar o mockup" ou melhorar o visual de uma tela com base num design aprovado. É a 3ª etapa do fluxo imagem → SVG → site.
---

# Implementar o design no site

Leva pro código o que as skills `design-por-imagem` (brief e mockup) e `imagem-para-svg` (ativos) produziram. Se não houver brief aprovado em `docs/design/<alvo>/`, pare e rode a `design-por-imagem` antes — implementar direto da imagem pula a decisão.

## Onde fica cada coisa

| Alvo | HTML | CSS |
|---|---|---|
| Landing | `web/index.html` | `web/css/style.css` (tokens no `:root`) |
| PWA Área Funcional | `web/app/index.html` | `web/app/css/app.css` (tokens no `:root`) |
| Painel de Comando | `web/painel/*.php` | `<style>` no topo de cada página |
| Ikarus37 | `web/ikarus37/*.php` | `<style>` no topo de cada página |

Imagens em `web/images/`, ícones em `web/images/icons/`. Sem framework, sem build, sem npm — é HTML, CSS e PHP puros, e continua assim.

## Passos

1. **Leia o brief e o código atual** do alvo inteiro antes de editar. Anote o que já usa os tokens e o que tem cor/medida solta.
2. **Tokens primeiro.** Aplique as mudanças de cor, fonte, raio, sombra e espaçamento nas variáveis do `:root`; depois troque valores soltos pelas variáveis. O mesmo token tem que ter o mesmo valor na landing e na PWA (hoje `--accent`, `--azul-eear` e os tons de texto/borda se repetem nos dois arquivos).
3. **Ativos.** Troque PNG por SVG onde o brief manda:
   - imagem de conteúdo → `<img src="..." alt="..." width height>` (com dimensões, pra não dar salto de layout);
   - ícone de interface → o padrão que já existe: `<span class="i" style="--icon-url:url('...')">`, pintado por `mask-image`;
   - só apague o PNG antigo depois de confirmar com `Grep` que nada mais aponta pra ele (README, manifest, `sw.js`, outras páginas).
4. **Componentes e layout**, na ordem do brief. Mobile primeiro (360px), depois desktop. Mantenha o HTML semântico e os `id`/`class` que o JavaScript usa (`web/js/main.js`, `web/app/js/app.js`).
5. **Confira no navegador.** Suba o ambiente (`docker compose up -d` → `http://localhost:8080`) e, em cada tela alterada:
   - print em 360px e em desktop, comparado com o mockup;
   - console sem erro e nenhuma requisição 404 (imagem ou CSS com caminho errado);
   - foco visível navegando por Tab; contraste AA nos pares novos;
   - estados do brief: vazio, erro, carregando, desabilitado.
   Pra entrar na PWA use um QR de `docs/dev/qrcodes-seed.html` (seed de desenvolvimento). Corrija o que divergir e confira de novo.
6. **Rode os testes** — `scripts/ci/lint.sh` e `scripts/ci/testes-integracao.sh` (ver `docs/CI-CD.md`). Eles pegam o que quebra em produção: arquivo da PWA que não existe, caminho absoluto, erro de PHP numa página.

## Armadilhas deste projeto

- **Caminhos sempre relativos.** Em produção o site fica num subdiretório (`/cidit/projetos/11/`); `src="/images/..."` ou `url(/css/...)` funciona local e quebra lá. O teste de integração reprova caminho absoluto no manifest e na API.
- **Service worker da PWA.** Arquivo novo que a PWA usa offline entra em `ARQUIVOS_SHELL` no `web/app/sw.js`; mudou CSS, JS ou ícone da PWA, suba a versão do cache (`CACHE_NOME`) no mesmo arquivo, senão quem já instalou continua vendo o visual antigo.
- **Ícones da PWA** (`web/app/icons/*.png`) e o `manifest.webmanifest` andam juntos: mudou a cor de tema ou o ícone, atualize `theme_color`/`background_color` e os três PNGs.
- **Painel e Ikarus37 repetem CSS por página.** Uma mudança de componente vale pra todas as páginas que têm aquele componente — procure com `Grep` e aplique em todas, senão o sistema fica com dois visuais.
- **Fontes** vêm do Google Fonts por `<link>`; fonte nova precisa do `<link>` em cada página que usa, só com os pesos usados.
- **Nada de dado real** em print, mockup ou commit: use os alunos fictícios do seed.

## Entrega

- Commits pequenos na `develop`, um por tela ou componente, citando a issue (`Refs #N`).
- Prints antes/depois (mobile e desktop) na resposta ao usuário e na issue.
- O que ficou diferente do brief e por quê, se algo ficou.
