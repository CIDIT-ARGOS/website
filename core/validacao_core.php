<?php

// Validação de comprimento de campo, compartilhada por toda a API/core que
// grava texto vindo do usuário. Sem isso, um campo de banco VARCHAR(50)
// aceita silenciosamente uma string de megabytes até a query — o MySQL só
// rejeita (ou, fora do modo estrito, trunca sem avisar) depois de já ter
// gasto memória/CPU processando a requisição inteira. Barrar aqui, antes de
// qualquer query, é mais barato e dá um erro claro em vez de um truncamento
// silencioso.

/**
 * Confere um conjunto de campos contra limites máximos de caracteres.
 * $limites é um mapa campo => tamanho máximo (deve bater com o VARCHAR da
 * coluna correspondente em database/init_db.sql).
 *
 * @return string|null Mensagem de erro do primeiro campo que estourar o limite, ou null se todos ok.
 */
function validarComprimentos($dados, $limites) {
    foreach ($limites as $campo => $max) {
        if (!isset($dados[$campo])) {
            continue;
        }
        $valor = (string) $dados[$campo];
        if (mb_strlen($valor) > $max) {
            return "Campo '$campo' excede o tamanho máximo permitido ($max caracteres).";
        }
    }
    return null;
}
