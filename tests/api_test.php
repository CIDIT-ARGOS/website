<?php

// Testes da API do Argos, endpoint por endpoint: o que tests/integracao_test.php
// não cobre (ele segue o fluxo da PWA) — CRUD administrativo de alunos, grupos,
// usuários do Painel, relatórios, situação, dispensas médicas, Livro do Dia, os
// casos de erro de retiradas e o que é comum a todos (CORS, log, sessão
// expirada, rate limit).
//
// Uso:
//   php tests/api_test.php http://127.0.0.1:8000
//
// Além do HTTP, alguns testes mexem direto no banco (expirar uma sessão,
// simular IP bloqueado, conferir o hash de uma senha) — coisas que a API de
// propósito não deixa fazer. Conexão pelas mesmas variáveis do tests/ci_banco.php:
//   DB_HOST (127.0.0.1), DB_PORTA (3306), DB_ROOT_SENHA (argos_root), DB_NOME (argos_teste)
//
// ATENÇÃO: cria e apaga alunos, grupos, usuários e retiradas. Rode só contra
// um banco descartável (CI ou Docker local), nunca contra produção.

require __DIR__ . '/lib_teste.php';

const ESQUADRAO_TESTE = 'Esquadrão Teste API';
const GRUPO_TESTE = 'Grupo Teste API';

// Apaga o que uma execução anterior deste arquivo deixou, pra dar pra rodar
// de novo no mesmo banco (no CI o banco é recriado, então não acha nada).
function limparDadosDoTeste() {
    $esquadrao = mysqli_real_escape_string(banco(), ESQUADRAO_TESTE);
    $grupo = mysqli_real_escape_string(banco(), GRUPO_TESTE);
    $retiradas = "SELECT id FROM (SELECT id FROM retiradas WHERE esquadrao = '$esquadrao' OR agrupamento_valor = '$grupo') r";
    $alunos = "SELECT id FROM (SELECT id FROM alunos WHERE identidade_militar LIKE 'API-%') a";

    sql("DELETE FROM retirada_itens WHERE retirada_id IN ($retiradas) OR aluno_id IN ($alunos)");
    sql("DELETE FROM retiradas WHERE id IN ($retiradas)");
    sql("DELETE FROM api_sessoes WHERE aluno_id IN ($alunos)");
    sql("DELETE FROM grupo_membros WHERE aluno_id IN ($alunos)");
    sql("DELETE FROM dispensa_dispensa_tipos WHERE dispensa_id IN (SELECT id FROM dispensas WHERE aluno_id IN ($alunos))");
    sql("DELETE FROM dispensas WHERE aluno_id IN ($alunos)");
    sql("DELETE FROM alunos WHERE identidade_militar LIKE 'API-%'");
    sql("DELETE FROM grupo_membros WHERE grupo_id IN (SELECT id FROM grupos WHERE nome = '$grupo')");
    sql("DELETE FROM grupos WHERE nome = '$grupo'");
    sql("DELETE FROM motivos_falta WHERE nome = '$grupo'");
    sql("DELETE FROM painel_usuarios WHERE usuario LIKE 'api.teste.%'");
    sql("DELETE FROM api_chaves WHERE nome = 'Chave inativa (teste)'");
    sql("DELETE FROM api_logs WHERE endpoint = 'teste-rate-limit'");
}

function dadosAluno($sufixo, $nomeGuerra, array $extra = []) {
    return $extra + [
        'posto_graduacao' => 'GS',
        'especialidade' => 'SIN',
        'nome_guerra' => $nomeGuerra,
        'sexo' => 'M',
        'identidade_militar' => "API-$sufixo",
        'milhao' => "26/91$sufixo",
        'esquadrao' => ESQUADRAO_TESTE,
        'esquadrilha' => 'Z',
        'curso' => 'CFS',
        'serie' => '2',
        'qrcode_hash' => hash('sha256', "api-teste-$sufixo"),
    ];
}

function porChave(array $linhas, $chave, $valor) {
    foreach ($linhas as $linha) {
        if ((string) ($linha[$chave] ?? '') === (string) $valor) {
            return $linha;
        }
    }
    return null;
}

echo "Argos — testes da API contra $baseUrl\n";

try {
    limparDadosDoTeste();
} catch (Throwable $e) {
    echo "\n" . $e->getMessage() . "\n";
    exit(1);
}

// =====================================================================
secao('Comum a todos os endpoints (api/bootstrap.php)');

teste('preflight OPTIONS responde 204 com os headers de CORS, sem pedir chave', function () {
    $r = requisicao('OPTIONS', '/api/alunos.php');
    garantirStatus($r, 204);
    garantir(($r['headers']['access-control-allow-origin'] ?? '') === '*', 'Access-Control-Allow-Origin ausente');
    foreach (['X-API-Key', 'X-Session-Token'] as $header) {
        garantir(stripos($r['headers']['access-control-allow-headers'] ?? '', $header) !== false, "Access-Control-Allow-Headers sem $header");
    }
    foreach (['GET', 'POST', 'PUT', 'DELETE'] as $metodo) {
        garantir(strpos($r['headers']['access-control-allow-methods'] ?? '', $metodo) !== false, "Access-Control-Allow-Methods sem $metodo");
    }
});

teste('erro vem em JSON, com o campo "erro"', function () {
    $r = api('DELETE', 'motivos.php');
    garantirStatus($r, 405);
    garantir(strpos($r['headers']['content-type'] ?? '', 'application/json') !== false, 'Content-Type não é JSON');
    garantir(is_string($r['json']['erro'] ?? null) && $r['json']['erro'] !== '', 'corpo sem o campo erro');
});

teste('chave desativada é recusada (401)', function () {
    $chave = 'chave-inativa-do-teste-de-api';
    sql("INSERT INTO api_chaves (nome, chave_hash, chave_preview, ativo) VALUES ('Chave inativa (teste)', '" . hash('sha256', $chave) . "', '...api', 0)");
    garantirStatus(api('GET', 'situacao.php', null, null, $chave), 401);
});

$ipDoTeste = null;
teste('cada requisição fica registrada em api_logs', function () use (&$ipDoTeste) {
    garantirStatus(api('GET', 'situacao.php'), 200);
    $log = sqlLinha("SELECT * FROM api_logs ORDER BY id DESC LIMIT 1");
    garantir($log['endpoint'] === 'situacao.php', "endpoint registrado errado: {$log['endpoint']}");
    garantir($log['metodo'] === 'GET' && (int) $log['status_code'] === 200, "método/status errados: {$log['metodo']} {$log['status_code']}");
    garantir($log['api_chave_id'] !== null, 'log sem a chave que fez a requisição');
    garantir(!empty($log['ip']), 'log sem IP');
    $ipDoTeste = $log['ip'];
});

