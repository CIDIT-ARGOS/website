<?php

// Dump SQL completo do banco, gravado num arquivo — usado pelo deploy antes
// de apagar os arquivos e rodar migrations. Mesmo formato do backup manual
// do Ikarus37 (web/ikarus37/backup.php), então restaura do mesmo jeito.
//
// Duplicado de propósito em vez de reaproveitar o do Ikarus37: no momento do
// backup o servidor ainda tem os arquivos da versão ANTERIOR, então este
// código não pode depender de nada que só exista na versão nova.

// Primeira linha do arquivo: se alguém tentar baixar o backup pelo navegador,
// o PHP executa esta linha e não serve nada — protege os dados mesmo que o
// servidor ignore .htaccess. Pra restaurar, apague esta linha (ou importe
// com `tail -n +2 backup.sql.php | mysql ...`).
const BACKUP_CABECALHO_PROTECAO = "<?php http_response_code(404); exit; ?>\n";

function backupGerarArquivo($conexao, $caminhoArquivo, $rotulo) {
    $arquivo = fopen($caminhoArquivo, 'w');
    if (!$arquivo) {
        throw new RuntimeException("Não consegui criar o arquivo de backup em $caminhoArquivo");
    }

    $nomeBanco = mysqli_fetch_row(mysqli_query($conexao, "SELECT DATABASE()"))[0];

    fwrite($arquivo, BACKUP_CABECALHO_PROTECAO);
    fwrite($arquivo, "-- Backup ARGOS (deploy $rotulo) gerado em " . date('Y-m-d H:i:s') . "\n");
    fwrite($arquivo, "-- Banco: $nomeBanco\n\n");
    fwrite($arquivo, "SET NAMES utf8mb4;\n");
    fwrite($arquivo, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

    $tabelas = [];
    $resultTabelas = mysqli_query($conexao, "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    while ($linha = mysqli_fetch_array($resultTabelas)) {
        $tabelas[] = $linha[0];
    }

    $totalLinhas = 0;
    foreach ($tabelas as $tabela) {
        $criacao = mysqli_fetch_assoc(mysqli_query($conexao, "SHOW CREATE TABLE `$tabela`"));

        fwrite($arquivo, "-- ----------------------------\n-- Tabela: $tabela\n-- ----------------------------\n");
        fwrite($arquivo, "DROP TABLE IF EXISTS `$tabela`;\n" . $criacao['Create Table'] . ";\n\n");

        // Sem buffer: não carrega a tabela inteira na memória do PHP.
        $resultDados = mysqli_query($conexao, "SELECT * FROM `$tabela`", MYSQLI_USE_RESULT);
        $colunasSql = null;
        $bloco = [];

        while ($linha = mysqli_fetch_assoc($resultDados)) {
            if ($colunasSql === null) {
                $colunasSql = implode(', ', array_map(fn($c) => "`$c`", array_keys($linha)));
            }
            $valores = array_map(function ($v) use ($conexao) {
                return $v === null ? 'NULL' : "'" . mysqli_real_escape_string($conexao, $v) . "'";
            }, array_values($linha));
            $bloco[] = '(' . implode(', ', $valores) . ')';
            $totalLinhas++;

            if (count($bloco) === 200) {
                fwrite($arquivo, "INSERT INTO `$tabela` ($colunasSql) VALUES\n" . implode(",\n", $bloco) . ";\n\n");
                $bloco = [];
            }
        }
        mysqli_free_result($resultDados);

        if ($bloco) {
            fwrite($arquivo, "INSERT INTO `$tabela` ($colunasSql) VALUES\n" . implode(",\n", $bloco) . ";\n\n");
        }
    }

    fwrite($arquivo, "SET FOREIGN_KEY_CHECKS = 1;\n");
    fclose($arquivo);

    return ['tabelas' => count($tabelas), 'linhas' => $totalLinhas, 'bytes' => filesize($caminhoArquivo)];
}

// Mantém só os $manter backups mais recentes do diretório.
function backupLimparAntigos($diretorio, $manter) {
    $arquivos = glob(rtrim($diretorio, '/') . '/backup_*.sql.php');
    usort($arquivos, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $removidos = [];
    foreach (array_slice($arquivos, $manter) as $antigo) {
        if (@unlink($antigo)) {
            $removidos[] = basename($antigo);
        }
    }
    return $removidos;
}
