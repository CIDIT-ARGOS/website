#!/usr/bin/env bash
# Deploy do Argos em produção por FTP — chamado pelo workflow de release
# (.forgejo/workflows/release.yml), mas roda igual na mão de qualquer Linux
# com lftp, curl, jq e php-cli.
#
# Fluxo:
#   1. confere se o diretório remoto é mesmo o Argos (nunca apaga às cegas)
#   2. liga a manutenção (.htaccess + páginas de entrada trocadas) e sobe o
#      endpoint temporário _deploy/ com um token só deste deploy
#   3. backup completo do banco → _backups/ no servidor
#   4. apaga os arquivos antigos (preserva config.php, _backups/, manutenção)
#      — inclusive database/, que não faz mais parte do pacote
#   5. sobe a versão nova (menos as páginas de entrada)
#   6. roda as migrations pendentes
#   7. confere a API (furando a manutenção com o token)
#   8. sobe .htaccess e páginas de entrada reais, remove manutenção e _deploy/
#   9. smoke test final já sem o token
#
# Se falhar ANTES do passo 4: desfaz a manutenção, produção segue na versão
# antiga. Se falhar DEPOIS: o site fica em manutenção (de propósito — melhor
# que meio código novo no ar) e o log diz o que fazer.
#
# Variáveis obrigatórias:
#   FTP_HOST, FTP_USER, FTP_PASSWORD, FTP_REMOTE_DIR
#   PROD_URL   URL pública da raiz do projeto (ex: https://sigsaeear.com/cidit/projetos/11)
#   VERSAO     ex: v1.2.0
#   PACOTE_DIR pasta com os arquivos da release (scripts/release/gerar-pacote.sh)
# Opcionais:
#   FTP_PORT (21), FTP_TLS (nao|sim), DB_BASELINE (força a baseline das
#   migrations na 1ª vez), BACKUPS_MANTER (10), DEPLOY_PRESERVAR (caminhos
#   extras a não apagar, separados por espaço), FTP_LFTP_OPCOES (comandos
#   "set" extras do lftp, ex: "set ftp:ignore-pasv-address yes;" atrás de NAT)

set -euo pipefail

for var in FTP_HOST FTP_USER FTP_PASSWORD FTP_REMOTE_DIR PROD_URL VERSAO PACOTE_DIR; do
    if [ -z "${!var:-}" ]; then
        echo "::error::Variável $var não definida (configure os secrets do repositório)."
        exit 1
    fi
done

RAIZ_REPO="$(cd "$(dirname "$0")/../.." && pwd)"
PROD_URL="${PROD_URL%/}"
FTP_PORT="${FTP_PORT:-21}"
BACKUPS_MANTER="${BACKUPS_MANTER:-10}"
TRABALHO="$(mktemp -d)"
DEPLOY_TOKEN="$(openssl rand -hex 32)"
echo "::add-mask::$DEPLOY_TOKEN"

# Páginas que o usuário abre primeiro — ficam com a página de manutenção do
# começo ao fim, porque o .htaccess de manutenção pode não ter efeito no
# servidor (sem mod_rewrite/AllowOverride).
PONTOS_ENTRADA=(index.html web/index.html web/app/index.html web/painel/index.php web/ikarus37/index.php)

# Nada disto é apagado na limpeza.
PRESERVAR=(core/config.php _backups _deploy .htaccess manutencao.html .argos-deploy .user.ini .well-known error_log "${PONTOS_ENTRADA[@]}")
# shellcheck disable=SC2206
PRESERVAR+=(${DEPLOY_PRESERVAR:-})

export LFTP_PASSWORD="$FTP_PASSWORD"
if [ "${FTP_TLS:-nao}" = "sim" ]; then
    LFTP_TLS="set ftp:ssl-force yes; set ftp:ssl-protect-data yes;"
else
    LFTP_TLS="set ftp:ssl-allow no;"
fi