teste('usar a chave atualiza o ultimo_uso dela', function () use ($apiKey) {
    $chave = sqlLinha("SELECT ultimo_uso FROM api_chaves WHERE chave_hash = '" . hash('sha256', $apiKey) . "'");
    garantir(!empty($chave['ultimo_uso']), 'ultimo_uso continua vazio');
});

$sessaoPrata = null;
teste('login dos alunos da fixture (pré-requisito das rotas com sessão)', function () use (&$sessaoPrata) {
    $sessaoPrata = login(QR_PRATA_1);
});
if (!$sessaoPrata) {
    echo "\nSem sessão de aluno não dá pra seguir.\n";
    exit(1);
}
$tokenPrata = $sessaoPrata['token'];

// =====================================================================
secao('Alunos — criar (POST /api/alunos.php)');

teste('campo obrigatório ausente é recusado (400) e diz qual', function () {
    $dados = dadosAluno('01', 'API DELTA');
    unset($dados['milhao']);
    $r = api('POST', 'alunos.php', null, $dados);
    garantirStatus($r, 400);
    garantir(strpos($r['json']['erro'], 'milhao') !== false, 'mensagem não cita o campo: ' . $r['json']['erro']);
});

foreach ([
    'sexo fora de M/F' => ['sexo' => 'X'],
    'curso inexistente' => ['curso' => 'CFOAV'],
    'série inválida pro CFS' => ['serie' => '9'],
    'EAGS com série diferente de EAGS' => ['curso' => 'EAGS', 'serie' => '1'],
    'qrcode_hash fora do formato sha256' => ['qrcode_hash' => 'abc123'],
    'nome de guerra maior que a coluna' => ['nome_guerra' => str_repeat('N', 51)],
] as $caso => $invalido) {
    teste("$caso é recusado (400)", function () use ($invalido) {
        garantirStatus(api('POST', 'alunos.php', null, dadosAluno('01', 'API DELTA', $invalido)), 400);
    });
}

$alunos = [];
teste('aluno válido é criado (201) e devolve o id', function () use (&$alunos) {
    foreach (['01' => 'API DELTA', '02' => 'API ECHO', '03' => 'API FOXTROT'] as $sufixo => $nome) {
        $r = api('POST', 'alunos.php', null, dadosAluno($sufixo, $nome));
        garantirStatus($r, 201);
        garantir((int) ($r['json']['id'] ?? 0) > 0, 'id do aluno ausente');
        $alunos[$nome] = (int) $r['json']['id'];
    }
});
if (count($alunos) !== 3) {
    echo "\nSem os alunos de teste não dá pra seguir.\n";
    exit(1);
}

teste('identidade militar repetida é recusada (409)', function () {
    garantirStatus(api('POST', 'alunos.php', null, dadosAluno('01', 'API CLONE', [
        'milhao' => '26/9199', 'qrcode_hash' => hash('sha256', 'api-teste-clone'),
    ])), 409);
});

teste('qrcode_hash repetido é recusado (409)', function () {
    garantirStatus(api('POST', 'alunos.php', null, dadosAluno('99', 'API CLONE', [
        'qrcode_hash' => hash('sha256', 'api-teste-01'),
    ])), 409);
});

$tokenDelta = null;
$tokenEcho = null;
teste('aluno criado pela API loga com o QR dele', function () use (&$tokenDelta, &$tokenEcho) {
    $sessao = login(hash('sha256', 'api-teste-01'));
    garantir($sessao['aluno']['nome_guerra'] === 'API DELTA', 'sessão de outro aluno');
    garantir($sessao['aluno']['esquadrao'] === ESQUADRAO_TESTE, 'esquadrão errado na sessão');
    $tokenDelta = $sessao['token'];
    $tokenEcho = login(hash('sha256', 'api-teste-02'))['token'];
});
if (!$tokenDelta || !$tokenEcho) {
    echo "\nSem sessão dos alunos de teste não dá pra seguir.\n";
    exit(1);
}

teste('GET ?id= devolve os dados gravados', function () use ($tokenEcho, &$alunos) {
    $r = api('GET', 'alunos.php?id=' . $alunos['API DELTA'], $tokenEcho);
    garantirStatus($r, 200);
    garantir($r['json']['identidade_militar'] === 'API-01' && $r['json']['milhao'] === '26/9101', 'dados diferentes dos enviados');
    garantir((int) $r['json']['ativo'] === 1, 'aluno novo deveria nascer ativo');
});

teste('filtro ?esquadrilha= funciona dentro do esquadrão da sessão', function () use ($tokenEcho) {
    garantir(count(api('GET', 'alunos.php?esquadrilha=Z', $tokenEcho)['json']) === 3, 'esquadrilha Z deveria ter os 3 alunos de teste');
    garantir(api('GET', 'alunos.php?esquadrilha=NAO-EXISTE', $tokenEcho)['json'] === [], 'esquadrilha inexistente deveria vir vazia');
});

teste('método não suportado responde 405', function () {
    garantirStatus(api('PATCH', 'alunos.php'), 405);
});

// =====================================================================
secao('Situação — antes de qualquer chamada enviada (GET /api/situacao.php)');

$filtroEsquadrao = 'esquadrao=' . rawurlencode(ESQUADRAO_TESTE);

teste('efetivo sem chamada enviada aparece como "sem registro"', function () use ($filtroEsquadrao) {
    $r = api('GET', "situacao.php?$filtroEsquadrao");
    garantirStatus($r, 200);
    $resumo = $r['json']['resumo'];
    garantir($resumo['total_efetivo'] === 3 && $resumo['sem_registro'] === 3, 'resumo inesperado: ' . json_encode($resumo));
    garantir($resumo['presentes'] === 0 && $resumo['ausentes'] === 0, 'ninguém deveria contar como presente/ausente ainda');
    garantir(count($r['json']['alunos']) === 3, 'lista de alunos incompleta');
});

// =====================================================================
secao('Retiradas — validações e casos de erro');

$abrir = fn(array $dados) => api('POST', 'retiradas.php', $tokenEcho, $dados + [
    'tipo' => '1_jornada', 'agrupamento_tipo' => 'esquadrilha', 'agrupamento_valor' => 'Z', 'esquadrao' => ESQUADRAO_TESTE,
]);

