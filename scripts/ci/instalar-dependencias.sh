#!/usr/bin/env bash
# Instala o que os jobs precisam no runner (imagens Debian/Ubuntu — rótulos
# ubuntu-latest e debian do Forgejo). Sempre PHP CLI + mysqli; o resto vem
# por argumento:
#
#   scripts/ci/instalar-dependencias.sh            # testes
#   scripts/ci/instalar-dependencias.sh lftp jq    # deploy

set -euo pipefail

SUDO=""
if [ "$(id -u)" != "0" ]; then
    SUDO="sudo"
fi

export DEBIAN_FRONTEND=noninteractive
$SUDO apt-get update -qq
$SUDO apt-get install -y -qq --no-install-recommends \
    php-cli php-mysql php-mbstring ca-certificates curl git openssl "$@" >/dev/null

echo "Ambiente:"
grep PRETTY_NAME /etc/os-release || true
php -r 'echo "PHP ", PHP_VERSION, PHP_EOL;'
php -r 'exit(extension_loaded("mysqli") ? 0 : 1);' || { echo "::error::Extensão mysqli não carregou."; exit 1; }
echo "mysqli ok"
