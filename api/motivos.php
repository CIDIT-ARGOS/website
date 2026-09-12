<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/motivos_core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    erro("Método não suportado.", 405);
}

responder(listarMotivos($conexao));

mysqli_close($conexao);