teste('campo obrigatório ausente é recusado (400)', function () use ($tokenEcho) {
    $r = api('POST', 'retiradas.php', $tokenEcho, ['agrupamento_tipo' => 'esquadrilha', 'agrupamento_valor' => 'Z', 'esquadrao' => ESQUADRAO_TESTE]);
    garantirStatus($r, 400);
    garantir(strpos($r['json']['erro'], 'tipo') !== false, 'mensagem não cita o campo: ' . $r['json']['erro']);
});

teste('agrupamento ainda não suportado é recusado (400)', function () use ($abrir) {
    garantirStatus($abrir(['agrupamento_tipo' => 'especialidade', 'agrupamento_valor' => 'SIN']), 400);
});

teste('esquadrilha sem nenhum aluno ativo é recusada (400)', function () use ($abrir) {
    garantirStatus($abrir(['agrupamento_valor' => 'NAO-EXISTE']), 400);
});

teste('grupo inexistente é recusado (400)', function () use ($abrir) {
    garantirStatus($abrir(['agrupamento_tipo' => 'grupo', 'agrupamento_valor' => 'Grupo Que Não Existe']), 400);
});

$retiradaId = null;
teste('retirada aberta fica no nome de quem está logado', function () use ($abrir, &$retiradaId) {
    $r = $abrir(['responsavel_nome' => 'OUTRA PESSOA']);
    garantirStatus($r, 201);
    $retiradaId = (int) $r['json']['id'];

    $retirada = api('GET', "retiradas.php?id=$retiradaId")['json'];
    garantir(strpos($retirada['responsavel_nome'], 'API ECHO') !== false, "responsável deveria ser o aluno da sessão: {$retirada['responsavel_nome']}");
    garantir(strpos($retirada['responsavel_nome'], 'OUTRA PESSOA') === false, 'responsavel_nome do cliente foi aceito');
});
if (!$retiradaId) {
    echo "\nSem retirada aberta não dá pra seguir.\n";
    exit(1);
}

teste('retirada inexistente responde 404', function () use ($tokenEcho) {
    garantirStatus(api('GET', 'retiradas.php?id=999999'), 404);
    garantirStatus(api('PUT', 'retiradas.php?id=999999&acao=enviar', $tokenEcho), 404);
    garantirStatus(api('GET', 'retirada_itens.php?retirada_id=999999', $tokenEcho), 404);
    garantirStatus(api('PUT', 'retirada_itens.php?retirada_id=999999&aluno_id=1', $tokenEcho, ['presente' => 1]), 404);
});

teste('PUT em retiradas.php sem id ou sem acao=enviar é recusado (400)', function () use ($tokenEcho, &$retiradaId) {
    garantirStatus(api('PUT', 'retiradas.php?acao=enviar', $tokenEcho), 400);
    garantirStatus(api('PUT', "retiradas.php?id=$retiradaId", $tokenEcho), 400);
    garantir(api('GET', "retiradas.php?id=$retiradaId")['json']['status'] === 'pendente', 'a retirada foi enviada mesmo sem acao=enviar');
});

teste('retirada_itens.php sem os parâmetros é recusado (400)', function () use ($tokenEcho, &$retiradaId, &$alunos) {
    garantirStatus(api('GET', 'retirada_itens.php', $tokenEcho), 400);
    garantirStatus(api('PUT', "retirada_itens.php?retirada_id=$retiradaId", $tokenEcho, ['presente' => 1]), 400);
    garantirStatus(api('PUT', "retirada_itens.php?retirada_id=$retiradaId&aluno_id={$alunos['API DELTA']}", $tokenEcho, ['observacao' => 'sem o campo presente']), 400);
    garantirStatus(api('DELETE', "retirada_itens.php?retirada_id=$retiradaId", $tokenEcho), 405);
});

$motivoFalta = null;
teste('falta marcada pode voltar pra presente, e o motivo é limpo', function () use ($tokenEcho, &$retiradaId, &$alunos, &$motivoFalta) {
    $motivoFalta = porChave(api('GET', 'motivos.php', $tokenEcho)['json'], 'codigo', 'FALT');
    garantir($motivoFalta, 'motivo FALT não existe no seed');
    $url = "retirada_itens.php?retirada_id=$retiradaId&aluno_id={$alunos['API DELTA']}";

    garantirStatus(api('PUT', $url, $tokenEcho, ['presente' => 0, 'motivo_falta_id' => (int) $motivoFalta['id']]), 200);
    garantirStatus(api('PUT', $url, $tokenEcho, ['presente' => 1, 'motivo_falta_id' => (int) $motivoFalta['id']]), 200);

    $item = porChave(api('GET', "retirada_itens.php?retirada_id=$retiradaId", $tokenEcho)['json'], 'aluno_id', $alunos['API DELTA']);
    garantir((int) $item['presente'] === 1, 'aluno não voltou pra presente');
    garantir($item['motivo_falta_id'] === null, 'presente ficou com motivo de falta');
});

teste('observação gigante é cortada em 500 caracteres', function () use ($tokenEcho, &$retiradaId, &$alunos, &$motivoFalta) {
    garantirStatus(api('PUT', "retirada_itens.php?retirada_id=$retiradaId&aluno_id={$alunos['API FOXTROT']}", $tokenEcho, [
        'presente' => 0, 'motivo_falta_id' => (int) $motivoFalta['id'], 'observacao' => str_repeat('x', 600),
    ]), 200);
    $item = porChave(api('GET', "retirada_itens.php?retirada_id=$retiradaId", $tokenEcho)['json'], 'aluno_id', $alunos['API FOXTROT']);
    garantir(mb_strlen($item['observacao']) === 500, 'observação ficou com ' . mb_strlen($item['observacao']) . ' caracteres');
    garantir($item['motivo_codigo'] === 'FALT', 'item não traz o código do motivo');
});

