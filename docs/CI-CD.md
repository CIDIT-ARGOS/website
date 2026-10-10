# CI/CD do Argos (Forgejo)

O repositório principal é o Forgejo — `https://cosmos.simioni.dev.br/cidit/projeto-argos`.
O GitHub (`CIDIT-ARGOS/website`) é só espelho.

## Branches

| Branch | Para quê |
|---|---|
| `main` | O que está (ou vai estar) em produção. Só recebe merge de `release` (ou hotfix). |
| `release` | Estabilização antes de ir pra `main`: congela o que vai na próxima versão. |
| `develop` | Integração do dia a dia. Toda feature entra aqui primeiro. |
| `feat/<nome>` | Uma feature/correção. Sai de `develop`, volta por PR pra `develop`. |

Fluxo: `feat/*` → PR → `develop` → PR → `release` → PR → `main` → **tag** → produção.

## Workflows (`.forgejo/workflows/`)

| Workflow | Quando roda | O que faz |
|---|---|---|
| `ci.yml` | push em `main`, `develop`, `release`, `feat/**` e PRs | Lint (sintaxe PHP/bash/JSON, segredos versionados, numeração das migrations) e testes de integração, nos runners `ubuntu-latest` e `debian` |
| `release.yml` | push de tag `vX.Y.Z` (ou manual, pra re-deploy/rollback) | Valida a tag, roda lint + testes, faz o deploy por FTP e cria a Release no Forgejo |
| `smoke-producao.yml` | todo dia 09:00 (Brasília) e manual | Smoke test **somente leitura** contra produção |

Os jobs fazem checkout com `git` puro (sem `actions/checkout`), pra não depender de Node.js na imagem do runner.

### O que os testes cobrem

`scripts/ci/testes-integracao.sh` sobe um MySQL 8 (service do workflow) e:

1. **Migrations, instalação nova** — `init_db.sql` + migrations pendentes; rodar de novo não aplica nada.
2. **Migrations, banco de produção legado** — banco sem `schema_migrations` e sem a 013: a baseline tem que ser detectada como 012, a 013 aplicada, e o esquema final tem que ser idêntico ao da instalação nova.
3. **API + PWA de ponta a ponta** (`tests/integracao_test.php`, 37 testes) — autenticação por chave, login por QR, isolamento entre esquadrões, abrir/marcar/enviar retirada, regra da dispensa médica, arquivos da PWA (manifest, service worker, `env.php`), páginas de login sem erro de PHP.
4. **API endpoint por endpoint** (`tests/api_test.php`, 81 testes) — CRUD administrativo de alunos, grupos e usuários do Painel, relatórios, situação, dispensas médicas, Livro do Dia, casos de erro das retiradas e o que é comum a todos (CORS, `api_logs`, sessão expirada, rate limit por IP). Além do HTTP, mexe direto no banco de teste pra montar cenários que a API não deixa (expirar sessão, simular IP bloqueado).
5. **Telas logadas** (`tests/paginas_test.php`, 38 testes) — entra no Painel e no Ikarus37 como um navegador (formulário + cookie) e abre cada página: tem que responder 200, sem erro de PHP, sem "acesso negado", com a folha de estilo comum e com o rodapé de versão e crédito.
6. **Smoke test** (`tests/smoke_producao.php`) — o mesmo que roda em produção.

Rodar localmente (precisa de um MySQL 8 acessível):

```bash
DB_HOST=127.0.0.1 DB_ROOT_SENHA=argos_root bash scripts/ci/testes-integracao.sh
```

## Lançar uma versão

1. Garanta que o que vai pra produção está na `main` (PR `release` → `main` mergeado).
2. Crie e envie a tag (versão semântica — `MAJOR.MINOR.PATCH`):

   ```bash
   git checkout main && git pull cosmos main
   git tag -a v1.2.0 -m "v1.2.0"
   git push cosmos v1.2.0
   ```

3. Acompanhe em **Actions → Release**. No fim, a Release aparece em **Releases** com as notas (commits desde a tag anterior) e o zip do pacote.

A tag tem que apontar pra um commit que já está na `main` — o workflow recusa o contrário.

### O que o deploy faz (`scripts/deploy/deploy-producao.sh`)

