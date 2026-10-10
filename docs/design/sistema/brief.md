# Brief de design — PWA, Painel de Comando e Ikarus37

Feito com a skill `design-por-imagem` em 10/10/2026. Um brief só pros três alvos porque a ideia é justamente eles passarem a ser **um sistema com três rostos**, não três sites.

## Objetivo

Hoje as três interfaces funcionam, mas parecem protótipo: fonte do sistema operacional, tudo cinza-claro, nenhuma marca, e cada página repete o próprio CSS. O objetivo é dar a elas a identidade que os mockups já prometiam na landing — azul de comando, títulos condensados, cartões com hierarquia clara — e deixar óbvio em qual das três você está:

| Interface | Quem usa | Onde | Rosto |
|---|---|---|---|
| **Área Funcional** (PWA) | aluno de serviço | celular, em pé, com pressa | cabeçalho azul com números do dia, cartões grandes, tudo ao alcance do polegar |
| **Painel de Comando** | comando e encarregados — quem tem direito de ver os dados | celular e desktop | claro, sóbrio, denso o bastante pra tabela e gráfico |
| **Ikarus37** | administradores técnicos | desktop | o mesmo sistema em tema escuro, com cara de console |

## Referências

| Imagem | O que foi aproveitado |
|---|---|
| `web/images/hero-app.png` (mockup da tela inicial do app) | Cabeçalho em azul chapado com a palavra **ARGOS** espaçada; dois blocos de número translúcidos dentro do cabeçalho; lista de cartões brancos com um "selo" à esquerda, título forte, linha de detalhe cinza e pílula de estado à direita; rótulos de seção em maiúsculas espaçadas; cartão de destaque em azul mais escuro |
| `web/images/tela-login.png` (mockup do login) | Brasão no topo, ARGOS grande e espaçado, subtítulo em maiúsculas pequenas, campos altos com raio generoso, botão azul de largura total com sombra, assinatura da escola no rodapé |
| `web/images/logo-especialista.png` | A engrenagem com estrela aparece nos dois mockups (canto do cabeçalho, rodapé do login) — vira SVG pra usar pequena |
| `web/images/argos_corpo_inteiro-removebg-preview.png` | O olho, que cobre o Argos Panoptes, vira a marca do sistema ao lado da palavra ARGOS |
| Prints do estado atual (Painel, Situação, PWA, Ikarus37) | Estrutura e conteúdo de cada tela — nada muda de lugar nem de nome |

Não aproveitado: o campo "Identidade / SARAM" + senha do mockup de login (o app entra por QR code), os horários nos cartões (a retirada não tem horário marcado, tem tipo) e o menu hambúrguer (são só três destinos, cabem na barra inferior).

## Tokens

| Token | Antes | Depois | Por quê |
|---|---|---|---|
| Azul principal | `--accent #2f6fed` em botões; `--azul-eear #0a2e5c` só em cabeçalho de tabela | `--azul #17468c` (botões, cabeçalho, links) · `--azul-escuro #0f3472` · `--azul-claro #e9f0fb` | É o azul dos mockups. Branco sobre ele: 9,0:1 |
| Fundo | `#f4f7fc` | `#f4f6fb` | igual ao mockup |
| Texto | `#16202e` / `#55637a` | `#16202e` / `#5b6b82` | apagado sobre o fundo: 5,0:1 |
| Borda | `#dbe3ef` | `#e1e7f0` | mais leve, o cartão passa a se destacar pela sombra |
| Pendente | `#fff4d6` / `#8a6300` | `#fdecd8` / `#a8500c` | laranja do mockup — 5,3:1 |
| Ok / erro | `#1f8a4c` / `#c0392b` | `#17794a` / `#b3261e`, com fundos `#e4f5ec` / `#fbe9e7` | um tom mais escuro pra passar AA em texto pequeno |
| Fonte | Segoe UI (do sistema) | **Barlow Semi Condensed** 600/700 em títulos, números e rótulos · **IBM Plex Sans** no corpo | as mesmas da landing |
| Raio | 6–10px | 10px (campo, botão) · 14px (cartão) · 999px (pílula) | |
| Sombra | nenhuma | `0 1px 2px rgba(15,52,114,.06), 0 6px 20px rgba(15,52,114,.06)` | |
| Altura de toque | 30–36px | 44px no celular | |

**Ikarus37** usa os mesmos nomes com outros valores: fundo `#0d1320`, cartão `#151d2e`, borda `#26324a`, texto `#e6ebf5` / `#93a1ba`, azul `#5b93f5` (sobre o fundo: 6,4:1). Código, chaves e rotas em fonte monoespaçada.

## Layout

**Área Funcional (360px)**
1. Cabeçalho azul: marca + ARGOS, quem está logado embaixo; na tela de retiradas, dois blocos de número (pendentes · enviadas hoje).
2. Rótulo de seção + data.
3. Cartões de retirada: selo com o tipo abreviado (1ªJ, 2ªJ, EF, PN), título, detalhe, pílula de estado.
4. Botão "Abrir nova retirada" fixo acima da barra inferior.
5. Barra inferior: Retiradas · Efetivo · Sair.