$protocolo = null;
teste('filtros ?status= e ?tipo= da listagem', function () use ($tokenEcho, &$retiradaId, &$protocolo, $filtroEsquadrao) {
    $ids = fn($query) => array_map('intval', array_column(api('GET', "retiradas.php?$filtroEsquadrao&$query")['json'], 'id'));

    garantir(in_array($retiradaId, $ids('status=pendente')), 'retirada pendente fora do filtro status=pendente');
    garantir(!in_array($retiradaId, $ids('status=enviada')), 'retirada pendente apareceu como enviada');

    $envio = api('PUT', "retiradas.php?id=$retiradaId&acao=enviar", $tokenEcho);
    garantirStatus($envio, 200);
    $protocolo = $envio['json']['protocolo'];

    garantir(in_array($retiradaId, $ids('status=enviada&tipo=1_jornada')), 'retirada enviada fora do filtro status=enviada&tipo=1_jornada');
    garantir(!in_array($retiradaId, $ids('status=pendente')), 'retirada enviada continua como pendente');
    garantir(!in_array($retiradaId, $ids('tipo=pernoite')), 'filtro ?tipo= não filtrou');
});

teste('excluir retirada apaga ela e os itens; de novo responde 404', function () use ($abrir, $tokenEcho) {
    garantirStatus(api('DELETE', 'retiradas.php'), 400);

    $r = $abrir(['tipo' => '2_jornada']);
    garantirStatus($r, 201);
    $id = (int) $r['json']['id'];

    $excluir = api('DELETE', "retiradas.php?id=$id");
    garantirStatus($excluir, 200);
    garantir(($excluir['json']['excluida'] ?? false) === true, 'resposta sem excluida=true');
    garantirStatus(api('GET', "retiradas.php?id=$id"), 404);
    garantir((int) sqlLinha("SELECT COUNT(*) AS total FROM retirada_itens WHERE retirada_id = $id")['total'] === 0, 'sobraram itens órfãos');
    garantirStatus(api('DELETE', "retiradas.php?id=$id"), 404);
});

// =====================================================================
secao('Situação e relatórios — depois da chamada enviada');

teste('situacao.php reflete a última chamada enviada', function () use ($filtroEsquadrao) {
    $r = api('GET', "situacao.php?$filtroEsquadrao");
    garantirStatus($r, 200);
    $resumo = $r['json']['resumo'];
    garantir(
        $resumo['total_efetivo'] === 3 && $resumo['presentes'] === 2 && $resumo['ausentes'] === 1 && $resumo['sem_registro'] === 0,
        'resumo inesperado: ' . json_encode($resumo)
    );
    garantir(($resumo['por_motivo']['Falta'] ?? 0) === 1, 'por_motivo deveria ter 1 "Falta": ' . json_encode($resumo['por_motivo']));

    $foxtrot = porChave($r['json']['alunos'], 'nome_guerra', 'API FOXTROT');
    garantir((int) $foxtrot['presente'] === 0 && $foxtrot['motivo_codigo'] === 'FALT', 'FOXTROT deveria estar ausente por FALT');
    garantir($foxtrot['retirada_tipo'] === '1_jornada', 'tipo da retirada de origem errado');
    garantir((int) porChave($r['json']['alunos'], 'nome_guerra', 'API ECHO')['presente'] === 1, 'ECHO deveria estar presente');
});

teste('situacao.php: filtros ?somente_ausentes, ?busca= e ?esquadrilha=', function () use ($filtroEsquadrao) {
    $ausentes = api('GET', "situacao.php?$filtroEsquadrao&somente_ausentes=1")['json'];
    garantir(array_column($ausentes['alunos'], 'nome_guerra') === ['API FOXTROT'], 'somente_ausentes deveria trazer só o FOXTROT');
    garantir($ausentes['resumo']['total_efetivo'] === 3, 'o resumo não deveria ser afetado por somente_ausentes');

    $busca = api('GET', "situacao.php?$filtroEsquadrao&busca=ECHO")['json'];
    garantir(array_column($busca['alunos'], 'nome_guerra') === ['API ECHO'], 'busca por nome não filtrou');

    garantir(api('GET', "situacao.php?$filtroEsquadrao&esquadrilha=NAO-EXISTE")['json']['alunos'] === [], 'esquadrilha inexistente deveria vir vazia');
});

teste('relatorios.php soma presenças e faltas do período', function () use ($filtroEsquadrao, &$retiradaId, &$protocolo) {
    $r = api('GET', "relatorios.php?$filtroEsquadrao");
    garantirStatus($r, 200);
    foreach (['resumo', 'por_motivo', 'retiradas'] as $bloco) {
        garantir(array_key_exists($bloco, $r['json']), "resposta sem o bloco $bloco");
    }

    $resumo = $r['json']['resumo'];
    garantir(
        $resumo['total_itens'] === 3 && $resumo['total_presentes'] === 2 && $resumo['total_faltas'] === 1,
        'resumo inesperado: ' . json_encode($resumo)
    );
    garantir(abs($resumo['percentual_presenca'] - 66.7) < 0.01, "percentual_presenca deveria ser 66.7: {$resumo['percentual_presenca']}");

    $porMotivo = porChave($r['json']['por_motivo'], 'motivo', 'Falta');
    garantir($porMotivo && (int) $porMotivo['total'] === 1, 'por_motivo deveria ter 1 "Falta": ' . json_encode($r['json']['por_motivo']));

    $linha = porChave($r['json']['retiradas'], 'id', $retiradaId);
    garantir($linha, 'retirada enviada não aparece no relatório');
    garantir($linha['protocolo'] === $protocolo && $linha['status'] === 'enviada', 'protocolo/status da retirada errados');
    garantir((int) $linha['total_itens'] === 3 && (int) $linha['presentes'] === 2 && (int) $linha['faltas'] === 1, 'totais da retirada errados: ' . json_encode($linha));
});

teste('relatorios.php: período sem chamada vem zerado, sem dividir por zero', function () use ($filtroEsquadrao) {
    $r = api('GET', "relatorios.php?$filtroEsquadrao&data_inicio=2000-01-01&data_fim=2000-01-31");
    garantirStatus($r, 200);
    garantir($r['json']['resumo']['total_itens'] === 0 && $r['json']['resumo']['percentual_presenca'] === null, 'resumo deveria vir zerado: ' . json_encode($r['json']['resumo']));
    garantir($r['json']['por_motivo'] === [] && $r['json']['retiradas'] === [], 'listas deveriam vir vazias');
});

teste('relatorios.php: filtro ?tipo=', function () use ($filtroEsquadrao) {
    garantir(api('GET', "relatorios.php?$filtroEsquadrao&tipo=pernoite")['json']['resumo']['total_itens'] === 0, 'tipo=pernoite deveria vir vazio');
    garantir(api('GET', "relatorios.php?$filtroEsquadrao&tipo=1_jornada")['json']['resumo']['total_itens'] === 3, 'tipo=1_jornada deveria trazer a chamada');
});