1. **Confere o destino** — só segue se o diretório remoto tiver `core/config.php` e (`api/index.php` ou o marcador `.argos-deploy`). O deploy apaga o conteúdo desse diretório; um `FTP_REMOTE_DIR` errado não pode virar um desastre.
2. **Liga a manutenção** — sobe um `.htaccess` que manda tudo pra `manutencao.html` e, como o servidor pode ignorar `.htaccess`, também troca as páginas de entrada (`index.html`, `web/index.html`, `web/app/index.html`, `web/painel/index.php`, `web/ikarus37/index.php`) pela página de manutenção.
3. **Sobe o endpoint temporário `_deploy/`** — a "ponte" até o banco, que não aceita conexão de fora. Protegido por um token aleatório gerado só pra esse deploy (o servidor guarda só o sha256). Junto sobem as migrations da versão.
4. **Backup completo do banco** em `_backups/` no servidor (mantém os 10 mais recentes). Cada arquivo começa com `<?php http_response_code(404); exit; ?>`, então não dá pra baixar pelo navegador mesmo se o `.htaccess` for ignorado.
5. **Apaga a versão anterior** — tudo, menos `core/config.php`, `_backups/`, os arquivos de manutenção e `.user.ini`/`.well-known`/`error_log`.
6. **Sobe a versão nova** (menos as páginas de entrada, que continuam em manutenção).
7. **Roda as migrations pendentes.**
8. **Confere a API** furando a manutenção com o token.
9. **Abre o site** — sobe o `.htaccess` e as páginas de entrada reais, apaga `manutencao.html` e `_deploy/`.
10. **Smoke test** em produção, já como um usuário comum.

Se falhar **antes do passo 5**, a manutenção é desfeita e produção continua na versão anterior.
Se falhar **depois**, o site fica em manutenção de propósito (melhor que meio código novo no ar) e o `_deploy/` é removido.

### Rollback

**Actions → Release → Run workflow** → informe a tag anterior (ex: `v1.1.0`). É o mesmo deploy, com aquela versão.

Migrations **não** são desfeitas no rollback. Se o banco também precisar voltar:

1. Baixe por FTP o backup em `_backups/` (o nome tem a versão e a data do deploy).
2. Apague a primeira linha do arquivo (`<?php http_response_code(404); exit; ?>`).
3. Importe pelo phpMyAdmin do servidor.

## Configuração no Forgejo

**Configurações do repositório → Actions → Secrets:**

| Secret | Exemplo | Uso |
|---|---|---|
| `FTP_HOST` | `ftp.sigsaeear.com` | Servidor FTP |
| `FTP_USER` | | Usuário FTP |
| `FTP_PASSWORD` | | Senha FTP |
| `FTP_REMOTE_DIR` | `/public_html/cidit/projetos/11` | Diretório do projeto **no FTP** (o que contém `api/`, `core/`, `web/`) |
| `PROD_URL` | `https://sigsaeear.com/cidit/projetos/11` | URL pública da mesma pasta, sem barra no fim |

**→ Variables** (opcionais):

| Variável | Padrão | Uso |
|---|---|---|
| `FTP_PORT` | `21` | |
| `FTP_TLS` | `nao` | `sim` pra FTPS explícito, se o servidor passar a aceitar |
| `DB_BASELINE` | detecção automática | Força a baseline das migrations na 1ª vez (ver abaixo) |
| `DEPLOY_PRESERVAR` | | Caminhos extras que o deploy não pode apagar, separados por espaço |
| `FTP_LFTP_OPCOES` | | Comandos `set` extras do lftp, ex: `set ftp:ignore-pasv-address yes;` |

O `core/config.php` de produção **não** vem do repositório — é preservado em todo deploy. Ele precisa ter o `$APP_PWA_API_KEY` preenchido (chave gerada em Ikarus37 → API), senão a PWA não autentica; o smoke test acusa isso.

## Migrations do banco

- Mudança de esquema = **um arquivo novo** `database/migration_NNN_descricao.sql`, com `NNN` maior que o da última. Comece com `SET NAMES utf8mb4;`.
- **Nunca edite** uma migration que já foi pra produção — crie outra.
- `database/init_db.sql` é o retrato do banco até a migration 013 e **não muda mais**. Instalação nova = `init_db.sql` + migrations pendentes.
- A tabela `schema_migrations` registra o que já rodou (`origem = baseline` pras que já estavam lá antes do controle existir, `deploy` pras aplicadas pelo deploy).

### Primeira vez em produção

Produção não tem `schema_migrations`. No primeiro deploy a baseline (última migration já aplicada) é detectada pelo que cada migration criou — ex: se a tabela `turmas` existe, a 013 já rodou. O passo "Situação do banco antes do deploy" do log mostra a baseline detectada e as pendentes **antes** de alterar qualquer coisa.

Se a detecção estiver errada, defina a variável `DB_BASELINE` com o número certo e rode de novo. Depois do primeiro deploy a variável não tem mais efeito e pode ser apagada.

### Docker local

```bash
docker compose exec web php deploy/migrar.php status
docker compose exec web php deploy/migrar.php migrar
```

## Espelho no GitHub

Em **Configurações do repositório → Repositório → Espelhos (push)**, adicione
`https://github.com/CIDIT-ARGOS/website.git` com um token do GitHub (permissão `contents: write`).

O push mirror do Forgejo é um espelho **exato**: branches que só existem no GitHub (ex: `dev/santana`, `feat/livro-de-dia`) **são apagadas** na primeira sincronização. Traga pro Forgejo o que precisar ser mantido antes de ativar.
