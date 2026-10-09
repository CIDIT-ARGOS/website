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
//
// Códigos de saída:
//   0  tudo ok
//   1  falha CRÍTICA — o site está quebrado (o deploy religa a manutenção)
//   3  só falhas de CONFIGURAÇÃO — o site funciona, mas falta ajuste no
//      servidor (ex: APP_PWA_API_KEY vazio); o deploy falha e avisa, mas
//      não tira o site do ar

$baseUrl = rtrim($argv[1] ?? getenv('PROD_URL') ?: '', '/');
$versaoEsperada = $argv[2] ?? '';

if ($baseUrl === '') {
    fwrite(STDERR, "Uso: php tests/smoke_producao.php <url-da-raiz-do-projeto> [versao]\n");
    exit(2);
}

$falhas = 0;
$falhasConfig = 0;

function checar($nome, callable $corpo, $critico = true) {
    global $falhas, $falhasConfig;
    try {
        $corpo();
        echo "  ok    $nome\n";
    } catch (Throwable $e) {
        $critico ? $falhas++ : $falhasConfig++;
        echo '  ' . ($critico ? 'FALHA' : 'CONFIG') . " $nome\n        " . $e->getMessage() . "\n";
        if (getenv('GITHUB_ACTIONS') || getenv('FORGEJO_ACTIONS')) {
            echo "::error::$nome — " . str_replace("\n", ' ', $e->getMessage()) . "\n";
        }
    }
}

// Checagem de configuração do servidor (não de código): se falhar, o deploy
// avisa mas não tira o site do ar.
function checarConfig($nome, callable $corpo) {
    checar($nome, $corpo, false);
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
        'content' => $corpo !== null ? json_encode($corpo) : '',
        'ignore_errors' => true,
        'timeout' => 30,
        // Sem User-Agent, firewalls de hospedagem costumam tratar a
        // requisição como robô e responder 403/404.
        'user_agent' => 'Mozilla/5.0 (compatible; Argos-Smoke/1.0)',
        // Nunca resposta de cache logo depois de trocar os arquivos.
        'header' => implode("\r\n", array_merge($linhas, ['Cache-Control: no-cache', 'Pragma: no-cache'])),
    ]]);
    // Parâmetro aleatório em toda URL: a Hostinger tem uma CDN (hcdn) na
    // frente do site, e logo depois do deploy alguns edges ainda serviam 404
    // em cache de quando os arquivos estavam sendo trocados.
    $url = $baseUrl . $caminho . (strpos($caminho, '?') === false ? '?' : '&') . '_smoke=' . bin2hex(random_bytes(4));
    $resposta = @file_get_contents($url, false, $contexto);
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

$envPwa = '';
checar('PWA: env.php responde com a base da API correta', function () use (&$envPwa, $baseUrl) {
    $r = pegar('GET', '/web/app/env.php');
    exigirStatus($r, 200);
    semErroPhp($r);
    $envPwa = $r['corpo'];
    exigir(preg_match('/window\.ARGOS_API_BASE = "([^"]*)";/', $r['corpo'], $b), 'ARGOS_API_BASE ausente');
    $caminhoEsperado = (parse_url($baseUrl, PHP_URL_PATH) ?? '') . '/api/';
    exigir($b[1] === $caminhoEsperado, "ARGOS_API_BASE = {$b[1]}, esperava $caminhoEsperado");
});

$chaveApp = null;
checarConfig('PWA: APP_PWA_API_KEY preenchido no core/config.php', function () use (&$envPwa, &$chaveApp) {
    exigir(preg_match('/window\.ARGOS_API_KEY = "([^"]*)";/', $envPwa, $m), 'ARGOS_API_KEY ausente no env.php');
    exigir($m[1] !== '', 'APP_PWA_API_KEY está vazio no core/config.php de produção — gere uma chave em Ikarus37 → API e cole no config.php');
    $chaveApp = $m[1];
});

checarConfig('PWA: a chave do app é aceita pela API', function () use (&$chaveApp) {
    exigir($chaveApp, 'sem chave do app (teste anterior falhou)');
    // qrcode_hash propositalmente inválido: com chave válida a API responde
    // 400 (formato), com chave inválida 401 — e nada é gravado.
    exigirStatus(pegar('POST', '/api/sessao.php', ['X-API-Key' => $chaveApp], ['qrcode_hash' => 'smoke']), 400);
});

checarConfig('rotas do app exigem sessão do aluno (401 só com a chave)', function () use (&$chaveApp) {
    exigir($chaveApp, 'sem chave do app');
    exigirStatus(pegar('GET', '/api/alunos.php', ['X-API-Key' => $chaveApp]), 401);
});

checar('raiz do site abre a landing page', function () {
    $r = pegar('GET', '/');
    exigirStatus($r, 200);
    semErroPhp($r);
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

if ($falhas) {
    echo "\n$falhas verificação(ões) CRÍTICA(s) falharam" . ($falhasConfig ? " (e $falhasConfig de configuração)" : '') . ".\n";
    exit(1);
}
if ($falhasConfig) {
    echo "\nSite ok, mas $falhasConfig verificação(ões) de CONFIGURAÇÃO falharam — ajuste no servidor.\n";
    exit(3);
}
echo "\nTudo ok.\n";
exit(0);
