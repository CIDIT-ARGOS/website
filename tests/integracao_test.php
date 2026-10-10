<?php

// Testes de integração do Argos: sobem contra um servidor de verdade (PHP +
// MySQL com database/init_db.sql + tests/fixtures/dados_teste.sql) e exercitam
// a API pelo HTTP, do mesmo jeito que a PWA "Área Funcional" faz.
//
// Uso:
//   php tests/integracao_test.php http://127.0.0.1:8000
//
// Variáveis de ambiente opcionais:
//   ARGOS_API_KEY  chave de API válida (padrão: a chave legada seedada no init_db.sql)
//
// Sem dependências (nem curl, nem PHPUnit) — só PHP puro, igual ao resto do
// projeto. Sai com código 1 se qualquer teste falhar.
//
// ATENÇÃO: cria retiradas e sessões no banco. Rode só contra um banco
// descartável (CI ou Docker local), nunca contra produção.

require __DIR__ . '/lib_teste.php';

echo "Argos — testes de integração contra $baseUrl\n";

// =====================================================================
secao('API — autenticação');

teste('index.php responde e se identifica', function () {
    $r = requisicao('GET', '/api/index.php');
    garantirStatus($r, 200);
    garantir(($r['json']['servico'] ?? null) === 'Argos API', 'campo servico ausente');
});

teste('requisição sem chave é recusada (401)', function () {
    garantirStatus(requisicao('GET', '/api/motivos.php'), 401);
});

teste('chave inválida é recusada (401)', function () {
    garantirStatus(api('GET', 'motivos.php', null, null, 'chave-invalida-de-teste'), 401);
});

teste('rota do app exige sessão de aluno além da chave (401)', function () {
    garantirStatus(api('GET', 'motivos.php'), 401);
    garantirStatus(api('GET', 'alunos.php'), 401);
});

teste('token de sessão inventado é recusado (401)', function () {
    garantirStatus(api('GET', 'motivos.php', str_repeat('f', 64)), 401);
});

// =====================================================================
secao('API — login da PWA via QR code (POST /api/sessao.php)');

teste('GET em sessao.php não é aceito (405)', function () {
    garantirStatus(api('GET', 'sessao.php'), 405);
});

teste('qrcode_hash fora do formato sha256 é rejeitado (400)', function () {
    garantirStatus(api('POST', 'sessao.php', null, ['qrcode_hash' => 'nao-e-hash']), 400);
});

teste('login aceita o conteúdo do QR em "qrcode" (o servidor calcula a impressão)', function () {
    // O QR da fixture já é um código de 64 caracteres: vale como está.
    $r = api('POST', 'sessao.php', null, ['qrcode' => QR_PRATA_1]);
    garantirStatus($r, 201);
    garantir(($r['json']['aluno']['nome_guerra'] ?? null) === 'TESTE ALFA', 'entrou como outro aluno');

    garantirStatus(api('POST', 'sessao.php', null, ['qrcode' => '']), 400);
    garantirStatus(api('POST', 'sessao.php', null, ['qrcode' => ['não', 'é', 'texto']]), 400);
    garantirStatus(api('POST', 'sessao.php', null, ['qrcode' => str_repeat('x', 5000)]), 400);
    garantirStatus(api('POST', 'sessao.php', null, []), 400);
});

teste('QR desconhecido é rejeitado (401)', function () {
    garantirStatus(api('POST', 'sessao.php', null, ['qrcode_hash' => str_repeat('9', 64)]), 401);
});

teste('QR de aluno inativo é rejeitado (401)', function () {
    garantirStatus(api('POST', 'sessao.php', null, ['qrcode_hash' => QR_INATIVO]), 401);
});

$sessaoPrata = null;
$sessaoAzul = null;

