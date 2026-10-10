<?php

// Livro do Dia do esquadrão do aluno de serviço: o resumo padronizado (faltas
// por tipo de chamada + dispensas médicas) e o texto pronto pra enviar.
// Exige sessão de aluno e é sempre do esquadrão da sessão.

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/livro_core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    erro("Método não suportado.", 405);
}

$alunoSessao = exigirSessaoAluno($conexao);

$data = $_GET['data'] ?? date('Y-m-d');
$dataValida = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
if (!$dataValida || $dataValida->format('Y-m-d') !== $data) {
    erro("Data inválida. Use o formato AAAA-MM-DD.");
}

responder(livroParaApi(montarLivroEsquadrao($conexao, $alunoSessao['esquadrao'], $data), $data));

mysqli_close($conexao);