ftp() {
    lftp -c "set cmd:fail-exit yes; set net:max-retries 3; set net:reconnect-interval-base 5; set net:timeout 30; set ftp:passive-mode on; set ftp:list-options -a; $LFTP_TLS ${FTP_LFTP_OPCOES:-}
open --env-password -u \"$FTP_USER\" -p $FTP_PORT \"$FTP_HOST\"
cd \"$FTP_REMOTE_DIR\"
$1"
}

etapa() { echo; echo "==> $*"; }

existe_remoto() { # existe_remoto <caminho relativo> — lista o diretório pai
    # (cls direto num arquivo falha em alguns servidores, ex: vsftpd)
    local pai nome
    pai="$(dirname "$1")"; nome="$(basename "$1")"
    ftp "cls -1a \"$pai\"" 2>/dev/null | sed 's|/$||; s|.*/||' | grep -xF "$nome" >/dev/null  # sem -q: com pipefail, -q gera SIGPIPE
}

endpoint() { # endpoint <metodo> <acao> [query extra]
    local saida
    saida="$TRABALHO/resposta.json"
    local status
    status=$(curl -sS -o "$saida" -w '%{http_code}' -X "$1" --max-time 600 \
        -H "X-Deploy-Token: $DEPLOY_TOKEN" \
        "$PROD_URL/_deploy/index.php?acao=$2${3:-}") || status=000
    if ! jq . "$saida" 2>/dev/null; then
        echo "Resposta não-JSON (HTTP $status):"
        head -c 2000 "$saida" || true
        echo
        return 1
    fi
    [ "$status" = "200" ] && [ "$(jq -r '.ok' "$saida")" = "true" ]
}

# ---------------------------------------------------------------------
FASE=preparacao
limpar_ao_sair() {
    local codigo=$?
    if [ $codigo -ne 0 ]; then
        echo
        echo "::error::Deploy de $VERSAO falhou na fase '$FASE'."
        case "$FASE" in
            preparacao|verificacao)
                echo "Nada foi alterado no servidor." ;;
            manutencao|backup)
                echo "Desfazendo a manutenção — produção continua na versão anterior."
                restaurar_originais || echo "::error::Não consegui desfazer a manutenção! Suba de volta os arquivos de $TRABALHO/originais por FTP." ;;
            smoke_config)
                echo "O site está NO AR com $VERSAO e funcionando, mas falta configuração no servidor"
                echo "(itens CONFIG do smoke test acima). Ajuste e confira em:"
                echo "Actions → Smoke test produção → Run workflow." ;;
            abertura|smoke)
                echo "::error::O site foi reaberto com a versão nova e não passou no smoke test — religando a manutenção."
                ftp "mirror -R --no-perms --overwrite --exclude '^_deploy/' \"$TRABALHO/manutencao\" .; rm -rf _deploy" >/dev/null 2>&1 \
                    || echo "::error::Não consegui religar a manutenção!"
                echo "Banco já migrado; backup deste deploy em _backups/ no servidor."
                echo "Opções: corrigir e lançar uma nova tag, ou re-deployar a versão anterior"
                echo "(Actions → Release → Run workflow → tag anterior)." ;;
            *)
                ftp "rm -rf _deploy" >/dev/null 2>&1 || true
                echo "::error::O site FICOU EM MANUTENÇÃO, com a versão nova parcialmente publicada."
                echo "Opções: corrigir e lançar uma nova tag, ou re-deployar a versão anterior"
                echo "(Actions → Release → Run workflow → tag anterior)."
                echo "Se o banco precisar voltar: o backup deste deploy está em _backups/ no servidor"
                echo "(apague a 1ª linha do arquivo e importe pelo phpMyAdmin)." ;;
        esac
    fi
    rm -rf "$TRABALHO"
    exit $codigo
}
trap limpar_ao_sair EXIT

restaurar_originais() {
    local comandos="rm -rf _deploy; rm -f manutencao.html;"
    local arquivo
    while IFS= read -r arquivo; do
        comandos+=" put -O \"$(dirname "$arquivo")\" \"$TRABALHO/originais/$arquivo\";"
    done < <(cd "$TRABALHO/originais" && find . -type f | sed 's|^\./||')
    ftp "$comandos"
}