teste('QR válido abre sessão e devolve token + dados do aluno', function () use (&$sessaoPrata, &$sessaoAzul) {
    $sessaoPrata = login(QR_PRATA_1);
    garantir(preg_match('/^[a-f0-9]{64}$/', $sessaoPrata['token'] ?? ''), 'token fora do formato esperado');
    garantir(($sessaoPrata['aluno']['esquadrao'] ?? null) === 'Esquadrão Prata', 'esquadrão do aluno errado');
    garantir(($sessaoPrata['aluno']['nome_guerra'] ?? null) === 'TESTE ALFA', 'nome_guerra errado');
    garantir((int) ($sessaoPrata['expira_em_horas'] ?? 0) > 0, 'expira_em_horas ausente');

    $sessaoAzul = login(QR_AZUL);
});

if (!$sessaoPrata || !$sessaoAzul) {
    echo "\nSem sessão de aluno não dá pra seguir com os testes do fluxo da PWA.\n";
    exit(1);
}

$tokenPrata = $sessaoPrata['token'];
$tokenAzul = $sessaoAzul['token'];

// =====================================================================
secao('API — dados que a PWA carrega');

$motivos = [];
teste('motivos.php lista motivos de falta (sem o "Presente")', function () use ($tokenPrata, &$motivos) {
    $r = api('GET', 'motivos.php', $tokenPrata);
    garantirStatus($r, 200);
    $motivos = $r['json'];
    garantir(is_array($motivos) && count($motivos) > 0, 'lista de motivos vazia');
    garantir(isset($motivos[0]['id'], $motivos[0]['nome']), 'motivo sem id/nome');
    foreach ($motivos as $m) {
        garantir($m['classificacao'] !== 'presente', "motivo de presença vazou na lista: {$m['nome']}");
    }
});

$alunosPrata = [];
teste('alunos.php devolve só o efetivo do esquadrão da sessão', function () use ($tokenPrata, &$alunosPrata) {
    $r = api('GET', 'alunos.php?esquadrao=' . rawurlencode('Esquadrão Azul'), $tokenPrata);
    garantirStatus($r, 200);
    $alunosPrata = $r['json'];
    $nomes = array_column($alunosPrata, 'nome_guerra');
    garantir(in_array('TESTE ALFA', $nomes) && in_array('TESTE BRAVO', $nomes), 'efetivo do Prata incompleto: ' . implode(', ', $nomes));
    garantir(!in_array('TESTE CHARLIE', $nomes), 'aluno do Azul apareceu pra sessão do Prata (filtro ?esquadrao= não foi ignorado)');
    foreach ($alunosPrata as $a) {
        garantir($a['esquadrao'] === 'Esquadrão Prata', "aluno de outro esquadrão na lista: {$a['nome_guerra']}");
    }
});

teste('alunos.php?id= de outro esquadrão responde 404', function () use ($tokenPrata, $tokenAzul) {
    $azul = api('GET', 'alunos.php', $tokenAzul)['json'];
    $charlie = array_values(array_filter($azul, fn($a) => $a['nome_guerra'] === 'TESTE CHARLIE'))[0] ?? null;
    garantir($charlie, 'TESTE CHARLIE não encontrado na sessão do Azul');
    garantirStatus(api('GET', 'alunos.php?id=' . $charlie['id'], $tokenPrata), 404);
});

// =====================================================================
secao('API — retirada de faltas pela PWA (abrir → marcar → enviar)');

$retiradaId = null;

teste('abrir retirada do próprio esquadrão (201)', function () use ($tokenPrata, &$retiradaId) {
    $r = api('POST', 'retiradas.php', $tokenPrata, [
        'tipo' => '1_jornada',
        'agrupamento_tipo' => 'esquadrilha',
        'agrupamento_valor' => 'A',
        'esquadrao' => 'Esquadrão Prata',
    ]);
    garantirStatus($r, 201);
    $retiradaId = (int) ($r['json']['id'] ?? 0);
    garantir($retiradaId > 0, 'id da retirada ausente');
});

