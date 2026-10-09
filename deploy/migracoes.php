<?php

// Controle de migrations do banco — quais database/migration_NNN_*.sql já
// rodaram, quais faltam, e aplicação das pendentes em ordem.
//
// Usado por dois caminhos:
//   - deploy/endpoint.php — no deploy de produção (o banco só é acessível
//     de dentro da aplicação, então o workflow chama esse endpoint por HTTP;
//     as migrations sobem junto, em _deploy/database/);
//   - deploy/migrar.php   — linha de comando (CI e Docker local).
//
// Autocontido de propósito: não depende de nada em core/ além do
// conectarBanco() do config.php, porque roda no meio do deploy, quando os
// arquivos do servidor ainda são os da versão antiga (ou nem existem).
//
// Regras:
//   - Uma migration nova = um arquivo database/migration_NNN_descricao.sql,
//     com NNN maior que o da última. Nunca edite uma que já foi pra produção.
//   - database/init_db.sql é o retrato do banco até a migration 013 e não
//     muda mais: instalação nova = init_db.sql + migrations pendentes.

const MIGRACOES_TABELA = 'schema_migrations';

// Migrations anteriores à existência da tabela de controle. Produção
// recebeu essas à mão (phpMyAdmin), então não há registro de quais rodaram.
// Na primeira execução, a "baseline" (última já aplicada) é detectada pela
// presença do que cada uma criou — a de número mais alto encontrada vale,
// e todas até ela são marcadas como aplicadas sem rodar de novo.
// As que só mexem em dados (006, 007, 009) não têm sentinela própria e
// ficam cobertas pela de número maior.
const MIGRACOES_SENTINELAS = [
    2 => ['coluna', 'retiradas', 'painel_usuario_id'],
    3 => ['tabela', 'unidades'],
    4 => ['tabela', 'cargos'],
    5 => ['tabela', 'dispensas'],
    8 => ['tabela', 'dispensa_tipos'],
    10 => ['coluna', 'admin_usuarios', 'tentativas_login'],
    11 => ['tabela', 'login_ip_tentativas'],
    12 => ['tabela', 'api_sessoes'],
    13 => ['tabela', 'turmas'],
];

function migracoesDisponiveis($diretorio) {
    $migracoes = [];
    foreach (glob(rtrim($diretorio, '/') . '/migration_*.sql') as $caminho) {
        if (preg_match('/^migration_(\d{3,})_[a-z0-9_]+\.sql$/', basename($caminho), $m)) {
            $numero = (int) $m[1];
            if (isset($migracoes[$numero])) {
                throw new RuntimeException("Duas migrations com o número $numero: " . basename($migracoes[$numero]) . ' e ' . basename($caminho));
            }
            $migracoes[$numero] = $caminho;
        }
    }
    ksort($migracoes);
    return $migracoes;
}

