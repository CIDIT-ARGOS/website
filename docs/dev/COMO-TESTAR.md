# Como testar o Argos inteiro

Dois jeitos, que se completam: **abrir e usar** (ambiente de demonstração) e **rodar os testes automáticos**.

## 1. Ambiente de demonstração — abrir e usar

Sobe um Argos completo em `http://localhost:8090`, com banco novo e **só dados fictícios**. É separado do `docker-compose.yml` do dia a dia (outra porta, outro volume), então não encosta no seu banco local.

```bash
docker compose -f docker-compose.demo.yml up -d --build
```

Depois, pra as telas terem o que mostrar (chamadas enviadas e pendentes, faltas com motivo):

```bash
docker compose -f docker-compose.demo.yml exec web php scripts/dev/simular-atividade.php http://localhost
```

| Interface | Endereço | Como entrar |
|---|---|---|
| **Área Funcional** (PWA, celular) | http://localhost:8090/web/app/ | QR code de [`qrcodes-seed.html`](qrcodes-seed.html) — abra o arquivo no navegador e use "Copiar código", ou escaneie com o celular |
| **Painel de Comando** | http://localhost:8090/web/painel/ | `dev.ca` (vê tudo) ou `dev.prata` (só o Esquadrão Prata) · senha `ArgosDev@2026` |
| **Ikarus37** | http://localhost:8090/web/ikarus37/ | `admin` · senha inicial que está no `database/init_db.sql` |

Pra ver a PWA em tamanho de celular no computador: F12 → ícone de celular (modo responsivo).
Pra abrir no celular de verdade, use o IP da máquina na rede (`http://192.168.x.x:8090/web/app/`); a câmera só funciona em HTTPS ou `localhost`, então fora disso use "colar código".

### Roteiro (uns 10 minutos)

**Área Funcional**
1. Entre com o código do aluno `ESTEVES` (Esquadrão Prata, esquadrilha B). O cabeçalho mostra pendentes e enviadas hoje.
2. Abra a retirada pendente de Educação Física, marque uma falta (escolha o motivo), volte um aluno pra presente — o placar acima do botão acompanha.
3. Envie a chamada: aparece o protocolo e ela vira "Enviada" na lista.
4. "Abrir nova retirada" → escolha tipo e esquadrilha → a chamada abre com todo mundo presente.
5. Aba **Dispensas** → "Lançar dispensa": escolha o aluno, o período, o motivo e do que ele está dispensado. Ela aparece na lista e, na próxima chamada, o aluno já entra marcado como dispensa médica.
6. Aba **Livro**: o Livro do Dia do esquadrão montado sozinho — faltas por tipo de chamada (com a situação por extenso), "Chamada não enviada" onde ainda falta lançar, e as dispensas. "Copiar texto" ou "Enviar" entrega o texto padronizado pra mandar ao Aluno de Dia ao CA.
7. Aba Efetivo: busque por nome ou milhão.
8. Tente entrar com o código do `WERNECK` (inativo): tem que ser recusado.

**Painel de Comando**
1. Entre como `dev.ca`. A primeira coisa na tela é **Situação do efetivo agora**: quantos estão prontos, quantos em pane e a lista de quem está em pane, com a situação por extenso. A falta que você marcou na PWA já aparece ali.
2. **Retiradas**: a tabela mostra só o essencial; "Ver / editar" abre o painel lateral com o resto e as ações (continuar a chamada, excluir). O mesmo vale pra Usuários, Turmas, Efetivo e Controle do Domínio.
3. **Efetivo**: clique em qualquer número dos quadros (esquadrilha, total, M/F, especialidade) — a listagem embaixo já vem filtrada.
4. **Livro do Dia** → "Baixar PDF" (a impressão não mudou com o redesign); a situação de cada aluno vem por extenso.
5. **Controle do Domínio de Negócio**: a aba selecionada fica em azul cheio com texto branco.
6. Saia e entre como `dev.prata`: só o Esquadrão Prata aparece, e some o que é só do CA (Grupos, por exemplo).

**Ikarus37**
1. Entre como `admin`. **API** mostra as chaves e o log das requisições que a PWA acabou de fazer.
2. **Banco de Dados** lista as tabelas com a contagem; **Usuários** lista as contas do Painel criadas pelo seed.

Recomeçar do zero (apaga o banco de demonstração):

```bash
docker compose -f docker-compose.demo.yml down -v
```

## 2. Testes automáticos

O mesmo script que o CI roda em todo push — migrations, API, telas logadas e smoke:

```bash
DB_HOST=127.0.0.1 DB_ROOT_SENHA=argos_root bash scripts/ci/testes-integracao.sh
```

Precisa de PHP com `mysqli` e de um MySQL 8 acessível. **Atenção:** o script gera um `core/config.php` de teste por cima do que estiver lá — rode numa cópia do repositório ou dentro de um container, não na sua pasta de trabalho se o seu `config.php` for importante. O que cada etapa cobre está em [`docs/CI-CD.md`](../CI-CD.md).

| Arquivo | Testes | Cobre |
|---|---|---|
| `tests/integracao_test.php` | 37 | fluxo da PWA de ponta a ponta |
| `tests/api_test.php` | 81 | cada endpoint e método da API, incluindo dispensas e Livro do Dia |
| `tests/paginas_test.php` | 38 | cada tela logada do Painel e do Ikarus37 abre sem erro e com o visual novo |
| `tests/smoke_producao.php` | 13 | o que roda em produção depois de cada deploy |

Na prática, o jeito mais simples é dar push na `develop` e olhar **Actions** no Forgejo: roda tudo nos dois runners.
