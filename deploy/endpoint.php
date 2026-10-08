<?php

// Endpoint TEMPORÁRIO de deploy — a "ponte" até o banco de produção, que não
// aceita conexão de fora, só da própria aplicação.
//
// Ciclo de vida (ver scripts/deploy/deploy-producao.sh):
//   1. o workflow de release gera um token aleatório só pra aquele deploy;
//   2. sobe este arquivo por FTP como _deploy/index.php, junto com
//      _deploy/token.php contendo só o sha256 do token;
//   3. chama as ações abaixo com o header X-Deploy-Token;
//   4. apaga a pasta _deploy/ inteira no fim (inclusive se der erro).
// Fora de um deploy em andamento, este código não existe no servidor.
//
// Ações (?acao=):
//   GET  status  → migrations aplicadas/pendentes, sem alterar nada
//   POST backup  → dump completo em _backups/ (fica no servidor)
//   POST migrar  → aplica as migrations pendentes

require_once __DIR__ . '/migracoes.php';
require_once __DIR__ . '/backup.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function responderDeploy($dados, $status = 200) {
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// ---------- Autenticação ----------
$arquivoToken = __DIR__ . '/token.php';
$hashEsperado = is_file($arquivoToken) ? (require $arquivoToken) : '';
$tokenRecebido = $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '';

if (!is_string($hashEsperado) || strlen($hashEsperado) !== 64 || $tokenRecebido === ''
    || !hash_equals($hashEsperado, hash('sha256', $tokenRecebido))) {
    // Resposta igual a "não existe" — não confirma que há um deploy rolando.
    responderDeploy(['erro' => 'Não encontrado.'], 404);
}

// ---------- Banco ----------
$raiz = dirname(__DIR__);
require_once $raiz . '/core/config.php';

set_time_limit(0);
ignore_user_abort(true);
mysqli_report(MYSQLI_REPORT_OFF);

$conexao = conectarBanco();
mysqli_set_charset($conexao, 'utf8mb4');

// As migrations sobem junto com este endpoint (_deploy/database/) — a pasta
// database/ não faz parte do pacote de produção.
$diretorioMigracoes = __DIR__ . '/database';
$baselineForcada = isset($_GET['baseline']) && $_GET['baseline'] !== '' ? (int) $_GET['baseline'] : null;
$acao = $_GET['acao'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];

try {
    switch ($acao) {
        case 'status':
            $versao = is_file($raiz . '/core/versao.php') ? (require $raiz . '/core/versao.php') : null;
            responderDeploy([
                'ok' => true,
                'versao_no_servidor' => $versao,
                'php' => PHP_VERSION,
                'mysql' => mysqli_get_server_info($conexao),
                'migracoes' => is_dir($diretorioMigracoes) ? migracoesStatus($conexao, $diretorioMigracoes, $baselineForcada) : null,
            ]);

        case 'backup':
            if ($metodo !== 'POST') {
                responderDeploy(['erro' => 'Use POST.'], 405);
            }
            $rotulo = preg_replace('/[^A-Za-z0-9._-]/', '', $_GET['rotulo'] ?? 'manual') ?: 'manual';
            $diretorioBackups = $raiz . '/_backups';
            if (!is_dir($diretorioBackups) && !mkdir($diretorioBackups, 0750, true)) {
                responderDeploy(['ok' => false, 'erro' => "Não consegui criar $diretorioBackups"], 500);
            }
            // Defesa extra além do cabeçalho PHP de cada arquivo.
            file_put_contents($diretorioBackups . '/.htaccess', "Require all denied\n");
            file_put_contents($diretorioBackups . '/index.html', '');

            $nome = 'backup_' . $rotulo . '_' . date('Y-m-d_His') . '_' . bin2hex(random_bytes(4)) . '.sql.php';
            $info = backupGerarArquivo($conexao, "$diretorioBackups/$nome", $rotulo);
            $removidos = backupLimparAntigos($diretorioBackups, (int) ($_GET['manter'] ?? 10));

            responderDeploy(['ok' => true, 'arquivo' => "_backups/$nome"] + $info + ['removidos' => $removidos]);

        case 'migrar':
            if ($metodo !== 'POST') {
                responderDeploy(['erro' => 'Use POST.'], 405);
            }
            $resultado = migracoesExecutar($conexao, $diretorioMigracoes, $baselineForcada);
            responderDeploy($resultado, $resultado['ok'] ? 200 : 500);

        default:
            responderDeploy(['erro' => 'Ação desconhecida. Use ?acao=status|backup|migrar'], 400);
    }
} catch (Throwable $e) {
    responderDeploy(['ok' => false, 'erro' => $e->getMessage()], 500);
}
