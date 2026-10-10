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

// Criar usuário do Painel e apagar retirada são endpoints administrativos.
$apiKey = $apiKeyAdmin;

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
    garantir(strpos($r['corpo'], 'class="argos-rodape"') !== false, 'a página não tem o rodapé com a versão e o crédito');
}

function apagarUsuarioDoTeste() {
    // Sobras do teste de posto de serviço, se ele tiver parado no meio.
    sql("DELETE FROM posto_servico_escalas WHERE posto_servico_id IN (SELECT id FROM postos_servico WHERE nome = 'Posto Teste das Páginas')");
    sql("DELETE FROM retirada_itens WHERE servico_id IN (SELECT id FROM postos_servico WHERE nome = 'Posto Teste das Páginas')");
    sql("DELETE FROM postos_servico WHERE nome = 'Posto Teste das Páginas'");
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
    '/web/js/argos-admin.js' => 'tabelas enxutas com painel lateral',
    '/web/css/fontes.css' => 'fontes servidas pelo próprio sistema',
    '/web/fonts/ibm-plex-sans-400-latin.woff2' => 'arquivo de fonte',
    '/web/vendor/chart.umd.js' => 'Chart.js local (gráficos do Painel)',
    '/web/app/vendor/jsQR.js' => 'jsQR local (leitor de QR)',
    '/web/images/argos-olho.svg' => 'marca do Argos',
    '/web/images/especialista.svg' => 'insígnia de especialista',
] as $caminho => $descricao) {
    teste("$descricao ($caminho)", function () use ($caminho) {
        garantirStatus(requisicao('GET', $caminho), 200);
    });
}

// O Argos roda em intranet: nenhuma página pode buscar script, estilo, fonte
// ou imagem na internet. (Link que o usuário clica, tipo o do GitHub na
// landing, não é carregamento — por isso <a href> não entra.)
teste('nenhuma página carrega recurso da internet', function () use ($raizProjeto) {
    $padroes = [
        '{<(?:script|img|iframe|source|video|audio)\b[^>]*\bsrc=["\']https?://}i',
        '{<link\b[^>]*\bhref=["\']https?://}i',
        '{@import\s+(?:url\()?["\']?https?://}i',
        '{url\(\s*["\']?https?://}i',
        '{\.src\s*=\s*["\']https?://}',
        '{(?:fetch|importScripts)\(\s*["\']https?://}',
    ];
    $achados = [];
    $arquivos = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$raizProjeto/web", FilesystemIterator::SKIP_DOTS));
    foreach ($arquivos as $arquivo) {
        $caminho = str_replace('\\', '/', substr($arquivo->getPathname(), strlen($raizProjeto) + 1));
        // Bibliotecas de terceiros trazem URL em comentário de licença — não são carregamento.
        if (!preg_match('/\.(php|html|css|js)$/', $caminho) || strpos($caminho, '/vendor/') !== false) {
            continue;
        }
        foreach (file($arquivo->getPathname()) as $n => $linha) {
            foreach ($padroes as $padrao) {
                if (preg_match($padrao, $linha)) {
                    $achados[] = "$caminho:" . ($n + 1) . ' ' . trim(substr($linha, 0, 120));
                }
            }
        }
    }
    garantir($achados === [], "recurso externo em:\n          " . implode("\n          ", $achados));
});

teste('o leitor de QR usa o decodificador local', function () use ($raizProjeto) {
    $leitor = file_get_contents("$raizProjeto/web/app/js/qr-scanner.js");
    garantir(strpos($leitor, '../vendor/jsQR.js') !== false, 'qr-scanner.js não aponta pro vendor/jsQR.js local');
    garantir(strpos(file_get_contents("$raizProjeto/web/app/vendor/jsQR.js"), 'jsQR') !== false, 'vendor/jsQR.js não parece ser o jsQR');
    garantir(strpos(file_get_contents("$raizProjeto/web/app/sw.js"), "'vendor/jsQR.js'") !== false, 'o service worker não guarda o jsQR pra uso offline');
});

