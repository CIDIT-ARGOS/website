<?php

// Testes das telas logadas: entra no Painel de Comando e no Ikarus37 como um
// navegador faria (formulário + cookie de sessão) e abre cada página,
// conferindo que carrega sem erro de PHP e com a folha de estilo comum.
//
// Existe porque os outros testes só batem na API e nas telas de login — uma
// tela interna podia cair com erro fatal sem ninguém ver (foi o caso da
// chamada do Painel, que consultava uma tabela que nenhum schema cria).
//
// Uso:
//   php tests/paginas_test.php http://127.0.0.1:8000
//
// Precisa de acesso ao banco de teste (ver tests/lib_teste.php) pra apagar o
// usuário que cria. Rode só contra um banco descartável.

require __DIR__ . '/lib_teste.php';

const USUARIO_PAGINAS = 'ci.paginas';
const SENHA_PAGINAS = 'senha-do-teste-de-paginas';

// Requisição de navegador: formulário urlencoded, cookies guardados em
// $cookies e sem seguir redirect (pra dar pra ver o 302 do login).
function navegar($metodo, $caminho, array &$cookies, ?array $campos = null) {
    global $baseUrl;

    $headers = [];
    if ($cookies) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(fn($n, $v) => "$n=$v", array_keys($cookies), $cookies));
    }
    if ($campos !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }

    $corpo = @file_get_contents($baseUrl . $caminho, false, stream_context_create(['http' => [
        'method' => $metodo,
        'header' => implode("\r\n", $headers),
        'content' => $campos !== null ? http_build_query($campos) : '',
        'follow_location' => 0,
        'ignore_errors' => true,
        'timeout' => 20,
    ]]));
    if ($corpo === false) {
        throw new RuntimeException("sem resposta de $baseUrl$caminho");
    }

    $status = 0;
    foreach ($http_response_header as $linha) {
        if (preg_match('{^HTTP/\S+ (\d{3})}', $linha, $m)) {
            $status = (int) $m[1];
        } elseif (preg_match('{^Set-Cookie:\s*([^=]+)=([^;]*)}i', $linha, $m)) {
            $cookies[$m[1]] = $m[2];
        }
    }

    return ['status' => $status, 'corpo' => $corpo];
}

function entrar($caminhoLogin, $usuario, $senha) {
    $cookies = [];
    $r = navegar('POST', $caminhoLogin, $cookies, ['usuario' => $usuario, 'senha' => $senha]);
    garantir($r['status'] === 302, "o login deveria redirecionar (302), veio {$r['status']}: " . substr(strip_tags($r['corpo']), 0, 200));
    return $cookies;
}

function conferirPagina($caminho, array &$cookies, $folhaDeEstilo) {
    $r = navegar('GET', $caminho, $cookies);
    garantirStatus($r, 200);
    semErroPhp($r);
    garantir(strpos($r['corpo'], 'Acesso negado') === false, 'a página respondeu "Acesso negado"');
    garantir(strpos($r['corpo'], $folhaDeEstilo) !== false, "a página não carrega $folhaDeEstilo");
}

function apagarUsuarioDoTeste() {
    sql("DELETE FROM painel_usuarios WHERE usuario = '" . USUARIO_PAGINAS . "'");
}

echo "Argos — testes das telas logadas contra $baseUrl\n";

try {
    apagarUsuarioDoTeste();
} catch (Throwable $e) {
    echo "\n" . $e->getMessage() . "\n";
    exit(1);
}

// =====================================================================
secao('Identidade visual — arquivos compartilhados');

foreach ([
    '/web/css/argos-admin.css' => 'folha comum do Painel e do Ikarus37',
    '/web/css/argos-ikarus.css' => 'tema do Ikarus37',
    '/web/images/argos-olho.svg' => 'marca do Argos',
    '/web/images/especialista.svg' => 'insígnia de especialista',
] as $caminho => $descricao) {
    teste("$descricao ($caminho)", function () use ($caminho) {
        garantirStatus(requisicao('GET', $caminho), 200);
    });
}

teste('as folhas de estilo só usam caminho relativo', function () use ($raizProjeto) {
    foreach (['web/css/argos-admin.css', 'web/css/argos-ikarus.css', 'web/app/css/app.css'] as $arquivo) {
        // Caminho absoluto quebra em produção, onde o site fica num subdiretório.
        garantir(!preg_match('{url\(\s*[\'"]?/(?!/)}', file_get_contents("$raizProjeto/$arquivo")), "$arquivo tem url() com caminho absoluto");
    }
});

