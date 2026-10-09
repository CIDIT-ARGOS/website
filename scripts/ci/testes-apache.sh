#!/usr/bin/env bash
# Sobe o pacote de release num Apache de verdade (mod_rewrite + .htaccess
# ligados), num SUBDIRETÓRIO igual ao de produção, e roda o smoke test.
#
# Existe porque o servidor embutido do PHP (usado em testes-integracao.sh)
# ignora .htaccess — e foi justamente um .htaccess que derrubou o deploy
# da v1.0.0 (loop de rewrite em subdiretório, HTTP 500 em tudo).
#
# Espera o banco argos_teste já preparado por testes-integracao.sh.

set -euo pipefail
cd "$(dirname "$0")/../.."

SUBDIR="/cidit/projetos/11"
DESTINO="/var/www/html$SUBDIR"
BASE="http://127.0.0.1$SUBDIR"

SUDO=""
[ "$(id -u)" = "0" ] || SUDO="sudo"

etapa() { echo; echo "==> $*"; }

etapa "Instalando Apache + mod_php"
export DEBIAN_FRONTEND=noninteractive
$SUDO apt-get install -y -qq --no-install-recommends apache2 libapache2-mod-php >/dev/null
$SUDO a2enmod -q rewrite headers >/dev/null
$SUDO tee /etc/apache2/conf-available/argos-ci.conf >/dev/null <<'CONF'
ServerName localhost
<Directory /var/www/html>
    # Como em produção: index.html não é página padrão (a raiz dava 403).
    DirectoryIndex index.php
    AllowOverride All
    Require all granted
</Directory>
CONF
$SUDO a2enconf -q argos-ci >/dev/null

etapa "Publicando o pacote de release em $SUBDIR"
bash scripts/release/gerar-pacote.sh v0.0.0 /tmp/argos-pacote >/dev/null
$SUDO rm -rf "$DESTINO"
$SUDO mkdir -p "$DESTINO"
$SUDO cp -a /tmp/argos-pacote/. "$DESTINO/"
# Mesmo config.php dos testes de integração, apontando pro banco argos_teste
# (o Apache não herda variáveis de ambiente, então os valores vão fixos).
sed -e "s|getenv('DB_HOST')|'$DB_HOST'|" \
    -e "s|getenv('DB_NOME') ?: 'argos_teste'|'argos_teste'|" \
    -e "s|getenv('DB_ROOT_SENHA')|'$DB_ROOT_SENHA'|" \
    core/config.php | $SUDO tee "$DESTINO/core/config.php" >/dev/null
$SUDO chown -R www-data:www-data /var/www/html

$SUDO apachectl start
for _ in $(seq 1 30); do
    curl -fsS -o /dev/null "$BASE/api/index.php" 2>/dev/null && break
    sleep 0.5
done

falhou=0
checar() { # checar <descrição> <caminho> <status esperado>
    local status
    status=$(curl -s -o /dev/null -w '%{http_code}' "$BASE$2")
    if [ "$status" = "$3" ]; then
        echo "  ok    $1 ($2 → $status)"
    else
        echo "  FALHA $1 ($2 → $status, esperava $3)"
        falhou=1
    fi
}

etapa "Rotas do .htaccess em subdiretório"
checar "raiz"                       "/"                        200
checar "landing"                    "/web/index.html"          200
checar "CSS da landing servida na raiz" "/css/style.css"         200
checar "URL curta do Painel"        "/painel/index.php"        200
checar "URL curta da PWA"           "/app/index.html"          200
checar "API direta"                 "/api/index.php"           200
checar "core/ bloqueado"            "/core/config.php"         403
checar "core/ bloqueado (pasta)"    "/core/"                   403
checar "_backups/ bloqueado"        "/_backups/"               403
checar "inexistente vira 404"       "/nao-existe.php"          404

etapa "Smoke test no Apache"
php tests/smoke_producao.php "$BASE" v0.0.0 || falhou=1

if [ $falhou -ne 0 ]; then
    echo
    echo "== error.log do Apache =="
    $SUDO tail -n 40 /var/log/apache2/error.log
    exit 1
fi
