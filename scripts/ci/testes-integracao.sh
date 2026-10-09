#!/usr/bin/env bash
# Testes que precisam de banco: migrations + API/PWA de ponta a ponta.
# Espera um MySQL 8 acessível (no CI: service "mysql" do workflow).
#
#   DB_HOST=mysql DB_ROOT_SENHA=argos_root scripts/ci/testes-integracao.sh

set -euo pipefail
cd "$(dirname "$0")/../.."

export DB_HOST="${DB_HOST:-mysql}"
export DB_ROOT_SENHA="${DB_ROOT_SENHA:-argos_root}"
PORTA_WEB="${PORTA_WEB:-8000}"
BANCO=php\ tests/ci_banco.php

etapa() { echo; echo "==> $*"; }

etapa "Esperando o MySQL"
$BANCO esperar

etapa "Gerando core/config.php de teste (a partir do config.example.php)"
# Usa o example de propósito: se alguém esquecer de levar uma função/variável
# nova pro example, o teste quebra aqui e não em produção.
sed -e "s|^\$DB_HOST = .*|\$DB_HOST = getenv('DB_HOST');|" \
    -e "s|^\$DB_NOME = .*|\$DB_NOME = getenv('DB_NOME') ?: 'argos_teste';|" \
    -e "s|^\$DB_USUARIO = .*|\$DB_USUARIO = 'root';|" \
    -e "s|^\$DB_SENHA = .*|\$DB_SENHA = getenv('DB_ROOT_SENHA');|" \
    -e "s|^\$APP_PWA_API_KEY = .*|\$APP_PWA_API_KEY = 'd0cf67c0a3b8464aacb2ed36f01b1fbb2af8d7623d804c3aa5eef732c6b3f058';|" \
    core/config.example.php > core/config.php
grep -q "getenv('DB_NOME')" core/config.php || { echo "::error::config.example.php mudou de formato — ajuste o sed deste script."; exit 1; }

# ---------------------------------------------------------------------
etapa "Migrations — instalação nova (init_db.sql + pendentes)"
$BANCO recriar argos_mig_nova
$BANCO importar argos_mig_nova database/init_db.sql
DB_NOME=argos_mig_nova php deploy/migrar.php status
DB_NOME=argos_mig_nova php deploy/migrar.php migrar
segunda=$(DB_NOME=argos_mig_nova php deploy/migrar.php migrar)
echo "$segunda" | php -r '$r = json_decode(stream_get_contents(STDIN), true); exit($r["ok"] && $r["aplicadas_agora"] === [] ? 0 : 1);' \
    || { echo "::error::Rodar migrar duas vezes aplicou algo de novo: $segunda"; exit 1; }
echo "Segunda execução não aplicou nada (ok)."

etapa "Migrations — banco de produção sem tabela de controle e sem a 013"
# Simula produção atual: banco montado à mão, sem schema_migrations, que
# ainda não recebeu a migration 013 (turmas). A baseline tem que ser
# detectada como 012 e a 013 aplicada, chegando no mesmo esquema do init_db.
$BANCO recriar argos_mig_legado
$BANCO importar argos_mig_legado database/init_db.sql
$BANCO remover-fk argos_mig_legado alunos turma_id
$BANCO sql argos_mig_legado "
    ALTER TABLE alunos DROP INDEX idx_turma_id, DROP COLUMN turma_id;
    DROP TABLE turmas;
    DELETE cp FROM cargo_permissoes cp JOIN permissoes p ON p.id = cp.permissao_id WHERE p.chave = 'gerenciar_turmas';
    DELETE FROM permissoes WHERE chave = 'gerenciar_turmas';"
status=$(DB_NOME=argos_mig_legado php deploy/migrar.php status)
echo "$status"
echo "$status" | php -r '$s = json_decode(stream_get_contents(STDIN), true); exit($s["baseline"] === 12 && $s["pendentes"][0] === "migration_013_turmas.sql" ? 0 : 1);' \
    || { echo "::error::Baseline do banco legado deveria ser 12 com a 013 pendente."; exit 1; }
DB_NOME=argos_mig_legado php deploy/migrar.php migrar
if ! diff <($BANCO esquema argos_mig_nova) <($BANCO esquema argos_mig_legado); then
    echo "::error::Banco legado migrado ficou com esquema diferente de init_db.sql + migrations (diff acima)."
    exit 1
fi
echo "Esquema do banco legado migrado = esquema da instalação nova (ok)."

# ---------------------------------------------------------------------
etapa "Banco dos testes de integração"
$BANCO recriar argos_teste
$BANCO importar argos_teste database/init_db.sql
DB_NOME=argos_teste php deploy/migrar.php migrar
$BANCO importar argos_teste tests/fixtures/dados_teste.sql

etapa "Subindo o servidor PHP embutido na porta $PORTA_WEB"
DB_NOME=argos_teste php -d display_errors=1 -d error_reporting="E_ALL & ~E_DEPRECATED" \
    -S 127.0.0.1:$PORTA_WEB -t . tests/roteador_servidor.php > /tmp/argos-php-server.log 2>&1 &
PID_SERVIDOR=$!
trap 'kill $PID_SERVIDOR 2>/dev/null || true' EXIT
for _ in $(seq 1 30); do
    curl -fsS "http://127.0.0.1:$PORTA_WEB/api/index.php" >/dev/null 2>&1 && break
    sleep 0.5
done

falhou=0
etapa "Testes de integração (API + PWA)"
php tests/integracao_test.php "http://127.0.0.1:$PORTA_WEB" || falhou=1

etapa "Smoke test (o mesmo que roda em produção)"
php tests/smoke_producao.php "http://127.0.0.1:$PORTA_WEB" || falhou=1

if [ $falhou -ne 0 ]; then
    echo
    echo "== Log do servidor PHP =="
    tail -n 80 /tmp/argos-php-server.log
    exit 1
fi
