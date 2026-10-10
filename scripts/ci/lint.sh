#!/usr/bin/env bash
# Checagens estáticas rápidas: sintaxe de todo PHP versionado, dos scripts
# bash e dos JSON da PWA.

set -euo pipefail
cd "$(dirname "$0")/../.."

erros=0

echo "== php -l ($(php -r 'echo PHP_VERSION;')) =="
while IFS= read -r arquivo; do
    if ! saida=$(php -l "$arquivo" 2>&1); then
        echo "$saida"
        erros=$((erros + 1))
    fi
done < <(git ls-files '*.php')
echo "$(git ls-files '*.php' | wc -l) arquivos PHP verificados"

echo "== bash -n =="
while IFS= read -r arquivo; do
    bash -n "$arquivo" || erros=$((erros + 1))
done < <(git ls-files '*.sh')

echo "== JSON =="
for arquivo in web/app/manifest.webmanifest; do
    php -r 'json_decode(file_get_contents($argv[1]), false, 512, JSON_THROW_ON_ERROR);' "$arquivo" \
        && echo "$arquivo ok" || erros=$((erros + 1))
done

echo "== Segredos/dados reais versionados =="
for proibido in core/config.php database/seed_db.sql; do
    if git ls-files --error-unmatch "$proibido" >/dev/null 2>&1; then
        echo "::error::$proibido está versionado — remova do git (git rm --cached) e troque as credenciais."
        erros=$((erros + 1))
    fi
done
if git ls-files | grep -E '(^|/)(backup_|reparo_producao_).*\.sql$'; then
    echo "::error::Dump de banco versionado (dados reais de alunos)."
    erros=$((erros + 1))
fi

echo "== Seed de desenvolvimento (QR codes) =="
php scripts/dev/gerar-seed-qrcodes.php --checar || erros=$((erros + 1))

echo "== Migrations =="
php -r '
require "deploy/migracoes.php";
$m = migracoesDisponiveis("database");
echo count($m) . " migrations, última: " . basename(end($m)) . "\n";
' || erros=$((erros + 1))

if [ $erros -gt 0 ]; then
    echo "::error::$erros problema(s) encontrado(s)."
    exit 1
fi
echo "Lint ok."
