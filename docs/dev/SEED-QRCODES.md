# Seed de desenvolvimento: alunos fictícios com QR code

Pra testar o login da PWA "Área Funcional" num banco local sem depender de dados reais.

| Arquivo | O que é |
|---|---|
| `database/seed_dev.sql` | 25 alunos fictícios (3 esquadrões × 2 esquadrilhas × 4, mais 1 inativo), cada um com `qrcode_hash` fixo |
| [`docs/dev/qrcodes-seed.html`](qrcodes-seed.html) | Folha com o QR code de cada um — abra no navegador pra escanear com o celular, copiar o código ou imprimir |
| `scripts/dev/gerar-seed-qrcodes.php` | Gera os dois arquivos acima |

**Nunca carregue o `seed_dev.sql` em produção** — os hashes estão no repositório, qualquer um logaria como esses alunos. O arquivo não entra no pacote de release (`database/` é `export-ignore`).

## Usar

**Docker local, banco novo:** se não houver `database/backup_*.sql` nem `database/seed_db.sql` (os dados reais, fora do git), o `seed_dev.sql` é importado sozinho na criação do banco.

```bash
docker compose down -v && docker compose up -d
```

**Banco que já existe:** carregue por cima. Pode rodar mais de uma vez — quem já existe só tem o `qrcode_hash` e o `ativo` restaurados.

```bash
docker compose exec -T db mysql -uroot -pargos_root argos < database/seed_dev.sql
```

Depois abra `http://localhost:8080/web/app/`, toque em escanear e aponte pra um QR da folha (ou use "Copiar código" e cole no campo de login). O aluno `WERNECK` é inativo de propósito: o login dele tem que ser recusado.

## Logins de desenvolvimento

O seed também cria usuários do Painel, pra ver as duas visões:

| Onde | Usuário | Senha | Vê |
|---|---|---|---|
| Painel (`/web/painel/`) | `dev.ca` | `ArgosDev@2026` | todo o Corpo de Alunos |
| Painel (`/web/painel/`) | `dev.prata` | `ArgosDev@2026` | só o Esquadrão Prata |
| Ikarus37 (`/web/ikarus37/`) | `admin` | a do `database/init_db.sql` | painel técnico |
| Área Funcional (`/web/app/`) | — | QR code da folha | o esquadrão do aluno |

## Mudar o efetivo de teste

Edite as listas no começo de `scripts/dev/gerar-seed-qrcodes.php` (esquadrões, esquadrilhas, nomes) e regenere:

```bash
docker compose exec web php scripts/dev/gerar-seed-qrcodes.php
```

Commite o script junto com os dois arquivos gerados — o lint do CI (`--checar`) falha se eles estiverem desatualizados.

O `qrcode_hash` de cada aluno é `sha256("argos-seed-dev:" + identidade_militar)`, então os QR codes não mudam quando o banco é recriado.

## Como o QR é lido

O QR code carrega o próprio `qrcode_hash` (64 caracteres hexadecimais). A PWA lê e manda em `POST /api/sessao.php`; a API devolve o token de sessão do aluno.
