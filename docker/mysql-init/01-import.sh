#!/bin/bash
set -e

SRC=/argos-src
DB="$MYSQL_DATABASE"

# Prioriza o backup mais recente (dump completo tirado do Ikarus37 -> Backup do banco,
# com os dados reais de produção) e cai para init_db.sql + seed_db.sql se não houver nenhum.
LATEST_BACKUP=$(ls -t "$SRC"/backup_*.sql 2>/dev/null | head -n1 || true)

if [ -n "$LATEST_BACKUP" ]; then
    echo ">> [argos] Importando backup mais recente: $(basename "$LATEST_BACKUP")"
    mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$DB" < "$LATEST_BACKUP"
elif [ -f "$SRC/init_db.sql" ]; then
    echo ">> [argos] Nenhum backup_*.sql encontrado. Importando init_db.sql"
    mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$DB" < "$SRC/init_db.sql"

    if [ -f "$SRC/seed_db.sql" ]; then
        echo ">> [argos] Importando seed_db.sql"
        mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$DB" < "$SRC/seed_db.sql"
    else
        echo ">> [argos] seed_db.sql não encontrado — banco ficará só com o admin básico do init_db.sql."
    fi
else
    echo ">> [argos] Nenhum backup_*.sql nem init_db.sql encontrado na raiz do projeto. Banco ficará vazio."
fi
