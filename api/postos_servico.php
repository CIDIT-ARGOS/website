<?php

// Catálogo de postos de serviço pro app: quando o motivo da ausência é
// "Serviço", a chamada pergunta em qual posto. Só leitura — o cadastro é no
// Painel (Controle do Domínio de Negócio → Postos de serviço).

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/servicos_core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    erro("Método não suportado.", 405);
}

exigirSessaoAluno($conexao);

responder(listarServicos($conexao));

mysqli_close($conexao);
