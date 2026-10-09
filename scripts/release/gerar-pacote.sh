#!/usr/bin/env bash
# Monta a pasta com exatamente o que vai pro servidor de produção.
#
#   scripts/release/gerar-pacote.sh v1.2.0 [pasta-de-saida]
#
# Usa `git archive`, então só entra o que está commitado — nada de
# config.php, backups, seed com dados reais ou icons-source/ (gitignorados).
# O que é só de desenvolvimento (workflows, Docker, testes, scripts) é
# excluído via `export-ignore` no .gitattributes.
#
# Gera core/versao.php com a versão, o commit e a data da release — é o que
# aparece no rodapé do Painel e do Ikarus37.

set -euo pipefail

VERSAO="${1:?Informe a versão, ex: v1.2.0}"
SAIDA="${2:-build/pacote}"

if ! [[ "$VERSAO" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "::error::Versão '$VERSAO' fora do padrão vMAJOR.MINOR.PATCH (ex: v1.2.0)."
    exit 1
fi

COMMIT="$(git rev-parse --short=7 HEAD)"
DATA="$(TZ=America/Sao_Paulo date +%F)"

rm -rf "$SAIDA"
mkdir -p "$SAIDA"
git archive --format=tar HEAD | tar -x -C "$SAIDA"

cat > "$SAIDA/core/versao.php" <<PHP
<?php
// Gerado por scripts/release/gerar-pacote.sh no deploy — não edite no servidor.
return [
    'versao' => '$VERSAO',
    'commit' => '$COMMIT',
    'data' => '$DATA',
];
PHP

for proibido in core/config.php database .forgejo tests docker deploy; do
    if [ -e "$SAIDA/$proibido" ]; then
        echo "::error::$proibido não deveria estar no pacote de produção."
        exit 1
    fi
done

echo "Pacote $VERSAO ($COMMIT, $DATA) em $SAIDA — $(find "$SAIDA" -type f | wc -l) arquivos."