Login: brasão, ARGOS, subtítulo, botão de escanear, campo de colar o código, assinatura no rodapé.
Chamada: cada aluno é um cartão com Presente/Falta em dois botões de 44px; na falta abre motivo e observação; "Enviar chamada" fixo embaixo com o placar (presentes · faltas).

**Painel e Ikarus37**
1. Barra superior azul (Painel) ou escura (Ikarus37) com marca, ARGOS, quem está logado e sair.
2. Título da página + descrição.
3. Cartões: filtros, números (KPI), tabelas, formulários.
No celular os cartões ocupam a largura toda e as tabelas rolam na horizontal dentro do cartão. Desktop: largura máxima de 1200px.

## Componentes

| Componente | Especificação | Estados |
|---|---|---|
| Botão principal | fundo `--azul`, texto branco 600, raio 10, 40px (44 no celular) | foco: anel de 3px `--azul` a 35% · desabilitado: 55% de opacidade · `danger`: vermelho · `ghost`: contorno |
| Campo | fundo branco, borda 1px, raio 10, 40px | foco: borda `--azul` + anel · somente leitura: fundo `--fundo` |
| Cartão | fundo branco, borda, raio 14, sombra | os cartões-link da home sobem 2px no hover e ganham borda azul |
| Número (KPI) | Barlow 700 30px + rótulo em maiúsculas 11px | cor só quando significa algo (verde presente, vermelho falta) |
| Tabela | cabeçalho `--azul-claro` com texto azul em maiúsculas pequenas; só linhas horizontais | linha inativa a 55% · hover com fundo `--fundo` |
| Pílula / badge | raio 999, 11px 600 maiúsculas | pendente (laranja), enviada/ativo (verde), falta (vermelho), neutro (cinza) |
| Mensagem | bloco com fundo colorido e borda à esquerda | `.ok` verde · `.erro` vermelho |
| Vazio | texto apagado centralizado com 28px de respiro | — |
| Carregando (PWA) | "Carregando…" apagado no lugar da lista | — |

## Ativos a produzir (entrada da `imagem-para-svg`)

| Ativo | Origem | Uso | Cor |
|---|---|---|---|
| `web/images/argos-olho.svg` | olho do mascote | marca ao lado de ARGOS nas barras (20px) e no login (56px) | monocromático |
| `web/images/especialista.svg` | `logo-especialista.png` (147 KB) | rodapé do login (22px) e login do Ikarus37 (64px) | tons de cinza |

O brasão da EEAR continua em PNG: é heráldica detalhada, redesenhar à mão perderia fidelidade.

## Fora do escopo

- Mudar fluxo, texto ou o que cada tela mostra — é só a camada visual.
- Tema escuro pro Painel e pra PWA.
- Redesenhar a landing (já segue essa identidade).
- Trocar os gráficos do Chart.js.
- Vetorizar o brasão da EEAR e o logo do CIDIT.

## Como foi implementado

Em vez de reescrever o `<style>` de 28 páginas, o Painel e o Ikarus37 passam a carregar uma folha comum, `web/css/argos-admin.css`, **depois** do CSS da página — ela redefine os tokens e os componentes que todas compartilham (`.topbar`, `.card`, `table`, `button`, `.badge`…). O Ikarus37 carrega também `web/css/argos-ikarus.css`, que só troca os tokens pro tema escuro. A PWA tem o próprio `web/app/css/app.css`.

## Resultado

Prints em `docs/design/sistema/prints/` (dados fictícios do seed de desenvolvimento), já com os ajustes da segunda rodada descrita no fim deste documento.

Diferenças em relação a este brief:
- Os cartões-link da home ficaram só com o ícone em azul, sem o quadrado de fundo: o ícone é pintado por `mask-image`, que recorta qualquer fundo do próprio elemento.
- O botão "Abrir nova retirada" da PWA fica colado acima da barra inferior (como o "Enviar chamada"), em vez de fixo flutuando.

## Segunda rodada (10/10/2026) — mais institucional

Pedidos do encarregado depois de ver a primeira versão:

- **Marca escrita:** é `A R G (olho) S` — o olho entra no lugar do O. Vale em todo lugar onde o nome aparece (barras, logins, PWA, landing). No código é `<span class="marca-argos">ARG<i class="olho"></i>S</span>`, e o olho é o mesmo `web/images/argos-olho.svg`.
- **Tom institucional:** azul EEAR `#0a2e5c` na barra e nos títulos, botões em `#123c7a`, filete dourado `#c9a227` embaixo da barra e na lateral do painel, cantos de 6px (eram 10–14px), sombra quase nula, badges retangulares, título de página com filete azul, sem gradiente no login.
- **Tabelas enxutas:** só as colunas essenciais na tabela; "Ver / editar" abre um painel lateral com todos os campos e as ações. É `web/js/argos-admin.js` + o atributo `data-enxuta` na `<table>` — o PHP das páginas não mudou, os campos da linha é que vão pro painel.
- **Rodapé:** em todas as páginas, a instituição, a versão publicada e o crédito — *Desenvolvido pelos membros do CIDIT — Esquadrão Prata · Turma Ikarus 37*.
- **Aba selecionada** (Controle do Domínio de Negócio): fundo cheio com texto claro, vindos de tokens diferentes, pra nunca coincidirem (era o defeito da primeira versão: texto azul sobre fundo azul).