teste('situacao.php e relatorios.php só aceitam GET (405)', function () {
    garantirStatus(api('POST', 'situacao.php', null, []), 405);
    garantirStatus(api('POST', 'relatorios.php', null, []), 405);
});

// =====================================================================
secao('Dispensas médicas pelo app (GET/POST /api/dispensas.php)');

$hoje = date('Y-m-d');
$dispensa = fn(array $extra = []) => $extra + [
    'aluno_id' => $alunos['API FOXTROT'], 'data_inicio' => $hoje, 'data_termino' => $hoje,
    'numero' => '45/26', 'motivo' => 'Entorse de tornozelo (teste)',
];

teste('exige sessão de aluno (401)', function () use ($dispensa) {
    garantirStatus(api('GET', 'dispensas.php'), 401);
    garantirStatus(api('POST', 'dispensas.php', null, $dispensa()), 401);
});

$tiposDispensa = [];
teste('?tipos=1 devolve o catálogo de "dispensado de"', function () use ($tokenEcho, &$tiposDispensa) {
    $r = api('GET', 'dispensas.php?tipos=1', $tokenEcho);
    garantirStatus($r, 200);
    $tiposDispensa = $r['json'];
    garantir(count($tiposDispensa) > 0 && isset($tiposDispensa[0]['id'], $tiposDispensa[0]['nome']), 'catálogo vazio ou sem id/nome');
});

teste('esquadrão sem dispensa lançada devolve lista vazia', function () use ($tokenEcho) {
    $r = api('GET', 'dispensas.php', $tokenEcho);
    garantirStatus($r, 200);
    garantir($r['json'] === [], 'deveria vir vazio: ' . $r['corpo']);
});

teste('não lança dispensa pra aluno de outro esquadrão (404)', function () use ($tokenEcho, $dispensa) {
    $deOutroEsquadrao = (int) sqlLinha("SELECT id FROM alunos WHERE identidade_militar = 'CI-0001'")['id'];
    garantirStatus(api('POST', 'dispensas.php', $tokenEcho, $dispensa(['aluno_id' => $deOutroEsquadrao])), 404);
    garantirStatus(api('POST', 'dispensas.php', $tokenEcho, $dispensa(['aluno_id' => 999999])), 404);
});

teste('sem motivo, com término antes do início ou com campo fora do formato é recusada (400)', function () use ($tokenEcho, $dispensa, $hoje) {
    garantirStatus(api('POST', 'dispensas.php', $tokenEcho, $dispensa(['motivo' => ''])), 400);
    garantirStatus(api('POST', 'dispensas.php', $tokenEcho, $dispensa(['data_termino' => date('Y-m-d', strtotime("$hoje -1 day"))])), 400);
    garantirStatus(api('POST', 'dispensas.php', $tokenEcho, $dispensa(['data_inicio' => '2000-01-01', 'data_termino' => '2000-01-02'])), 400);
    garantirStatus(api('POST', 'dispensas.php', $tokenEcho, $dispensa(['motivo' => ['não', 'é', 'texto']])), 400);
});

teste('dispensa válida é lançada (201) e aparece na lista com o "dispensado de"', function () use ($tokenEcho, $dispensa, &$tiposDispensa) {
    $r = api('POST', 'dispensas.php', $tokenEcho, $dispensa(['dispensa_tipo_ids' => [(int) $tiposDispensa[0]['id']], 'dispensado_de' => 'corrida']));
    garantirStatus($r, 201);
    garantir((int) ($r['json']['id'] ?? 0) > 0, 'id da dispensa ausente');

    $lista = api('GET', 'dispensas.php', $tokenEcho)['json'];
    garantir(count($lista) === 1 && $lista[0]['nome_guerra'] === 'API FOXTROT', 'lista inesperada: ' . json_encode($lista));
    garantir($lista[0]['tags_nomes'] === $tiposDispensa[0]['nome'] && $lista[0]['dispensado_de'] === 'corrida', '"dispensado de" não foi gravado');
    garantir($lista[0]['numero'] === '45/26', 'número da dispensa não foi gravado');
});

teste('dispensa idêntica não é lançada duas vezes (400)', function () use ($tokenEcho, $dispensa) {
    garantirStatus(api('POST', 'dispensas.php', $tokenEcho, $dispensa()), 400);
});

teste('outro esquadrão não vê a dispensa', function () use ($tokenPrata) {
    garantir(porChave(api('GET', 'dispensas.php?todas=1', $tokenPrata)['json'], 'nome_guerra', 'API FOXTROT') === null, 'dispensa vazou pra outro esquadrão');
});

teste('método não suportado responde 405', function () use ($tokenEcho) {
    garantirStatus(api('DELETE', 'dispensas.php', $tokenEcho), 405);
});

// =====================================================================
secao('Livro do Dia (GET /api/livro.php)');

teste('exige sessão de aluno (401) e só aceita GET (405)', function () use ($tokenEcho) {
    garantirStatus(api('GET', 'livro.php'), 401);
    garantirStatus(api('POST', 'livro.php', $tokenEcho, []), 405);
});

teste('data fora do formato é recusada (400)', function () use ($tokenEcho) {
    garantirStatus(api('GET', 'livro.php?data=10/10/2026', $tokenEcho), 400);
    garantirStatus(api('GET', 'livro.php?data=2026-02-31', $tokenEcho), 400);
});

teste('livro de hoje traz a chamada enviada, a situação por extenso e a dispensa', function () use ($tokenEcho, $hoje) {
    $r = api('GET', 'livro.php', $tokenEcho);
    garantirStatus($r, 200);
    $livro = $r['json'];
    garantir($livro['data'] === $hoje && $livro['esquadrao'] === ESQUADRAO_TESTE, 'data/esquadrão errados');

    $jornada = porChave($livro['secoes'], 'tipo', '1_jornada');
    garantir($jornada['chamadas_enviadas'] === 1, 'a 1ª Jornada deveria ter 1 chamada enviada');
    garantir(count($jornada['ausencias']) === 1, 'a 1ª Jornada deveria ter 1 ausência: ' . json_encode($jornada['ausencias']));
    garantir(strpos($jornada['ausencias'][0]['identificacao'], 'API FOXTROT') !== false, 'a ausência deveria ser do FOXTROT');
    garantir(strpos($jornada['ausencias'][0]['situacao'], 'Falta') === 0, "a situação deveria vir por extenso (\"Falta\"), veio: {$jornada['ausencias'][0]['situacao']}");

    garantir(porChave($livro['secoes'], 'tipo', 'pernoite')['chamadas_enviadas'] === 0, 'pernoite não teve chamada');
    garantir(count($livro['dispensas']) === 1 && strpos($livro['dispensas'][0]['identificacao'], 'API FOXTROT') !== false, 'a dispensa do FOXTROT deveria estar no livro');
});

