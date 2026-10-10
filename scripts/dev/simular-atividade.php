<?php

// Enche um banco local com chamadas de exemplo, pra as telas do Painel
// (situação, relatórios, gráficos, Livro do Dia) e da PWA terem o que mostrar.
// Faz tudo pela API, como a PWA faria: loga com o QR de um aluno do seed de
// desenvolvimento, abre a retirada, marca algumas faltas e envia.
//
//   php scripts/dev/simular-atividade.php http://localhost:8080
//
// Precisa do database/seed_dev.sql carregado. Só pra banco local — NUNCA
// aponte pra produção.

$baseUrl = rtrim($argv[1] ?? '', '/');
if ($baseUrl === '' || !preg_match('{^https?://(localhost|127\.0\.0\.1|\[::1\]|[^/]*\.localhost|[^/]*\.test)(:\d+)?(/|$)}', $baseUrl)) {
    fwrite(STDERR, "Uso: php scripts/dev/simular-atividade.php http://localhost:8080  (só aceita endereço local)\n");
    exit(2);
}
$apiKey = getenv('ARGOS_API_KEY') ?: 'd0cf67c0a3b8464aacb2ed36f01b1fbb2af8d7623d804c3aa5eef732c6b3f058';

function chamar($metodo, $caminho, $token = null, $corpo = null) {
    global $baseUrl, $apiKey;
    $headers = ["X-API-Key: $apiKey", 'Content-Type: application/json'];
    if ($token) {
        $headers[] = "X-Session-Token: $token";
    }
    $resposta = @file_get_contents("$baseUrl/api/$caminho", false, stream_context_create(['http' => [
        'method' => $metodo, 'header' => implode("\r\n", $headers), 'content' => $corpo !== null ? json_encode($corpo) : '', 'ignore_errors' => true, 'timeout' => 20,
    ]]));
    if ($resposta === false) {
        fwrite(STDERR, "Sem resposta de $baseUrl/api/$caminho\n");
        exit(1);
    }
    return json_decode($resposta, true);
}

// [identidade do aluno de serviço (seed), esquadrão, esquadrilha, tipo, enviar?, faltas: nome de guerra => código do motivo]
$chamadas = [
    ['DEV-0001', 'Esquadrão Prata', 'A', 'almoco', true, ['CARDOSO' => 'FALT']],
    ['DEV-0001', 'Esquadrão Prata', 'A', '2_jornada', true, ['DUARTE' => 'HOSP']],
    ['DEV-0005', 'Esquadrão Prata', 'B', 'almoco', true, ['GOUVEIA' => 'CNTR', 'HENRIQUES' => 'FALT']],
    ['DEV-0005', 'Esquadrão Prata', 'B', 'pernoite', false, ['FARIAS' => 'ATL']],
    ['DEV-0009', 'Esquadrão Azul', 'A', 'almoco', true, []],
    ['DEV-0013', 'Esquadrão Azul', 'B', 'almoco', true, ['PACHECO' => 'ESTG', 'NOGUEIRA' => 'FALT']],
    ['DEV-0017', 'Esquadrão Verde', 'A', 'almoco', true, ['TAVARES' => 'INSP']],
    ['DEV-0021', 'Esquadrão Verde', 'B', 'pernoite', false, []],
];

foreach ($chamadas as [$identidade, $esquadrao, $esquadrilha, $tipo, $enviar, $faltas]) {
    $sessao = chamar('POST', 'sessao.php', null, ['qrcode_hash' => hash('sha256', "argos-seed-dev:$identidade")]);
    if (empty($sessao['token'])) {
        fwrite(STDERR, "Login de $identidade falhou — o seed_dev.sql está carregado? " . json_encode($sessao, JSON_UNESCAPED_UNICODE) . "\n");
        exit(1);
    }
    $token = $sessao['token'];

    $retirada = chamar('POST', 'retiradas.php', $token, [
        'tipo' => $tipo, 'agrupamento_tipo' => 'esquadrilha', 'agrupamento_valor' => $esquadrilha, 'esquadrao' => $esquadrao,
    ]);
    $id = $retirada['id'] ?? null;
    if (!$id) {
        fwrite(STDERR, "Não abriu a retirada de $esquadrao/$esquadrilha: " . json_encode($retirada, JSON_UNESCAPED_UNICODE) . "\n");
        exit(1);
    }

    $motivos = array_column(chamar('GET', 'motivos.php', $token), 'id', 'codigo');
    foreach (chamar('GET', "retirada_itens.php?retirada_id=$id", $token) as $item) {
        $codigo = $faltas[$item['nome_guerra']] ?? null;
        if ($codigo && isset($motivos[$codigo])) {
            chamar('PUT', "retirada_itens.php?retirada_id=$id&aluno_id={$item['aluno_id']}", $token, ['presente' => 0, 'motivo_falta_id' => (int) $motivos[$codigo]]);
        }
    }

    $estado = 'pendente';
    if ($enviar) {
        $estado = 'enviada, protocolo ' . (chamar('PUT', "retiradas.php?id=$id&acao=enviar", $token)['protocolo'] ?? '?');
    }
    printf("%-16s %s  %-16s %d falta(s)  %s\n", $esquadrao, $esquadrilha, $tipo, count($faltas), $estado);
}
