<?php

// Utilitário de banco pro CI — faz o papel do cliente `mysql` sem precisar
// instalá-lo no runner (e sem a dor de cabeça de plugin de autenticação do
// MySQL 8 com cliente MariaDB). Conecta como root via variáveis de ambiente.
//
//   php tests/ci_banco.php esperar
//   php tests/ci_banco.php recriar   <banco>
//   php tests/ci_banco.php importar  <banco> <arquivo.sql> [...]
//   php tests/ci_banco.php sql       <banco> "<comandos SQL>"
//   php tests/ci_banco.php remover-fk <banco> <tabela> <coluna>
//   php tests/ci_banco.php esquema   <banco>        (colunas de todas as tabelas, pra comparar)
//
// Ambiente: DB_HOST (mysql), DB_PORTA (3306), DB_ROOT_SENHA (argos_root)

$host = getenv('DB_HOST') ?: 'mysql';
$porta = (int) (getenv('DB_PORTA') ?: 3306);
$senha = getenv('DB_ROOT_SENHA') ?: 'argos_root';

mysqli_report(MYSQLI_REPORT_OFF);

function conectar($banco = null) {
    global $host, $porta, $senha;
    $c = @mysqli_connect($host, 'root', $senha, $banco ?? '', $porta);
    if ($c) {
        mysqli_set_charset($c, 'utf8mb4');
    }
    return $c;
}

function falhar($mensagem) {
    fwrite(STDERR, "ci_banco: $mensagem\n");
    exit(1);
}

function executarSql($conexao, $sql, $origem) {
    if (!mysqli_multi_query($conexao, $sql)) {
        falhar("$origem: " . mysqli_error($conexao));
    }
    do {
        if ($r = mysqli_store_result($conexao)) {
            mysqli_free_result($r);
        }
        if (mysqli_errno($conexao)) {
            falhar("$origem: " . mysqli_error($conexao));
        }
    } while (mysqli_more_results($conexao) && (mysqli_next_result($conexao) || falhar("$origem: " . mysqli_error($conexao))));
}

$comando = $argv[1] ?? '';
$banco = $argv[2] ?? null;

switch ($comando) {
    case 'esperar':
        for ($i = 0; $i < 90; $i++) {
            if ($c = conectar()) {
                echo "MySQL pronto (" . mysqli_get_server_info($c) . ")\n";
                exit(0);
            }
            sleep(2);
        }
        falhar("MySQL não respondeu em $host:$porta: " . mysqli_connect_error());

    case 'recriar':
        $c = conectar() ?: falhar(mysqli_connect_error());
        executarSql($c, "DROP DATABASE IF EXISTS `$banco`; CREATE DATABASE `$banco` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;", 'recriar');
        echo "banco $banco recriado\n";
        break;

    case 'importar':
        $c = conectar($banco) ?: falhar(mysqli_connect_error());
        foreach (array_slice($argv, 3) as $arquivo) {
            $sql = file_get_contents($arquivo);
            if ($sql === false) {
                falhar("não li $arquivo");
            }
            executarSql($c, $sql, basename($arquivo));
            echo "importado $arquivo em $banco\n";
        }
        break;

    case 'sql':
        $c = conectar($banco) ?: falhar(mysqli_connect_error());
        executarSql($c, $argv[3], 'sql');
        break;

    case 'remover-fk':
        [$tabela, $coluna] = [$argv[3], $argv[4]];
        $c = conectar($banco) ?: falhar(mysqli_connect_error());
        $stmt = mysqli_prepare($c, "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL");
        mysqli_stmt_bind_param($stmt, 'sss', $banco, $tabela, $coluna);
        mysqli_stmt_execute($stmt);
        foreach (mysqli_fetch_all(mysqli_stmt_get_result($stmt)) as [$nome]) {
            executarSql($c, "ALTER TABLE `$tabela` DROP FOREIGN KEY `$nome`", 'remover-fk');
            echo "FK $nome removida\n";
        }
        break;

    case 'esquema':
        $c = conectar($banco) ?: falhar(mysqli_connect_error());
        $stmt = mysqli_prepare($c, "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME <> 'schema_migrations' ORDER BY TABLE_NAME, COLUMN_NAME");
        mysqli_stmt_bind_param($stmt, 's', $banco);
        mysqli_stmt_execute($stmt);
        foreach (mysqli_fetch_all(mysqli_stmt_get_result($stmt)) as $linha) {
            echo implode("\t", $linha) . "\n";
        }
        break;

    default:
        falhar("comando desconhecido: '$comando'");
}