teste('o texto pronto segue o padrão e distingue "não há" de "chamada não enviada"', function () use ($tokenEcho) {
    $texto = api('GET', 'livro.php', $tokenEcho)['json']['texto'];
    foreach (['CORPO DE ALUNOS', 'RESUMO DO DIA', "PERNOITE\nChamada não enviada.", 'API FOXTROT — Falta', 'OCORRÊNCIAS MÉDICAS', 'MOTIVO: Entorse de tornozelo (teste)'] as $trecho) {
        garantir(strpos($texto, $trecho) !== false, "o texto do livro não tem \"$trecho\":\n$texto");
    }
    garantir(strpos($texto, 'FALT)') === false && strpos($texto, '(FALT') === false, 'o texto ainda usa a sigla do motivo');
});

teste('dia sem chamada vem com todas as seções marcadas como não enviadas', function () use ($tokenEcho) {
    $livro = api('GET', 'livro.php?data=2000-01-01', $tokenEcho)['json'];
    garantir(array_sum(array_column($livro['secoes'], 'chamadas_enviadas')) === 0, 'não deveria haver chamada em 2000-01-01');
    garantir($livro['dispensas'] === [], 'não deveria haver dispensa em 2000-01-01');
    garantir($livro['data_extenso'] === '01 JAN 2000', "data por extenso errada: {$livro['data_extenso']}");
});

// =====================================================================
secao('Grupos (GET/POST/DELETE /api/grupos.php)');

teste('lista traz o grupo do seed (CIDIT)', function () {
    $r = api('GET', 'grupos.php');
    garantirStatus($r, 200);
    garantir(porChave($r['json'], 'nome', 'CIDIT') !== null, 'CIDIT não está na lista');
});

teste('grupo sem nome, com categoria inválida ou nome longo demais é recusado (400)', function () {
    garantirStatus(api('POST', 'grupos.php', null, ['categoria' => 'clube']), 400);
    garantirStatus(api('POST', 'grupos.php', null, ['nome' => GRUPO_TESTE]), 400);
    garantirStatus(api('POST', 'grupos.php', null, ['nome' => GRUPO_TESTE, 'categoria' => 'inventada']), 400);
    garantirStatus(api('POST', 'grupos.php', null, ['nome' => str_repeat('G', 51), 'categoria' => 'clube']), 400);
});

$grupoId = null;
teste('grupo válido é criado (201)', function () use (&$grupoId) {
    $r = api('POST', 'grupos.php', null, ['nome' => GRUPO_TESTE, 'categoria' => 'comissao']);
    garantirStatus($r, 201);
    $grupoId = (int) $r['json']['id'];
    garantir($grupoId > 0, 'id do grupo ausente');
});
if (!$grupoId) {
    echo "\nSem o grupo de teste não dá pra seguir.\n";
    exit(1);
}

teste('nome de grupo repetido é recusado (400)', function () {
    garantirStatus(api('POST', 'grupos.php', null, ['nome' => GRUPO_TESTE, 'categoria' => 'clube']), 400);
});

teste('grupo novo vira opção de motivo de falta', function () use ($tokenEcho) {
    garantir(porChave(api('GET', 'motivos.php', $tokenEcho)['json'], 'nome', GRUPO_TESTE) !== null, 'motivo com o nome do grupo não foi criado');
});

teste('GET ?id= traz o grupo com a lista de membros; inexistente responde 404', function () use (&$grupoId) {
    $r = api('GET', "grupos.php?id=$grupoId");
    garantirStatus($r, 200);
    garantir($r['json']['nome'] === GRUPO_TESTE && $r['json']['categoria'] === 'comissao', 'dados do grupo errados');
    garantir($r['json']['membros'] === [], 'grupo novo deveria nascer sem membros');
    garantirStatus(api('GET', 'grupos.php?id=999999'), 404);
});

teste('adicionar membro: valida aluno_id e grupo, e não duplica', function () use (&$grupoId, &$alunos) {
    garantirStatus(api('POST', "grupos.php?id=$grupoId", null, []), 400);
    garantirStatus(api('POST', 'grupos.php?id=999999', null, ['aluno_id' => $alunos['API ECHO']]), 404);

    foreach ([1, 2] as $_) {
        garantirStatus(api('POST', "grupos.php?id=$grupoId", null, ['aluno_id' => $alunos['API ECHO']]), 200);
    }
    $membros = api('GET', "grupos.php?id=$grupoId")['json']['membros'];
    garantir(array_column($membros, 'nome_guerra') === ['API ECHO'], 'membros inesperados: ' . json_encode(array_column($membros, 'nome_guerra')));
});

teste('retirada por grupo traz só os membros e não fica presa a um esquadrão', function () use ($abrir, $tokenEcho) {
    $r = $abrir(['agrupamento_tipo' => 'grupo', 'agrupamento_valor' => GRUPO_TESTE, 'esquadrao' => null]);
    garantirStatus($r, 201);
    $id = (int) $r['json']['id'];

    garantir(api('GET', "retiradas.php?id=$id")['json']['esquadrao'] === null, 'retirada de grupo deveria ter esquadrao nulo');
    $itens = api('GET', "retirada_itens.php?retirada_id=$id", $tokenEcho)['json'];
    garantir(array_column($itens, 'nome_guerra') === ['API ECHO'], 'itens deveriam ser só os membros do grupo');

    garantirStatus(api('DELETE', "retiradas.php?id=$id"), 200);
});

teste('remover membro exige id e aluno_id, e tira o aluno do grupo', function () use (&$grupoId, &$alunos) {
    garantirStatus(api('DELETE', "grupos.php?id=$grupoId"), 400);
    garantirStatus(api('DELETE', "grupos.php?aluno_id={$alunos['API ECHO']}"), 400);

    garantirStatus(api('DELETE', "grupos.php?id=$grupoId&aluno_id={$alunos['API ECHO']}"), 200);
    garantir(api('GET', "grupos.php?id=$grupoId")['json']['membros'] === [], 'membro continua no grupo');
});

teste('método não suportado responde 405', function () {
    garantirStatus(api('PUT', 'grupos.php', null, []), 405);
});