function _migracoesValor($conexao, $sql, $tipos = '', ...$params) {
    $stmt = mysqli_prepare($conexao, $sql);
    if ($tipos !== '') {
        mysqli_stmt_bind_param($stmt, $tipos, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $linha = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
    return $linha[0] ?? null;
}

function migracoesTabelaExiste($conexao, $tabela) {
    return (int) _migracoesValor($conexao,
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        's', $tabela) > 0;
}

function migracoesColunaExiste($conexao, $tabela, $coluna) {
    return (int) _migracoesValor($conexao,
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        'ss', $tabela, $coluna) > 0;
}

function migracoesDetectarBaseline($conexao) {
    $baseline = 0;
    foreach (MIGRACOES_SENTINELAS as $numero => $sentinela) {
        $existe = $sentinela[0] === 'tabela'
            ? migracoesTabelaExiste($conexao, $sentinela[1])
            : migracoesColunaExiste($conexao, $sentinela[1], $sentinela[2]);
        if ($existe) {
            $baseline = max($baseline, $numero);
        }
    }
    return $baseline;
}

function migracoesAplicadas($conexao) {
    if (!migracoesTabelaExiste($conexao, MIGRACOES_TABELA)) {
        return null;
    }
    $resultado = mysqli_query($conexao, "SELECT numero, arquivo, origem, aplicada_em FROM " . MIGRACOES_TABELA . " ORDER BY numero");
    $aplicadas = [];
    while ($linha = mysqli_fetch_assoc($resultado)) {
        $aplicadas[(int) $linha['numero']] = $linha;
    }
    return $aplicadas;
}

/**
 * Situação atual, sem alterar nada.
 *
 * @param int|null $baselineForcada sobrepõe a detecção automática (só vale
 *                                  enquanto a tabela de controle não existe)
 */
function migracoesStatus($conexao, $diretorio, $baselineForcada = null) {
    if (!migracoesTabelaExiste($conexao, 'alunos')) {
        throw new RuntimeException('Banco sem a tabela "alunos" — parece vazio. Importe database/init_db.sql antes de rodar migrations.');
    }

    $disponiveis = migracoesDisponiveis($diretorio);
    $aplicadas = migracoesAplicadas($conexao);
    $tabelaExiste = $aplicadas !== null;
    $baseline = null;

    if (!$tabelaExiste) {
        $baseline = $baselineForcada ?? migracoesDetectarBaseline($conexao);
        $aplicadas = [];
        foreach ($disponiveis as $numero => $caminho) {
            if ($numero <= $baseline) {
                $aplicadas[$numero] = ['numero' => $numero, 'arquivo' => basename($caminho), 'origem' => 'baseline', 'aplicada_em' => null];
            }
        }
    }

    $pendentes = [];
    foreach ($disponiveis as $numero => $caminho) {
        if (!isset($aplicadas[$numero])) {
            $pendentes[$numero] = basename($caminho);
        }
    }

    // Migration registrada no banco mas sem arquivo = código mais velho que
    // o banco (deploy de versão antiga por cima de uma nova). Não é erro
    // fatal, mas merece aviso.
    $desconhecidas = array_values(array_diff_key($aplicadas, $disponiveis));

    return [
        'tabela_controle_existe' => $tabelaExiste,
        'baseline' => $baseline,
        'aplicadas' => array_values(array_map(fn($a) => $a['arquivo'], $aplicadas)),
        'pendentes' => array_values($pendentes),
        'registradas_sem_arquivo' => array_map(fn($a) => $a['arquivo'], $desconhecidas),
    ];
}

function _migracoesExecutarSql($conexao, $sql) {
    if (!mysqli_multi_query($conexao, $sql)) {
        return mysqli_error($conexao);
    }
    do {
        if ($resultado = mysqli_store_result($conexao)) {
            mysqli_free_result($resultado);
        }
        if (mysqli_errno($conexao)) {
            return mysqli_error($conexao);
        }
        if (!mysqli_more_results($conexao)) {
            break;
        }
        if (!mysqli_next_result($conexao)) {
            return mysqli_error($conexao);
        }
    } while (true);
    return null;
}

/**
 * Cria a tabela de controle (marcando a baseline) se ainda não existe e
 * aplica as migrations pendentes em ordem. Para na primeira que falhar —
 * DDL do MySQL não é transacional, então uma migration que quebra no meio
 * pode deixar alteração parcial: por isso o deploy faz backup antes.
 *
 * @return array{ok: bool, baseline: ?int, aplicadas_agora: string[], erro?: string, arquivo_com_erro?: string}
 */
function migracoesExecutar($conexao, $diretorio, $baselineForcada = null) {
    $status = migracoesStatus($conexao, $diretorio, $baselineForcada);
    $disponiveis = migracoesDisponiveis($diretorio);

    if (!$status['tabela_controle_existe']) {
        $erro = _migracoesExecutarSql($conexao, "
            CREATE TABLE " . MIGRACOES_TABELA . " (
              numero INT PRIMARY KEY,
              arquivo VARCHAR(150) NOT NULL,
              origem ENUM('baseline', 'deploy') NOT NULL,
              aplicada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) DEFAULT CHARSET = utf8mb4
        ");
        if ($erro) {
            return ['ok' => false, 'baseline' => $status['baseline'], 'aplicadas_agora' => [], 'erro' => "Não consegui criar " . MIGRACOES_TABELA . ": $erro"];
        }

        $stmt = mysqli_prepare($conexao, "INSERT INTO " . MIGRACOES_TABELA . " (numero, arquivo, origem) VALUES (?, ?, 'baseline')");
        foreach ($disponiveis as $numero => $caminho) {
            if ($numero <= $status['baseline']) {
                $arquivo = basename($caminho);
                mysqli_stmt_bind_param($stmt, 'is', $numero, $arquivo);
                mysqli_stmt_execute($stmt);
            }
        }
    }

    $aplicadasAgora = [];
    $registrar = mysqli_prepare($conexao, "INSERT INTO " . MIGRACOES_TABELA . " (numero, arquivo, origem) VALUES (?, ?, 'deploy')");

    foreach ($disponiveis as $numero => $caminho) {
        $arquivo = basename($caminho);
        if (!in_array($arquivo, $status['pendentes'], true)) {
            continue;
        }

        $erro = _migracoesExecutarSql($conexao, file_get_contents($caminho));
        if ($erro) {
            return [
                'ok' => false,
                'baseline' => $status['baseline'],
                'aplicadas_agora' => $aplicadasAgora,
                'arquivo_com_erro' => $arquivo,
                'erro' => $erro,
            ];
        }

        mysqli_stmt_bind_param($registrar, 'is', $numero, $arquivo);
        mysqli_stmt_execute($registrar);
        $aplicadasAgora[] = $arquivo;
    }

    return ['ok' => true, 'baseline' => $status['baseline'], 'aplicadas_agora' => $aplicadasAgora];
}
