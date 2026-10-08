<?php

// Roteador do servidor embutido do PHP (php -S) usado no CI — imita os
// bloqueios que em produção vêm do .htaccess (core/, database/) e do pacote
// de release (deploy/, tests/, scripts/ e docker/ nem sobem pro servidor).
// Qualquer outro caminho é servido normalmente.

$caminho = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('{^/(core|database|deploy|tests|scripts|docker|\.git|\.forgejo)(/|$)}', $caminho)) {
    http_response_code(404);
    echo 'Não encontrado.';
    return true;
}

return false;