// =====================================================================
secao('Usuários do Painel (/api/painel_usuarios.php)');

$usuario = fn($login, array $extra = []) => $extra + [
    'nome' => 'Usuário de Teste da API', 'usuario' => "api.teste.$login", 'senha' => 'senha-inicial-1', 'cargo' => 'CMD_ESQUADRAO', 'esquadrao' => 'Esquadrão Prata',
];
$semSenha = function ($linha) {
    foreach (['senha_hash', 'senha'] as $campo) {
        garantir(!array_key_exists($campo, $linha), "resposta expõe $campo");
    }
};

teste('usuário incompleto, com cargo inválido ou cargo de esquadrão sem esquadrão é recusado (400)', function () use ($usuario) {
    garantirStatus(api('POST', 'painel_usuarios.php', null, $usuario('a', ['senha' => ''])), 400);
    garantirStatus(api('POST', 'painel_usuarios.php', null, $usuario('a', ['cargo' => 'GENERAL'])), 400);
    garantirStatus(api('POST', 'painel_usuarios.php', null, $usuario('a', ['esquadrao' => ''])), 400);
    garantirStatus(api('POST', 'painel_usuarios.php', null, $usuario('a', ['nome' => str_repeat('N', 101)])), 400);
});

$usuarioId = null;
teste('usuário válido é criado (201)', function () use ($usuario, &$usuarioId) {
    $r = api('POST', 'painel_usuarios.php', null, $usuario('a'));
    garantirStatus($r, 201);
    $usuarioId = (int) $r['json']['id'];
    garantir($usuarioId > 0, 'id do usuário ausente');
});
if (!$usuarioId) {
    echo "\nSem o usuário de teste não dá pra seguir.\n";
    exit(1);
}

teste('login repetido é recusado (400)', function () use ($usuario) {
    garantirStatus(api('POST', 'painel_usuarios.php', null, $usuario('a', ['nome' => 'Outro'])), 400);
});

teste('listar e buscar nunca expõem a senha', function () use (&$usuarioId, $semSenha) {
    $lista = api('GET', 'painel_usuarios.php');
    garantirStatus($lista, 200);
    garantir(porChave($lista['json'], 'id', $usuarioId) !== null, 'usuário criado não está na lista');
    array_map($semSenha, $lista['json']);

    $um = api('GET', "painel_usuarios.php?id=$usuarioId");
    garantirStatus($um, 200);
    $semSenha($um['json']);
    garantir($um['json']['usuario'] === 'api.teste.a' && $um['json']['cargo'] === 'CMD_ESQUADRAO', 'dados diferentes dos enviados');
    garantir($um['json']['esquadrao'] === 'Esquadrão Prata' && (int) $um['json']['ativo'] === 1, 'esquadrão/ativo errados');

    garantirStatus(api('GET', 'painel_usuarios.php?id=999999'), 404);
});

teste('a senha é guardada como hash, nunca em texto', function () use (&$usuarioId) {
    $hash = sqlLinha("SELECT senha_hash FROM painel_usuarios WHERE id = $usuarioId")['senha_hash'];
    garantir($hash !== 'senha-inicial-1' && password_verify('senha-inicial-1', $hash), 'senha_hash não confere com a senha enviada');
});

teste('cargo de CA ignora o esquadrão informado', function () use ($usuario) {
    $r = api('POST', 'painel_usuarios.php', null, $usuario('b', ['cargo' => 'AUX_CA']));
    garantirStatus($r, 201);
    garantir(api('GET', "painel_usuarios.php?id={$r['json']['id']}")['json']['esquadrao'] === null, 'cargo de CA ficou preso a um esquadrão');
});

teste('atualizar muda nome, cargo e ativo; sem id ou inexistente é recusado (400)', function () use (&$usuarioId) {
    $novo = ['nome' => 'Nome Atualizado', 'cargo' => 'AUX_ESQUADRAO', 'esquadrao' => 'Esquadrão Azul', 'ativo' => 0];
    garantirStatus(api('PUT', 'painel_usuarios.php', null, $novo), 400);
    garantirStatus(api('PUT', 'painel_usuarios.php?id=999999', null, $novo), 400);
    garantirStatus(api('PUT', "painel_usuarios.php?id=$usuarioId", null, ['esquadrao' => ''] + $novo), 400);

    garantirStatus(api('PUT', "painel_usuarios.php?id=$usuarioId", null, $novo), 200);
    $depois = api('GET', "painel_usuarios.php?id=$usuarioId")['json'];
    garantir($depois['nome'] === 'Nome Atualizado' && $depois['cargo'] === 'AUX_ESQUADRAO', 'nome/cargo não mudaram');
    garantir($depois['esquadrao'] === 'Esquadrão Azul' && (int) $depois['ativo'] === 0, 'esquadrão/ativo não mudaram');
    garantir($depois['usuario'] === 'api.teste.a', 'o login não deveria mudar no PUT');
});

teste('resetar senha: exige nova_senha com 6+ caracteres e troca o hash', function () use (&$usuarioId) {
    $url = "painel_usuarios.php?id=$usuarioId&acao=resetar_senha";
    garantirStatus(api('PUT', $url, null, []), 400);
    garantirStatus(api('PUT', $url, null, ['nova_senha' => '12345']), 400);

    garantirStatus(api('PUT', $url, null, ['nova_senha' => 'senha-nova-2']), 200);
    $hash = sqlLinha("SELECT senha_hash FROM painel_usuarios WHERE id = $usuarioId")['senha_hash'];
    garantir(password_verify('senha-nova-2', $hash), 'a senha nova não vale');
    garantir(!password_verify('senha-inicial-1', $hash), 'a senha antiga continua valendo');
});

teste('o último CMD_CA ativo não pode ser rebaixado, desativado nem excluído', function () use ($usuario) {
    garantir((int) sqlLinha("SELECT COUNT(*) AS total FROM painel_usuarios WHERE cargo = 'CMD_CA' AND ativo = 1")['total'] === 0, 'o banco de teste já tem CMD_CA — o cenário deste teste não se monta');

    $unico = api('POST', 'painel_usuarios.php', null, $usuario('cmd1', ['cargo' => 'CMD_CA']));
    garantirStatus($unico, 201);
    $id = (int) $unico['json']['id'];

    garantirStatus(api('PUT', "painel_usuarios.php?id=$id", null, ['nome' => 'Cmd', 'cargo' => 'AUX_CA', 'ativo' => 1]), 400);
    garantirStatus(api('PUT', "painel_usuarios.php?id=$id", null, ['nome' => 'Cmd', 'cargo' => 'CMD_CA', 'ativo' => 0]), 400);
    garantirStatus(api('DELETE', "painel_usuarios.php?id=$id"), 400);
    garantirStatus(api('GET', "painel_usuarios.php?id=$id"), 200);

    // Com um segundo CMD_CA ativo, o primeiro pode sair.
    garantirStatus(api('POST', 'painel_usuarios.php', null, $usuario('cmd2', ['cargo' => 'CMD_CA'])), 201);
    garantirStatus(api('DELETE', "painel_usuarios.php?id=$id"), 200);
    garantirStatus(api('GET', "painel_usuarios.php?id=$id"), 404);
});

