<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/motivos_core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    erro("Método não suportado.", 405);
}

// Exige sessão de aluno (issue #26) — faz parte do fluxo do app (escolher
// o motivo ao marcar falta), não é catálogo público.
exigirSessaoAluno($conexao);

responder(listarMotivos($conexao));

mysqli_close($conexao);