// =====================================================================
secao('Painel de Comando — telas logadas');

$retiradaId = null;
$alunoId = null;
$cookiesPainel = [];

teste('prepara um usuário do CA, uma retirada e um aluno', function () use (&$retiradaId, &$alunoId) {
    garantirStatus(api('POST', 'painel_usuarios.php', null, [
        'nome' => 'Teste das Páginas', 'usuario' => USUARIO_PAGINAS, 'senha' => SENHA_PAGINAS, 'cargo' => 'CMD_CA',
    ]), 201);

    $token = login(QR_PRATA_1)['token'];
    $retirada = api('POST', 'retiradas.php', $token, [
        'tipo' => 'educacao_fisica', 'agrupamento_tipo' => 'esquadrilha', 'agrupamento_valor' => 'A', 'esquadrao' => 'Esquadrão Prata',
    ]);
    garantirStatus($retirada, 201);
    $retiradaId = (int) $retirada['json']['id'];
    $alunoId = (int) api('GET', 'alunos.php', $token)['json'][0]['id'];
});

teste('página interna sem login manda pro login (302)', function () {
    $semCookie = [];
    garantir(navegar('GET', '/web/painel/situacao.php', $semCookie)['status'] === 302, 'situacao.php abriu sem sessão');
});

teste('senha errada não entra', function () {
    $cookies = [];
    $r = navegar('POST', '/web/painel/index.php', $cookies, ['usuario' => USUARIO_PAGINAS, 'senha' => 'senha-errada']);
    garantir($r['status'] === 200 && strpos($r['corpo'], 'name="senha"') !== false, 'deveria voltar pro formulário de login');
    garantir(navegar('GET', '/web/painel/situacao.php', $cookies)['status'] === 302, 'entrou com a senha errada');
});

teste('login com usuário e senha', function () use (&$cookiesPainel) {
    $cookiesPainel = entrar('/web/painel/index.php', USUARIO_PAGINAS, SENHA_PAGINAS);
});

if ($cookiesPainel && $retiradaId) {
    foreach ([
        'index.php', 'retiradas.php', 'retirada_nova.php', "retirada_marcar.php?id=$retiradaId",
        'efetivo.php', "efetivo_editar.php?id=$alunoId", 'situacao.php', 'dashboard.php', 'relatorios.php',
        'livro_do_dia.php', 'dispensas.php', 'grupos.php', 'usuarios.php', 'dominio.php',
        'grupo_acesso_detalhe.php?id=1', 'permissoes_cargo.php', 'turmas.php',
    ] as $pagina) {
        teste($pagina, function () use ($pagina, &$cookiesPainel) {
            conferirPagina("/web/painel/$pagina", $cookiesPainel, 'css/argos-admin.css');
        });
    }

    teste('sair encerra a sessão', function () use (&$cookiesPainel) {
        navegar('GET', '/web/painel/index.php?logout=1', $cookiesPainel);
        garantir(navegar('GET', '/web/painel/situacao.php', $cookiesPainel)['status'] === 302, 'a sessão continuou valendo depois de sair');
    });
}

// =====================================================================
secao('Ikarus37 — telas logadas');

$cookiesIkarus = [];
teste('login com o admin do init_db.sql', function () use (&$cookiesIkarus) {
    // Usuário e senha iniciais documentados em database/init_db.sql.
    $cookiesIkarus = entrar('/web/ikarus37/index.php', 'admin', 'Argos@2026');
});

if ($cookiesIkarus) {
    foreach ([
        'index.php', 'api.php', 'usuarios.php', 'banco.php', 'backup.php', 'importar.php',
        'dominio.php', 'grupo_acesso_detalhe.php?id=1', 'permissoes_cargo.php',
    ] as $pagina) {
        teste($pagina, function () use ($pagina, &$cookiesIkarus) {
            conferirPagina("/web/ikarus37/$pagina", $cookiesIkarus, 'css/argos-ikarus.css');
        });
    }
}

// =====================================================================
try {
    if ($retiradaId) {
        api('DELETE', "retiradas.php?id=$retiradaId");
    }
    apagarUsuarioDoTeste();
} catch (Throwable $e) {
    echo "\nNão consegui limpar os dados do teste: " . $e->getMessage() . "\n";
}

encerrarTestes();