teste('excluir exige id e remove o usuário', function () use (&$usuarioId) {
    garantirStatus(api('DELETE', 'painel_usuarios.php'), 400);
    $r = api('DELETE', "painel_usuarios.php?id=$usuarioId");
    garantirStatus($r, 200);
    garantir(($r['json']['excluido'] ?? false) === true, 'resposta sem excluido=true');
    garantirStatus(api('GET', "painel_usuarios.php?id=$usuarioId"), 404);
});

teste('método não suportado responde 405', function () {
    garantirStatus(api('PATCH', 'painel_usuarios.php', null, []), 405);
});

// =====================================================================
secao('Alunos — atualizar e inativar (PUT/DELETE /api/alunos.php)');

teste('PUT sem id ou com dado inválido é recusado (400)', function () use (&$alunos) {
    garantirStatus(api('PUT', 'alunos.php', null, dadosAluno('01', 'API DELTA')), 400);
    garantirStatus(api('PUT', "alunos.php?id={$alunos['API DELTA']}", null, dadosAluno('01', 'API DELTA', ['sexo' => 'X'])), 400);
    garantirStatus(api('PUT', "alunos.php?id={$alunos['API DELTA']}", null, ['nome_guerra' => 'SÓ O NOME']), 400);
});

teste('PUT atualiza os dados do aluno', function () use (&$alunos, $tokenEcho) {
    $r = api('PUT', "alunos.php?id={$alunos['API DELTA']}", null, dadosAluno('01', 'API DELTA EDITADO', ['sexo' => 'F', 'serie' => '3']));
    garantirStatus($r, 200);
    garantir(($r['json']['atualizado'] ?? false) === true, 'resposta sem atualizado=true');

    $depois = api('GET', "alunos.php?id={$alunos['API DELTA']}", $tokenEcho)['json'];
    garantir($depois['nome_guerra'] === 'API DELTA EDITADO' && $depois['sexo'] === 'F' && $depois['serie'] === '3', 'dados não mudaram: ' . json_encode($depois));
    garantir($depois['qrcode_hash'] === hash('sha256', 'api-teste-01'), 'qrcode_hash se perdeu no PUT');
});

teste('DELETE sem id é recusado (400)', function () {
    garantirStatus(api('DELETE', 'alunos.php'), 400);
});

teste('DELETE inativa (não apaga) e tira o aluno do efetivo', function () use (&$alunos, $tokenEcho) {
    $r = api('DELETE', "alunos.php?id={$alunos['API DELTA']}");
    garantirStatus($r, 200);
    garantir(($r['json']['inativado'] ?? false) === true, 'resposta sem inativado=true');

    $depois = api('GET', "alunos.php?id={$alunos['API DELTA']}", $tokenEcho);
    garantirStatus($depois, 200);
    garantir((int) $depois['json']['ativo'] === 0, 'aluno continua ativo');

    $efetivo = array_column(api('GET', 'alunos.php', $tokenEcho)['json'], 'nome_guerra');
    garantir(!in_array('API DELTA EDITADO', $efetivo) && in_array('API ECHO', $efetivo), 'efetivo inesperado: ' . implode(', ', $efetivo));
});

// =====================================================================
secao('Sessão do aluno — quando deixa de valer');

teste('aluno inativado perde a sessão aberta e não loga mais (401)', function () use ($tokenDelta) {
    garantirStatus(api('GET', 'motivos.php', $tokenDelta), 401);
    garantirStatus(api('POST', 'sessao.php', null, ['qrcode_hash' => hash('sha256', 'api-teste-01')]), 401);
});

teste('sessão expirada é recusada (401)', function () use ($tokenPrata) {
    garantirStatus(api('GET', 'motivos.php', $tokenPrata), 200);
    sql("UPDATE api_sessoes SET expira_em = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE token_hash = '" . hash('sha256', $tokenPrata) . "'");
    garantirStatus(api('GET', 'motivos.php', $tokenPrata), 401);
});

// =====================================================================
secao('Rate limit por IP (20 respostas 401 em 5 minutos)');

// Fica por último de propósito: no fim, apaga os 401 deste IP do api_logs —
// os de mentira e os que os testes acima geraram de verdade — pra não
// deixar o IP perto do limite pro que roda depois (smoke test).
teste('IP com 20 respostas 401 recentes é bloqueado (429), até com chave válida', function () use (&$ipDoTeste) {
    garantir($ipDoTeste, 'IP do teste desconhecido (o teste do api_logs falhou)');
    $ip = mysqli_real_escape_string(banco(), $ipDoTeste);

    try {
        sql("DELETE FROM api_logs WHERE status_code = 401 AND ip = '$ip'");
        $linhas = implode(', ', array_fill(0, 19, "('teste-rate-limit', 'GET', 401, '$ip')"));
        sql("INSERT INTO api_logs (endpoint, metodo, status_code, ip) VALUES $linhas");
        garantirStatus(api('GET', 'situacao.php'), 200);

        sql("INSERT INTO api_logs (endpoint, metodo, status_code, ip) VALUES ('teste-rate-limit', 'GET', 401, '$ip')");
        $bloqueado = api('GET', 'situacao.php');
        garantirStatus($bloqueado, 429);
        garantir(!empty($bloqueado['json']['erro']), 'resposta 429 sem mensagem');

        // 401 antigo (fora da janela de 5 minutos) não conta.
        sql("UPDATE api_logs SET criado_em = DATE_SUB(NOW(), INTERVAL 6 MINUTE) WHERE endpoint = 'teste-rate-limit' LIMIT 1");
        garantirStatus(api('GET', 'situacao.php'), 200);
    } finally {
        sql("DELETE FROM api_logs WHERE status_code = 401 AND ip = '$ip'");
    }
});

limparDadosDoTeste();
encerrarTestes();
