# Segurança da API do Argos

Como a API decide quem pode fazer o quê. O código que aplica isso é `api/bootstrap.php`; os testes que provam são `tests/api_test.php` (seções "Comum a todos os endpoints" e "Posto de serviço da sessão").

## O modelo, em três regras

1. **Toda requisição traz uma chave de API** (`X-API-Key`), e toda chave tem um **escopo**:

   | Escopo | Serve pra | Onde a chave fica |
   |---|---|---|
   | `app` | as rotas do app do aluno — **sempre junto com a sessão do aluno** | no `core/config.php` (`APP_PWA_API_KEY`); chega ao navegador de quem abre a PWA |
   | `admin` | também os endpoints administrativos | só em servidor de integração — nunca numa página nem num aplicativo distribuído |

2. **As rotas do app exigem também a sessão do aluno** — o token devolvido por `POST /api/sessao.php` (login pelo QR code), enviado em `X-Session-Token` ou em `Authorization: Bearer <token>`. O token vale 16h e só o hash dele fica no banco.

3. **Os endpoints administrativos exigem chave `admin`.** Com chave `app` respondem `403`.

A chave do app é pública por natureza: qualquer pessoa que abre a PWA consegue lê-la. Por isso ela, sozinha, não pode dar acesso a nada — é o princípio do menor privilégio. Antes desta revisão toda chave valia pra API inteira, e com a chave do app dava pra criar um usuário do Painel.

## Quem pode o quê

| Endpoint | Chave `app` + sessão | Chave `admin` |
|---|---|---|
| `POST sessao.php` (login) | chave basta — é o que cria a sessão | sim |
| `GET/PUT servico.php`, `GET motivos.php`, `GET postos_servico.php`, `GET/POST dispensas.php`, `GET/PUT/POST livro.php` | sim | só com sessão |
| `GET alunos.php` | sim, só o esquadrão de serviço | só com sessão |
| `POST/PUT/DELETE alunos.php` | **403** | sim |
| `GET retiradas.php` | sim, só o esquadrão de serviço | sim, tudo |
| `POST/PUT retiradas.php`, `GET/PUT retirada_itens.php` | sim, só o esquadrão de serviço | só com sessão |
| `DELETE retiradas.php` | **403** | sim |
| `grupos.php`, `painel_usuarios.php`, `relatorios.php`, `situacao.php` | **403** | sim |

"Só o esquadrão de serviço": a sessão guarda em qual esquadrão/esquadrilha o aluno está de serviço (`PUT /api/servico.php`), e é esse esquadrão que limita o que ele lê e lança — vale pra listar, pra buscar por `id` e pra gravar. Pedir o `id` de um registro de outro esquadrão dá `403`/`404`, não o registro.

### Livro do Dia: função e validação

Junto com o posto, a sessão guarda a **função** do aluno no serviço (`funcao` em `PUT /api/servico.php`): `esquadrilha`, `esquadrao` ou `ca`. É ela que decide qual livro `livro.php` entrega e o que ele pode fazer:

| Função | Livro dele | Recebe e valida |
|---|---|---|
| Aluno de Dia à Esquadrilha | o da esquadrilha de serviço | — |
| Aluno de Dia ao Esquadrão | o do esquadrão de serviço | os das esquadrilhas desse esquadrão |
| Aluno de Dia ao Corpo de Alunos | o do Corpo de Alunos | os dos esquadrões |

- Situações: `rascunho` → `enviado` → `validado`, ou `enviado` → `devolvido` (com motivo) → `enviado`.
- Só o dono altera o livro (as alterações e observações), e só em `rascunho` ou `devolvido`; depois de enviado dá `409`.
- Validar ou devolver livro que ele não recebe dá `403`/`404`; livro que não está `enviado` dá `409`.
- Ao enviar, o texto do livro é guardado como está — é esse texto que é validado, mesmo que uma chamada mude depois.
- O livro do CA não tem quem valide: enviado é final, e só o Aluno de Dia ao CA pode reabrir.
- Fica registrado quem enviou, quem validou ou devolveu, e quando.

Como o posto, a função é declarada pelo próprio aluno ao entrar — provisório, até existir integração com a escala de serviço. Regras em `core/livro_fluxo_core.php`.

## Padrões seguidos

| Referência | O que foi aplicado |
|---|---|
| OWASP API Security Top 10 (2023) — API1, autorização por objeto | toda leitura e escrita por `id` confere se o registro é do esquadrão de serviço da sessão |
| OWASP API5, autorização por função | endpoints administrativos separados por escopo de chave |
| OWASP API2, autenticação | chave e token aleatórios de 256 bits, guardados só como hash SHA-256; sessão com validade; chave revogável em Ikarus37 → API |
| OWASP API4, consumo de recursos | bloqueio por IP depois de 20 respostas `401` em 5 minutos; limite de tamanho em todo campo de texto |
| RFC 7235 / RFC 6750 | `401` + `WWW-Authenticate` quando falta credencial válida; `403` + `error="insufficient_scope"` quando a credencial vale mas não tem permissão; token aceito em `Authorization: Bearer` |
| RFC 6585 | `429` + `Retry-After` no bloqueio por excesso de tentativas |
| Cabeçalhos | `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer` em toda resposta |
| CORS | aberto (`*`) de propósito: a API não usa cookie, a credencial vai sempre em cabeçalho explícito, então outra origem não consegue aproveitar a sessão de ninguém |

Toda requisição fica em `api_logs` (chave, endpoint, método, status, IP), visível em Ikarus37 → API.

## O que muda pra quem já usa

- **A PWA continua igual**: mesmos cabeçalhos, mesmo login. Toda chave que já existia virou `app` na migration 015.
- **Integração que usava os endpoints administrativos** precisa de uma chave nova de escopo `admin`: Ikarus37 → API → "Nova chave" → escopo admin.
- `GET /api/retiradas.php` sem sessão deixou de responder pra chave `app` (passa a dar `401`).

## Limites conhecidos

- **O posto de serviço é escolhido pelo próprio aluno.** Qualquer aluno com QR válido pode se dizer de serviço em qualquer esquadrão e ver as chamadas e dispensas dele. É assim de propósito enquanto não há integração com a escala de serviço; a escolha fica registrada na sessão (`api_sessoes.esquadrao_servico`).
- **O QR code é a única credencial do aluno.** Quem copiar o QR entra como ele. O banco guarda só a impressão digital (sha256) do conteúdo, nunca o conteúdo; cadastrar ou trocar o QR de alguém é restrito a quem pode editar o efetivo (Painel) ou ao administrador técnico (Ikarus37), e trocar derruba as sessões abertas com o QR antigo. Um QR comprometido só se resolve cadastrando outro.
- **O token fica no `localStorage` do navegador** — some ao sair, mas um script malicioso na página conseguiria lê-lo. A PWA não carrega script de terceiros além do leitor de QR.