# ---------------------------------------------------------------------
etapa "Conferindo o pacote $VERSAO"
[ -f "$PACOTE_DIR/api/index.php" ] && [ -f "$PACOTE_DIR/core/versao.php" ] || { echo "::error::Pacote incompleto em $PACOTE_DIR"; exit 1; }
[ ! -e "$PACOTE_DIR/core/config.php" ] || { echo "::error::O pacote contém core/config.php — abortando pra não sobrescrever as credenciais de produção."; exit 1; }
grep -q "'$VERSAO'" "$PACOTE_DIR/core/versao.php" || { echo "::error::core/versao.php do pacote não é da versão $VERSAO"; exit 1; }

# ---------------------------------------------------------------------
FASE=verificacao
etapa "Conferindo se $FTP_REMOTE_DIR é mesmo o Argos"
if ! existe_remoto core/config.php; then
    echo "::error::Não achei core/config.php em $FTP_REMOTE_DIR. Confira o secret FTP_REMOTE_DIR — o deploy APAGA o conteúdo desse diretório, então não sigo sem ter certeza."
    exit 1
fi
if ! existe_remoto api/index.php && ! existe_remoto .argos-deploy; then
    echo "::error::$FTP_REMOTE_DIR tem core/config.php mas nem api/index.php nem .argos-deploy. Não parece o Argos — abortando."
    exit 1
fi

etapa "Guardando cópia das páginas de entrada atuais (pra desfazer se precisar)"
mkdir -p "$TRABALHO/originais"
for arquivo in .htaccess "${PONTOS_ENTRADA[@]}"; do
    mkdir -p "$TRABALHO/originais/$(dirname "$arquivo")"
    ftp "get \"$arquivo\" -o \"$TRABALHO/originais/$arquivo\"" >/dev/null 2>&1 || rm -f "$TRABALHO/originais/$arquivo"
done

# ---------------------------------------------------------------------
FASE=manutencao
etapa "Ligando a manutenção e subindo o endpoint temporário de deploy"
MANUT="$TRABALHO/manutencao"
mkdir -p "$MANUT/_deploy"
sed "s/__DEPLOY_TOKEN__/$DEPLOY_TOKEN/" "$RAIZ_REPO/deploy/htaccess-manutencao" > "$MANUT/.htaccess"
cp "$RAIZ_REPO/deploy/manutencao.html" "$MANUT/manutencao.html"
for arquivo in "${PONTOS_ENTRADA[@]}"; do
    mkdir -p "$MANUT/$(dirname "$arquivo")"
    cp "$RAIZ_REPO/deploy/manutencao.html" "$MANUT/$arquivo"
done
cp "$RAIZ_REPO/deploy/endpoint.php" "$MANUT/_deploy/index.php"
cp "$RAIZ_REPO/deploy/migracoes.php" "$RAIZ_REPO/deploy/backup.php" "$MANUT/_deploy/"
# Migrations: vêm do pacote (= da tag sendo publicada), não do checkout.
mkdir -p "$MANUT/_deploy/database"
cp "$RAIZ_REPO"/database/migration_*.sql "$MANUT/_deploy/database/"
echo "Require all denied" > "$MANUT/_deploy/database/.htaccess"
printf '<?php return %s;\n' "'$(printf '%s' "$DEPLOY_TOKEN" | sha256sum | cut -d' ' -f1)'" > "$MANUT/_deploy/token.php"
echo "$VERSAO $(date -u +%FT%TZ)" > "$MANUT/.argos-deploy"
ftp "mirror -R --no-perms --overwrite \"$MANUT\" ."

etapa "Situação do banco antes do deploy"
endpoint GET status "${DB_BASELINE:+&baseline=$DB_BASELINE}"

# ---------------------------------------------------------------------
FASE=backup
etapa "Backup do banco (fica em _backups/ no servidor)"
endpoint POST backup "&rotulo=$VERSAO&manter=$BACKUPS_MANTER"

# ---------------------------------------------------------------------
FASE=limpeza
etapa "Apagando a versão anterior"

