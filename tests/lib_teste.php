<?php

// Mini framework compartilhado pelos testes que batem no servidor por HTTP
// (tests/integracao_test.php e tests/api_test.php).
//
// Sem dependências (nem curl, nem PHPUnit) — só PHP puro, igual ao resto do
// projeto.
//
// Variáveis de ambiente opcionais:
//   BASE_URL       raiz do servidor, se não vier como 1º argumento
//   ARGOS_API_KEY  chave de API válida (padrão: a chave legada seedada no init_db.sql)

$baseUrl = rtrim($argv[1] ?? getenv('BASE_URL') ?: 'http://127.0.0.1:8000', '/');
$apiKey = getenv('ARGOS_API_KEY') ?: 'd0cf67c0a3b8464aacb2ed36f01b1fbb2af8d7623d804c3aa5eef732c6b3f058';
$raizProjeto = dirname(__DIR__);

// qrcode_hash dos alunos de tests/fixtures/dados_teste.sql
const QR_PRATA_1 = '1111111111111111111111111111111111111111111111111111111111111111';
const QR_PRATA_2 = '2222222222222222222222222222222222222222222222222222222222222222';
const QR_AZUL = '3333333333333333333333333333333333333333333333333333333333333333';
const QR_INATIVO = '4444444444444444444444444444444444444444444444444444444444444444';

$falhas = [];
$total = 0;

function teste($nome, callable $corpo) {
    global $falhas, $total;
    $total++;
    try {
        $corpo();
        echo "  ok    $nome\n";
    } catch (Throwable $e) {
        $falhas[] = "$nome: " . $e->getMessage();
        echo "  FALHA $nome\n        " . $e->getMessage() . "\n";
    }
}

function secao($titulo) {
    echo "\n== $titulo ==\n";
}

function garantir($condicao, $mensagem) {
    if (!$condicao) {
        throw new RuntimeException($mensagem);
    }
}

function garantirStatus($resposta, $esperado) {
    garantir(
        $resposta['status'] === $esperado,
        "esperava HTTP $esperado, veio {$resposta['status']}. Corpo: " . substr($resposta['corpo'], 0, 300)
    );
}

function requisicao($metodo, $caminho, $headers = [], $corpo = null) {
    global $baseUrl;

    $linhasHeader = [];
    foreach ($headers as $nome => $valor) {
        $linhasHeader[] = "$nome: $valor";
    }
    if ($corpo !== null) {
        $linhasHeader[] = 'Content-Type: application/json';
    }

    $contexto = stream_context_create(['http' => [
        'method' => $metodo,
        'header' => implode("\r\n", $linhasHeader),
        'content' => $corpo !== null ? json_encode($corpo) : '',
        'ignore_errors' => true,
        'timeout' => 20,
    ]]);

    $corpoResposta = @file_get_contents($baseUrl . $caminho, false, $contexto);
    if ($corpoResposta === false) {
        throw new RuntimeException("sem resposta de $baseUrl$caminho");
    }

    // Com redirect, $http_response_header acumula os headers de todas as
    // respostas — vale o status/headers da última.
    $status = 0;
    $headersResposta = [];
    foreach ($http_response_header as $linha) {
        if (preg_match('{^HTTP/\S+ (\d{3})}', $linha, $m)) {
            $status = (int) $m[1];
            $headersResposta = [];
            continue;
        }
        [$nome, $valor] = array_map('trim', explode(':', $linha, 2)) + [1 => ''];
        $headersResposta[strtolower($nome)] = $valor;
    }

    return [
        'status' => $status,
        'corpo' => $corpoResposta,
        'json' => json_decode($corpoResposta, true),
        'headers' => $headersResposta,
    ];
}

function api($metodo, $caminho, $token = null, $corpo = null, $chave = null) {
    global $apiKey;
    $headers = ['X-API-Key' => $chave ?? $apiKey];
    if ($token) {
        $headers['X-Session-Token'] = $token;
    }
    return requisicao($metodo, '/api/' . $caminho, $headers, $corpo);
}

function login($qrcodeHash) {
    $resposta = api('POST', 'sessao.php', null, ['qrcode_hash' => $qrcodeHash]);
    garantirStatus($resposta, 201);
    return $resposta['json'];
}

function semErroPhp($resposta) {
    foreach (['Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Uncaught'] as $marca) {
        garantir(stripos($resposta['corpo'], $marca) === false, "página contém '$marca': " . substr($resposta['corpo'], 0, 300));
    }
}

// ---------- Acesso direto ao banco de teste ----------
// Pra montar cenários que a aplicação de propósito não deixa (expirar sessão,
// simular IP bloqueado) e limpar o que o teste criou. Mesmas variáveis do
// tests/ci_banco.php: DB_HOST, DB_PORTA, DB_ROOT_SENHA, DB_NOME (argos_teste).
function banco() {
    static $conexao = null;
    if (!$conexao) {
        mysqli_report(MYSQLI_REPORT_OFF);
        $conexao = @mysqli_connect(
            getenv('DB_HOST') ?: '127.0.0.1',
            'root',
            getenv('DB_ROOT_SENHA') ?: 'argos_root',
            getenv('DB_NOME') ?: 'argos_teste',
            (int) (getenv('DB_PORTA') ?: 3306)
        );
        if (!$conexao) {
            throw new RuntimeException('sem acesso ao banco de teste (DB_HOST/DB_PORTA/DB_ROOT_SENHA/DB_NOME): ' . mysqli_connect_error());
        }
        mysqli_set_charset($conexao, 'utf8mb4');
    }
    return $conexao;
}

function sql($comando) {
    $resultado = mysqli_query(banco(), $comando);
    if ($resultado === false) {
        throw new RuntimeException('SQL falhou: ' . mysqli_error(banco()) . " — $comando");
    }
    return $resultado;
}

function sqlLinha($comando) {
    return mysqli_fetch_assoc(sql($comando));
}

// Imprime o placar e encerra com código 1 se algum teste falhou.
function encerrarTestes() {
    global $falhas, $total;
    $qtdFalhas = count($falhas);
    echo "\n$total testes, " . ($total - $qtdFalhas) . " ok, $qtdFalhas falha(s)\n";
    if ($qtdFalhas > 0) {
        echo "\nFalhas:\n - " . implode("\n - ", $falhas) . "\n";
        exit(1);
    }
    exit(0);
}
