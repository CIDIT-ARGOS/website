<?php
// Endereço de CSS/JS com a versão publicada no fim: "../css/argos-admin.css?v=v1.2.1".
//
// A hospedagem (e a CDN na frente dela) guarda .css e .js por 7 dias. Sem a
// versão no endereço, depois de um deploy o navegador continuava recebendo o
// arquivo antigo — foi assim que o leitor de QR novo "não funcionava" em
// produção: a página era a nova, o script era o da versão anterior. Com a
// versão no endereço, cada release é um endereço novo, que ninguém tem guardado.

function urlVersionada($url) {
    static $versao = null;
    if ($versao === null) {
        $versao = rawurlencode((require __DIR__ . '/versao.php')['versao']);
    }
    return $url . '?v=' . $versao;
}
