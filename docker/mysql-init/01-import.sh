#!/bin/bash
set -e

SRC=/argos-src/database
DB="$MYSQL_DATABASE"

importar() {
    mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$DB" < "$1"
}

# Ambiente de demonstração (docker-compose.demo.yml): só dados fictícios, mesmo
# que existam backup_*.sql ou seed_db.sql (dados reais) na pasta.
if [ "${ARGOS_SO_SEED_DEV:-}" = "1" ]; then
    echo ">> [argos] ARGOS_SO_SEED_DEV=1 — importando init_db.sql + seed_dev.sql (só dados fictícios)"
    importar "$SRC/init_db.sql"
    importar "$SRC/seed_dev.sql"
    exit 0
fi

# Prioriza o backup mais recente (dump completo tirado do Ikarus37 -> Backup do banco,
# com os dados reais de produção) e cai para init_db.sql + seed_db.sql se não houver nenhum.
# Sem seed_db.sql (dados reais, fora do git), entra o seed_dev.sql (fictício, versionado).
LATEST_BACKUP=$(ls -t "$SRC"/backup_*.sql 2>/dev/null | head -n1 || true)

if [ -n "$LATEST_BACKUP" ]; then
    echo ">> [argos] Importando backup mais recente: $(basename "$LATEST_BACKUP")"
    importar "$LATEST_BACKUP"
elif [ -f "$SRC/init_db.sql" ]; then
    echo ">> [argos] Nenhum backup_*.sql encontrado. Importando init_db.sql"
    importar "$SRC/init_db.sql"

    if [ -f "$SRC/seed_db.sql" ]; then
        echo ">> [argos] Importando seed_db.sql"
        importar "$SRC/seed_db.sql"
    elif [ -f "$SRC/seed_dev.sql" ]; then
        # Sem dados reais: alunos fictícios com QR code (docs/dev/qrcodes-seed.html),
        # pra dar pra logar na PWA.
        echo ">> [argos] seed_db.sql não encontrado. Importando seed_dev.sql (alunos fictícios com QR code)"
        importar "$SRC/seed_dev.sql"
    else
        echo ">> [argos] Nenhum seed encontrado — banco ficará só com o admin básico do init_db.sql."
    fi
else
    echo ">> [argos] Nenhum backup_*.sql nem init_db.sql encontrado em database/. Banco ficará vazio."
fi