teste('abrir retirada sem sessão é recusado (401)', function () {
    garantirStatus(api('POST', 'retiradas.php', null, [
        'tipo' => '1_jornada', 'agrupamento_tipo' => 'esquadrilha', 'agrupamento_valor' => 'A', 'esquadrao' => 'Esquadrão Prata',
    ]), 401);
});

teste('abrir retirada de outro esquadrão é recusado (403)', function () use ($tokenPrata) {
    garantirStatus(api('POST', 'retiradas.php', $tokenPrata, [
        'tipo' => '1_jornada', 'agrupamento_tipo' => 'esquadrilha', 'agrupamento_valor' => 'A', 'esquadrao' => 'Esquadrão Azul',
    ]), 403);
});

teste('tipo de retirada inválido é recusado (400)', function () use ($tokenPrata) {
    garantirStatus(api('POST', 'retiradas.php', $tokenPrata, [
        'tipo' => 'inventado', 'agrupamento_tipo' => 'esquadrilha', 'agrupamento_valor' => 'A', 'esquadrao' => 'Esquadrão Prata',
    ]), 400);
});

teste('retirada nova aparece como pendente', function () use ($tokenPrata, &$retiradaId) {
    $r = api('GET', "retiradas.php?id=$retiradaId", $tokenPrata);
    garantirStatus($r, 200);
    garantir($r['json']['status'] === 'pendente', "status inesperado: {$r['json']['status']}");
    garantir($r['json']['esquadrao'] === 'Esquadrão Prata', 'esquadrão da retirada errado');
});

teste('retirada aparece na listagem do esquadrão', function () use ($tokenPrata, &$retiradaId) {
    $r = api('GET', 'retiradas.php?esquadrao=' . rawurlencode('Esquadrão Prata'), $tokenPrata);
    garantirStatus($r, 200);
    garantir(in_array($retiradaId, array_map('intval', array_column($r['json'], 'id'))), 'retirada não está na lista');
});

$itens = [];
teste('itens da retirada = efetivo ativo da esquadrilha, todos presentes', function () use ($tokenPrata, &$retiradaId, &$itens) {
    $r = api('GET', "retirada_itens.php?retirada_id=$retiradaId", $tokenPrata);
    garantirStatus($r, 200);
    $itens = $r['json'];
    $nomes = array_column($itens, 'nome_guerra');
    sort($nomes);
    garantir($nomes === ['TESTE ALFA', 'TESTE BRAVO'], 'efetivo inesperado: ' . implode(', ', $nomes));
    foreach ($itens as $it) {
        garantir((int) $it['presente'] === 1, "{$it['nome_guerra']} não começou como presente");
    }
});

teste('outro esquadrão não vê nem marca os itens (403)', function () use ($tokenAzul, &$retiradaId, &$itens) {
    garantirStatus(api('GET', "retirada_itens.php?retirada_id=$retiradaId", $tokenAzul), 403);
    garantirStatus(api('PUT', "retirada_itens.php?retirada_id=$retiradaId&aluno_id={$itens[0]['aluno_id']}", $tokenAzul, ['presente' => 1]), 403);
});

teste('marcar falta sem motivo é recusado (400)', function () use ($tokenPrata, &$retiradaId, &$itens) {
    garantirStatus(api('PUT', "retirada_itens.php?retirada_id=$retiradaId&aluno_id={$itens[0]['aluno_id']}", $tokenPrata, ['presente' => 0]), 400);
});

$motivoFalta = null;
teste('marcar falta com motivo é gravado', function () use ($tokenPrata, &$retiradaId, &$itens, &$motivos, &$motivoFalta) {
    $motivoFalta = array_values(array_filter($motivos, fn($m) => $m['codigo'] !== 'DMED'))[0];
    $alunoId = $itens[0]['aluno_id'];

    $r = api('PUT', "retirada_itens.php?retirada_id=$retiradaId&aluno_id=$alunoId", $tokenPrata, [
        'presente' => 0, 'motivo_falta_id' => (int) $motivoFalta['id'], 'observacao' => 'teste de CI',
    ]);
    garantirStatus($r, 200);

    $atual = api('GET', "retirada_itens.php?retirada_id=$retiradaId", $tokenPrata)['json'];
    $item = array_values(array_filter($atual, fn($i) => (int) $i['aluno_id'] === (int) $alunoId))[0];
    garantir((int) $item['presente'] === 0, 'falta não foi gravada');
    garantir((int) $item['motivo_falta_id'] === (int) $motivoFalta['id'], 'motivo não foi gravado');
    garantir($item['observacao'] === 'teste de CI', 'observação não foi gravada');
});

