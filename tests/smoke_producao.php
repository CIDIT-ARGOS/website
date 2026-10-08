<?php

// Smoke test SOMENTE LEITURA — seguro pra rodar contra produção.
// Não cria nem altera nada: só confere que o site está de pé e respondendo
// do jeito certo. Roda no fim de todo deploy e todo dia pelo agendamento
// (.forgejo/workflows/smoke-producao.yml).
//
// Uso:
//   php tests/smoke_producao.php https://sigsaeear.com/cidit/projetos/11 [versao-esperada]
//
// A chave de API usada é a que a própria PWA recebe (web/app/env.php), então
// o teste também prova que o APP_PWA_API_KEY do config.php de produção está
// preenchido e é uma chave ativa.

$baseUrl = rtrim($argv[1] ?? getenv('PROD_URL') ?: '', '/');
$versaoEsperada = $argv[2] ?? '';

if ($baseUrl === '') {
    fwrite(STDERR, "Uso: php tests/smoke_producao.php <url-da-raiz-do-projeto> [versao]\n");
    exit(2);
}

$falhas = 0;

function checar($nome, callable $corpo) {
    global $falhas;
    try {
        $corpo();
        echo "  ok    $nome\n";
    } catch (Throwable $e) {
        $falhas++;
        echo "  FALHA $nome\n        " . $e->getMessage() . "\n";
        if (getenv('GITHUB_ACTIONS') || getenv('FORGEJO_ACTIONS')) {
            echo "::error::$nome — " . str_replace("\n", ' ', $e->getMessage()) . "\n";
        }
    }
}

function exigir($condicao, $mensagem) {
    if (!$condicao) {
        throw new RuntimeException($mensagem);
    }
}

function pegar($metodo, $caminho, $headers = [], $corpo = null) {
    global $baseUrl;
    $linhas = [];
    foreach ($headers as $n => $v) {
        $linhas[] = "$n: $v";
    }
    if ($corpo !== null) {
        $linhas[] = 'Content-Type: application/json';
    }
    $contexto = stream_context_create(['http' => [
        'method' => $metodo,
        'header' => implode("\r\n", $linhas),
        'content' => $corpo !== null ? json_encode($corpo) : '',
        'ignore_errors' => true,
        'timeout' => 30,
    ]]);
    $resposta = @file_get_contents($baseUrl . $caminho, false, $contexto);
    if ($resposta === false) {
        throw new RuntimeException("sem resposta de $baseUrl$caminho");
    }
    $status = 0;
    foreach ($http_response_header as $linha) {
        if (preg_match('{^HTTP/\S+ (\d{3})}', $linha, $m)) {
            $status = (int) $m[1];
        }
    }
    return ['status' => $status, 'corpo' => $resposta, 'json' => json_decode($resposta, true)];
}

function exigirStatus($r, $esperado) {
    exigir($r['status'] === $esperado, "esperava HTTP $esperado, veio {$r['status']}: " . substr(trim($r['corpo']), 0, 200));
}

function semErroPhp($r) {
    foreach (['Fatal error', 'Parse error', 'Warning:', 'Uncaught', 'Falha na conexão com o banco'] as $marca) {
        exigir(stripos($r['corpo'], $marca) === false, "página contém '$marca'");
    }
    exigir(stripos($r['corpo'], 'Argos em manutenção') === false, 'página ainda mostra a manutenção');
}

echo "Smoke test em $baseUrl\n";

checar('api/index.php responde', function () {
    $r = pegar('GET', '/api/index.php');
    exigirStatus($r, 200);
    exigir(($r['json']['servico'] ?? null) === 'Argos API', 'resposta não é da Argos API');
});

checar('API recusa requisição sem chave (401)', function () {
    exigirStatus(pegar('GET', '/api/motivos.php'), 401);
});

checar('API recusa chave inválida (401)', function () {
    exigirStatus(pegar('GET', '/api/motivos.php', ['X-API-Key' => 'chave-invalida-smoke-test']), 401);
});

$chaveApp = null;
checar('PWA: env.php entrega chave e base da API corretas', function () use (&$chaveApp, $baseUrl) {
    $r = pegar('GET', '/web/app/env.php');
    exigirStatus($r, 200);
    semErroPhp($r);
    exigir(preg_match('/window\.ARGOS_API_KEY = "([^"]*)";/', $r['corpo'], $m), 'ARGOS_API_KEY ausente');
    exigir($m[1] !== '', 'APP_PWA_API_KEY está vazio no core/config.php de produção — gere uma chave em Ikarus37 → API');
    $chaveApp = $m[1];
    exigir(preg_match('/window\.ARGOS_API_BASE = "([^"]*)";/', $r['corpo'], $b), 'ARGOS_API_BASE ausente');
    $caminhoEsperado = (parse_url($baseUrl, PHP_URL_PATH) ?? '') . '/api/';
    exigir($b[1] === $caminhoEsperado, "ARGOS_API_BASE = {$b[1]}, esperava $caminhoEsperado");
});

checar('PWA: a chave do app é aceita pela API', function () use (&$chaveApp) {
    exigir($chaveApp, 'sem chave do app (teste anterior falhou)');
    // qrcode_hash propositalmente inválido: com chave válida a API responde
    // 400 (formato), com chave inválida 401 — e nada é gravado.
    exigirStatus(pegar('POST', '/api/sessao.php', ['X-API-Key' => $chaveApp], ['qrcode_hash' => 'smoke']), 400);
});

checar('rotas do app exigem sessão do aluno (401 só com a chave)', function () use (&$chaveApp) {
    exigir($chaveApp, 'sem chave do app');
    exigirStatus(pegar('GET', '/api/alunos.php', ['X-API-Key' => $chaveApp]), 401);
});

foreach ([
    '/web/index.html' => 'landing page',
    '/web/app/index.html' => 'PWA Área Funcional',
    '/web/painel/index.php' => 'login do Painel',
    '/web/ikarus37/index.php' => 'login do Ikarus37',
] as $caminho => $nome) {
    checar("$nome carrega", function () use ($caminho) {
        $r = pegar('GET', $caminho);
        exigirStatus($r, 200);
        semErroPhp($r);
    });
}

if ($versaoEsperada !== '') {
    checar("rodapé do Painel mostra a versão $versaoEsperada", function () use ($versaoEsperada) {
        $r = pegar('GET', '/web/painel/index.php');
        exigir(strpos($r['corpo'], "Argos $versaoEsperada") !== false, 'versão não aparece no rodapé (core/versao.php não foi atualizado?)');
    });
}

checar('dados sensíveis não são servidos', function () {
    // Olha o conteúdo, não só o status: cada servidor responde diferente
    // (404, 403, 200 vazio, ou fallback pro index.html) e todos são ok —
    // o problema é vazar listagem de diretório, dump ou credencial.
    foreach (['/core/config.php', '/_backups/', '/_deploy/index.php', '/database/init_db.sql'] as $caminho) {
        $r = pegar('GET', $caminho);
        if ($r['status'] !== 200) {
            continue;
        }
        foreach (['Index of', 'backup_', 'CREATE TABLE', 'DB_SENHA', '<?php', 'migracoes'] as $vazamento) {
            exigir(stripos($r['corpo'], $vazamento) === false, "$caminho expõe conteúdo interno ('$vazamento')");
        }
    }
});

echo $falhas ? "\n$falhas verificação(ões) falharam.\n" : "\nTudo ok.\n";
exit($falhas ? 1 : 0);