teste('as folhas de estilo só usam caminho relativo', function () use ($raizProjeto) {
    foreach (['web/css/argos-admin.css', 'web/css/argos-ikarus.css', 'web/css/fontes.css', 'web/app/css/app.css'] as $arquivo) {
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
        'postos_servico.php', 'qrcodes.php', 'qrcodes.php?situacao=sem&busca=TESTE', 'efetivo.php', "efetivo_editar.php?id=$alunoId", 'situacao.php', 'dashboard.php', 'relatorios.php',
        'livro_do_dia.php', 'dispensas.php', 'grupos.php', 'usuarios.php', 'dominio.php',
        'grupo_acesso_detalhe.php?id=1', 'permissoes_cargo.php', 'turmas.php',
    ] as $pagina) {
        teste($pagina, function () use ($pagina, &$cookiesPainel) {
            conferirPagina("/web/painel/$pagina", $cookiesPainel, 'css/argos-admin.css');
        });
    }

    // Posto de serviço de ponta a ponta, pela tela: cria o posto, coloca um
    // aluno de serviço, rende por outro — e a chamada aberta em seguida já
    // traz quem está de serviço como ausente por Serviço, com o posto.
    teste('posto de serviço: criar, colocar de serviço, render, e a chamada já sabe', function () use (&$cookiesPainel) {
        $posto = 'Posto Teste das Páginas';
        $enviar = fn(array $campos) => navegar('POST', '/web/painel/postos_servico.php', $cookiesPainel, $campos);
        $deServico = fn() => array_column(mysqli_fetch_all(sql("
            SELECT a.nome_guerra FROM posto_servico_escalas e
            JOIN alunos a ON a.id = e.aluno_id
            JOIN postos_servico p ON p.id = e.posto_servico_id
            WHERE p.nome = '$posto' AND e.fim IS NULL"), MYSQLI_ASSOC), 'nome_guerra');

        $r = $enviar(['acao' => 'criar_posto', 'nome' => $posto, 'sigla' => 'PTP']);
        garantirStatus($r, 200);
        semErroPhp($r);
        garantir(strpos($r['corpo'], 'Posto de serviço criado.') !== false, 'a tela não confirmou a criação do posto');
        $postoId = (int) sqlLinha("SELECT id FROM postos_servico WHERE nome = '$posto'")['id'];

        garantir(strpos($enviar(['acao' => 'criar_posto', 'nome' => $posto])['corpo'], 'Já existe um posto de serviço ativo') !== false, 'aceitou posto com nome repetido');
        garantir(strpos($enviar(['acao' => 'escalar', 'posto_id' => $postoId, 'milhao_aluno' => '00/0000'])['corpo'], 'Aluno não encontrado') !== false, 'aceitou aluno inexistente');

        // TESTE ALFA assume; TESTE BRAVO rende o ALFA no meio do serviço.
        semErroPhp($enviar(['acao' => 'escalar', 'posto_id' => $postoId, 'milhao_aluno' => '26/9001']));
        garantir($deServico() === ['TESTE ALFA'], 'ALFA deveria estar de serviço: ' . implode(', ', $deServico()));

        $escalaAlfa = (int) sqlLinha("SELECT id FROM posto_servico_escalas WHERE posto_servico_id = $postoId AND fim IS NULL")['id'];
        semErroPhp($enviar(['acao' => 'escalar', 'posto_id' => $postoId, 'milhao_aluno' => '26/9002', 'substitui_escala_id' => $escalaAlfa, 'motivo' => 'pane no meio do serviço']));
        garantir($deServico() === ['TESTE BRAVO'], 'BRAVO deveria ter rendido o ALFA: ' . implode(', ', $deServico()));
        $saida = sqlLinha("SELECT fim, motivo_saida FROM posto_servico_escalas WHERE id = $escalaAlfa");
        garantir($saida['fim'] !== null && $saida['motivo_saida'] === 'pane no meio do serviço', 'a saída do ALFA não ficou no histórico com o motivo');

        // A chamada aberta agora já sabe que o BRAVO está de serviço.
        $token = login(QR_PRATA_1)['token'];
        $retirada = api('POST', 'retiradas.php', $token, ['tipo' => 'almoco', 'agrupamento_tipo' => 'esquadrilha', 'agrupamento_valor' => 'A', 'esquadrao' => 'Esquadrão Prata']);
        garantirStatus($retirada, 201);
        $itens = array_column(api('GET', "retirada_itens.php?retirada_id={$retirada['json']['id']}", $token)['json'], null, 'nome_guerra');
        garantir((int) $itens['TESTE BRAVO']['presente'] === 0 && $itens['TESTE BRAVO']['motivo_codigo'] === 'SV', 'quem está de serviço deveria entrar na chamada como ausente por Serviço');
        garantir($itens['TESTE BRAVO']['servico_nome'] === $posto, 'a chamada não trouxe o posto de quem está de serviço');
        garantir((int) $itens['TESTE ALFA']['presente'] === 1, 'quem já foi rendido deveria entrar como presente');
        api('DELETE', "retiradas.php?id={$retirada['json']['id']}");

        // Encerrar tira do serviço; desativar o posto some com ele da lista.
        $escalaBravo = (int) sqlLinha("SELECT id FROM posto_servico_escalas WHERE posto_servico_id = $postoId AND fim IS NULL")['id'];
        semErroPhp($enviar(['acao' => 'encerrar', 'escala_id' => $escalaBravo, 'motivo' => 'fim do serviço']));
        garantir($deServico() === [], 'ainda há alguém de serviço depois de encerrar');
        $depois = $enviar(['acao' => 'desativar_posto', 'posto_id' => $postoId]);
        garantir(strpos($depois['corpo'], 'Posto desativado.') !== false, 'a tela não confirmou a desativação');
        garantir((int) sqlLinha("SELECT ativo FROM postos_servico WHERE id = $postoId")['ativo'] === 0, 'o posto continua ativo');

        sql("DELETE FROM posto_servico_escalas WHERE posto_servico_id = $postoId");
        sql("DELETE FROM postos_servico WHERE id = $postoId");
    });

    // QR code de ponta a ponta, pela tela: cadastra um QR de formato qualquer
    // (longo, com símbolos) pra um aluno, e ele passa a entrar no app com ele.
    teste('QR code: cadastrar um QR de qualquer formato, conferir, entrar no app, trocar e remover', function () use (&$cookiesPainel, $alunoId) {
        $enviar = fn(array $campos) => navegar('POST', '/web/painel/qrcodes.php', $cookiesPainel, $campos);
        $bravo = (int) sqlLinha("SELECT id FROM alunos WHERE identidade_militar = 'CI-0002'")['id'];
        $alfa = (int) sqlLinha("SELECT id FROM alunos WHERE identidade_militar = 'CI-0001'")['id'];
        // 256 caracteres, com espaço, acento e símbolos — nada a ver com o formato antigo.
        $qr = 'FAB|EEAR|ALUNO: João D\'Ávila|' . str_repeat('A1b2/+=', 32);
        $qr = substr($qr, 0, 256);
        $outroQr = 'https://exemplo.test/identidade?id=' . str_repeat('9', 40);

        try {
            // Conferir antes de cadastrar: ninguém, e a tela diz o tamanho.
            $r = $enviar(['acao' => 'conferir', 'conteudo' => $qr]);
            semErroPhp($r);
            garantir(strpos($r['corpo'], 'ainda não está cadastrado') !== false, 'a conferência deveria dizer que o QR não é de ninguém');
            garantir(strpos($r['corpo'], mb_strlen($qr) . ' caracteres') !== false, 'a conferência deveria mostrar o tamanho do conteúdo');
            garantir(strpos($r['corpo'], 'A1b2/+=') === false, 'a tela devolveu o conteúdo do QR');

            // Cadastra pro BRAVO.
            $r = $enviar(['acao' => 'definir', 'aluno_id' => $bravo, 'conteudo' => $qr]);
            semErroPhp($r);
            garantir(strpos($r['corpo'], 'QR trocado para') !== false, 'a tela não confirmou a troca do QR (o BRAVO já tinha um)');
            $gravado = sqlLinha("SELECT qrcode_hash FROM alunos WHERE id = $bravo")['qrcode_hash'];
            garantir($gravado === hash('sha256', $qr), 'o que ficou no banco não é a impressão digital (sha256) do conteúdo');

            // O mesmo QR não serve pra outro aluno.
            garantir(strpos($enviar(['acao' => 'definir', 'aluno_id' => $alfa, 'conteudo' => $qr])['corpo'], 'já está cadastrado para') !== false, 'aceitou o mesmo QR pra dois alunos');

            // Ele entra no app com o conteúdo lido; o QR antigo dele parou de valer.
            $sessao = api('POST', 'sessao.php', null, ['qrcode' => $qr]);
            garantirStatus($sessao, 201);
            garantir($sessao['json']['aluno']['nome_guerra'] === 'TESTE BRAVO', 'entrou como outro aluno');
            garantirStatus(api('POST', 'sessao.php', null, ['qrcode' => "  $qr\n"]), 201);
            garantirStatus(api('POST', 'sessao.php', null, ['qrcode' => QR_PRATA_2]), 401);
            garantirStatus(api('POST', 'sessao.php', null, ['qrcode' => $qr . 'x']), 401);

            // Conferir agora diz de quem é.
            garantir(strpos($enviar(['acao' => 'conferir', 'conteudo' => $qr])['corpo'], 'TESTE BRAVO') !== false, 'a conferência deveria dizer que o QR é do BRAVO');

            // Trocar o QR derruba a sessão aberta com o antigo.
            semErroPhp($enviar(['acao' => 'definir', 'aluno_id' => $bravo, 'conteudo' => $outroQr]));
            garantirStatus(api('GET', 'motivos.php', $sessao['json']['token']), 401);
            garantirStatus(api('POST', 'sessao.php', null, ['qrcode' => $outroQr]), 201);

            // Remover: não entra mais.
            $r = $enviar(['acao' => 'remover', 'aluno_id' => $bravo]);
            garantir(strpos($r['corpo'], 'QR removido de') !== false, 'a tela não confirmou a remoção');
            garantirStatus(api('POST', 'sessao.php', null, ['qrcode' => $outroQr]), 401);

            // Lote: uma linha boa, uma de aluno que não existe.
            $r = $enviar(['acao' => 'importar', 'lote' => "26/9002;$qr\n00/0000;qualquer coisa\nlinha sem separador"]);
            semErroPhp($r);
            garantir(strpos($r['corpo'], '1 QR(s) cadastrado(s) em lote') !== false, 'o lote deveria ter gravado 1 linha');
            garantir(strpos($r['corpo'], 'Linha 2') !== false && strpos($r['corpo'], 'Linha 3') !== false, 'o lote deveria apontar as linhas com problema');
            garantirStatus(api('POST', 'sessao.php', null, ['qrcode' => $qr]), 201);
        } finally {
            // Devolve o QR da fixture, que os outros testes usam.
            sql("DELETE FROM api_sessoes WHERE aluno_id = $bravo");
            sql("UPDATE alunos SET qrcode_hash = '" . QR_PRATA_2 . "' WHERE id = $bravo");
            sql("DELETE FROM api_logs WHERE status_code = 401");
        }
    });

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
        'index.php', 'api.php', 'qrcodes.php', 'usuarios.php', 'banco.php', 'backup.php', 'importar.php',
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