teste('enviar a chamada gera protocolo', function () use ($tokenPrata, &$retiradaId) {
    $r = api('PUT', "retiradas.php?id=$retiradaId&acao=enviar", $tokenPrata);
    garantirStatus($r, 200);
    garantir(preg_match('/^\d{8}-\d{4,}-[0-9A-F]{4}$/', $r['json']['protocolo'] ?? ''), 'protocolo fora do formato: ' . ($r['json']['protocolo'] ?? '(vazio)'));

    $depois = api('GET', "retiradas.php?id=$retiradaId", $tokenPrata)['json'];
    garantir($depois['status'] === 'enviada', 'retirada não ficou como enviada');
});

teste('enviar de novo é recusado (409)', function () use ($tokenPrata, &$retiradaId) {
    garantirStatus(api('PUT', "retiradas.php?id=$retiradaId&acao=enviar", $tokenPrata), 409);
});

teste('outro esquadrão não envia retirada alheia (403)', function () use ($tokenPrata, $tokenAzul) {
    $nova = api('POST', 'retiradas.php', $tokenPrata, [
        'tipo' => '2_jornada', 'agrupamento_tipo' => 'esquadrilha', 'agrupamento_valor' => 'A', 'esquadrao' => 'Esquadrão Prata',
    ]);
    garantirStatus($nova, 201);
    garantirStatus(api('PUT', "retiradas.php?id={$nova['json']['id']}&acao=enviar", $tokenAzul), 403);
});

teste('falta por dispensa médica sem dispensa lançada bloqueia o envio (409)', function () use ($tokenPrata, &$itens) {
    $todos = api('GET', 'motivos.php', $tokenPrata)['json'];
    $dmed = array_values(array_filter($todos, fn($m) => $m['codigo'] === 'DMED'))[0] ?? null;
    if (!$dmed) {
        throw new RuntimeException('motivo DMED não existe no seed — a regra de dispensa médica ficou sem teste');
    }

    $nova = api('POST', 'retiradas.php', $tokenPrata, [
        'tipo' => 'almoco', 'agrupamento_tipo' => 'esquadrilha', 'agrupamento_valor' => 'A', 'esquadrao' => 'Esquadrão Prata',
    ]);
    garantirStatus($nova, 201);
    $id = $nova['json']['id'];

    garantirStatus(api('PUT', "retirada_itens.php?retirada_id=$id&aluno_id={$itens[1]['aluno_id']}", $tokenPrata, [
        'presente' => 0, 'motivo_falta_id' => (int) $dmed['id'],
    ]), 200);
    garantirStatus(api('PUT', "retiradas.php?id=$id&acao=enviar", $tokenPrata), 409);
});

teste('cada login gera um token diferente e os dois valem', function () {
    $a = login(QR_PRATA_2)['token'];
    $b = login(QR_PRATA_2)['token'];
    garantir($a !== $b, 'dois logins devolveram o mesmo token');
    garantirStatus(api('GET', 'motivos.php', $a), 200);
    garantirStatus(api('GET', 'motivos.php', $b), 200);
});

// =====================================================================
secao('PWA "Área Funcional" — arquivos e configuração');

teste('index.html da PWA carrega e referencia env.php, manifest e scripts', function () {
    $r = requisicao('GET', '/web/app/index.html');
    garantirStatus($r, 200);
    foreach (['env.php', 'manifest.webmanifest', 'js/app.js', 'js/qr-scanner.js'] as $ref) {
        garantir(strpos($r['corpo'], $ref) !== false, "index.html não referencia $ref");
    }
});