preservado() {
    local caminho="$1" p
    for p in "${PRESERVAR[@]}"; do
        [ "$caminho" = "$p" ] && return 0
    done
    return 1
}
contem_preservado() {
    local caminho="$1" p
    for p in "${PRESERVAR[@]}"; do
        case "$p" in "$caminho"/*) return 0 ;; esac
    done
    return 1
}
limpar_diretorio() { # limpar_diretorio <caminho relativo, "" = raiz>
    local dir="$1" entrada nome caminho eh_dir comandos=""
    while IFS= read -r entrada; do
        # cls -F: "/" no fim = diretório; "*", "@" etc. = marcação de
        # executável/link, não faz parte do nome.
        entrada="${entrada#./}"
        [ -n "$dir" ] && entrada="${entrada#"$dir"/}"
        if [ "${entrada%/}" != "$entrada" ]; then
            eh_dir=1; nome="${entrada%/}"
        else
            eh_dir=0; nome="${entrada%[*@=|]}"
        fi
        if [ -z "$nome" ] || [ "$nome" = "." ] || [ "$nome" = ".." ]; then
            continue
        fi
        caminho="${dir:+$dir/}$nome"
        if preservado "$caminho"; then
            echo "   mantém  $caminho"
        elif [ $eh_dir = 1 ] && contem_preservado "$caminho"; then
            limpar_diretorio "$caminho"
        else
            echo "   apaga   $caminho"
            if [ $eh_dir = 1 ]; then
                comandos+=" rm -r \"$caminho\";"
            else
                comandos+=" rm \"$caminho\";"
            fi
        fi
    done < <(ftp "cls -1aF \"${dir:-.}\"")
    [ -z "$comandos" ] || ftp "$comandos"
}
limpar_diretorio ""

# ---------------------------------------------------------------------
FASE=upload
etapa "Subindo os arquivos de $VERSAO"
EXCLUIR="-x '^.htaccess\$'"
for arquivo in "${PONTOS_ENTRADA[@]}"; do
    EXCLUIR+=" -x '^$arquivo\$'"
done
ftp "mirror -R --no-perms --overwrite --parallel=4 $EXCLUIR \"$PACOTE_DIR\" ."

# ---------------------------------------------------------------------
FASE=migracoes
etapa "Aplicando migrations do banco"
endpoint POST migrar "${DB_BASELINE:+&baseline=$DB_BASELINE}"

# ---------------------------------------------------------------------
FASE=verificacao_api
etapa "Conferindo a API antes de abrir o site"
status=$(curl -sS -o "$TRABALHO/api.json" -w '%{http_code}' -H "X-Deploy-Token: $DEPLOY_TOKEN" "$PROD_URL/api/index.php" || echo 000)
if [ "$status" != "200" ] || [ "$(jq -r '.servico' "$TRABALHO/api.json" 2>/dev/null)" != "Argos API" ]; then
    echo "::error::api/index.php respondeu HTTP $status depois do upload."
    head -c 1000 "$TRABALHO/api.json" || true
    exit 1
fi
echo "API ok."

# ---------------------------------------------------------------------
FASE=abertura
etapa "Tirando da manutenção"
comandos="put -O . \"$PACOTE_DIR/.htaccess\";"
for arquivo in "${PONTOS_ENTRADA[@]}"; do
    comandos+=" put -O \"$(dirname "$arquivo")\" \"$PACOTE_DIR/$arquivo\";"
done
comandos+=" rm -f manutencao.html; rm -rf _deploy;"
ftp "$comandos"

# ---------------------------------------------------------------------
FASE=smoke
etapa "Smoke test em produção"
codigo_smoke=0
php "$RAIZ_REPO/tests/smoke_producao.php" "$PROD_URL" "$VERSAO" || codigo_smoke=$?
if [ $codigo_smoke -eq 3 ]; then
    # Só configuração do servidor (ex: APP_PWA_API_KEY vazio): o site
    # funciona, então não religa a manutenção — só falha o job e avisa.
    FASE=smoke_config
    exit 1
fi
[ $codigo_smoke -eq 0 ] || exit 1

echo
echo "Deploy de $VERSAO concluído."