teste('env.php entrega a chave do app e a base da API sem erro de PHP', function () use ($apiKey) {
    $r = requisicao('GET', '/web/app/env.php');
    garantirStatus($r, 200);
    semErroPhp($r);
    garantir(strpos($r['headers']['content-type'] ?? '', 'javascript') !== false, 'Content-Type não é javascript');
    garantir(strpos($r['corpo'], 'window.ARGOS_API_KEY = ' . json_encode($apiKey)) !== false, 'ARGOS_API_KEY ausente ou diferente da configurada');
    garantir(strpos($r['corpo'], 'window.ARGOS_API_BASE = "/api/"') !== false, 'ARGOS_API_BASE deveria ser "/api/" na raiz do servidor de teste: ' . $r['corpo']);
});

teste('manifest é JSON válido, com caminhos relativos e ícones existentes', function () use ($raizProjeto) {
    $r = requisicao('GET', '/web/app/manifest.webmanifest');
    garantirStatus($r, 200);
    $manifest = $r['json'];
    garantir(is_array($manifest), 'manifest não é JSON válido');
    foreach (['name', 'short_name', 'start_url', 'scope', 'display', 'icons'] as $campo) {
        garantir(isset($manifest[$campo]), "manifest sem $campo");
    }
    // Caminho absoluto quebra em produção, onde o site fica num subdiretório.
    foreach (['start_url', 'scope', 'id'] as $campo) {
        garantir(!str_starts_with($manifest[$campo] ?? '', '/'), "$campo do manifest não pode ser absoluto: {$manifest[$campo]}");
    }
    foreach ($manifest['icons'] as $icone) {
        garantir(is_file("$raizProjeto/web/app/{$icone['src']}"), "ícone do manifest não existe: {$icone['src']}");
    }
});

teste('todos os arquivos cacheados pelo service worker existem', function () {
    $r = requisicao('GET', '/web/app/sw.js');
    garantirStatus($r, 200);
    garantir(preg_match('/const ARQUIVOS_SHELL = \[(.*?)\];/s', $r['corpo'], $m), 'ARQUIVOS_SHELL não encontrado no sw.js');
    preg_match_all("/'([^']+)'/", $m[1], $arquivos);
    garantir(count($arquivos[1]) > 0, 'ARQUIVOS_SHELL vazio');
    foreach ($arquivos[1] as $arquivo) {
        garantirStatus(requisicao('GET', "/web/app/$arquivo"), 200);
    }
});

teste('app.js não usa caminho absoluto pra API', function () {
    $r = requisicao('GET', '/web/app/js/app.js');
    garantirStatus($r, 200);
    garantir(strpos($r['corpo'], "ARGOS_API_BASE = '/api/'") === false, "app.js voltou a fixar '/api/' (quebra em subdiretório)");
    garantir(strpos($r['corpo'], 'window.ARGOS_API_BASE') !== false, 'app.js não lê window.ARGOS_API_BASE');
});

// =====================================================================
secao('Páginas — carregam sem erro de PHP');

foreach ([
    '/web/index.html' => 'landing page',
    '/web/painel/index.php' => 'login do Painel',
    '/web/ikarus37/index.php' => 'login do Ikarus37',
] as $caminho => $descricao) {
    teste("$descricao ($caminho)", function () use ($caminho) {
        $r = requisicao('GET', $caminho);
        garantirStatus($r, 200);
        semErroPhp($r);
    });
}

teste('core/versao.php tem versao, commit e data', function () use ($raizProjeto) {
    $versao = require "$raizProjeto/core/versao.php";
    foreach (['versao', 'commit', 'data'] as $campo) {
        garantir(!empty($versao[$campo]), "core/versao.php sem $campo");
    }
});

encerrarTestes();
