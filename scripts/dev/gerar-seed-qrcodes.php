<?php

// Gera o seed de desenvolvimento dos QR codes: alunos 100% fictícios, cada um
// com um qrcode_hash fixo, pra dar pra logar na PWA "Área Funcional" num
// banco local sem depender de dados reais (o seed_db.sql de verdade nunca é
// versionado).
//
//   php scripts/dev/gerar-seed-qrcodes.php            regrava os dois arquivos
//   php scripts/dev/gerar-seed-qrcodes.php --checar   só confere se estão em dia (lint do CI)
//
// Saídas (versionadas — pra usar não precisa rodar este script):
//   database/seed_dev.sql        os alunos, com o qrcode_hash de cada um
//   docs/dev/qrcodes-seed.html   folha com o QR code de cada aluno, pra escanear ou imprimir
//
// Pra mudar o efetivo de teste, edite as listas abaixo e rode de novo.
//
// NUNCA carregue o seed_dev.sql em produção: os hashes estão no repositório,
// qualquer um logaria como esses alunos.

const SAIDA_SQL = 'database/seed_dev.sql';
const SAIDA_HTML = 'docs/dev/qrcodes-seed.html';

// O qrcode_hash de cada aluno é sha256(PREFIXO_HASH . identidade_militar):
// determinístico, então o QR impresso hoje continua valendo depois de recriar o banco.
const PREFIXO_HASH = 'argos-seed-dev:';

// esquadrão => [curso, série, turma (database/init_db.sql)]
const ESQUADROES = [
    'Esquadrão Prata' => ['EAGS', 'EAGS', 'Ikarus'],
    'Esquadrão Azul' => ['CFS', '4', 'Fera Azul'],
    'Esquadrão Verde' => ['CFS', '3', 'Invictus'],
];
const ESQUADRILHAS = ['A', 'B'];
const ALUNOS_POR_ESQUADRILHA = 4;
const ESPECIALIDADES = ['SIN', 'BMA', 'BCT', 'SAD'];

const NOMES_GUERRA = [
    'ALMEIDA', 'BARROS', 'CARDOSO', 'DUARTE', 'ESTEVES', 'FARIAS', 'GOUVEIA', 'HENRIQUES',
    'IBARRA', 'JARDIM', 'KLEIN', 'LACERDA', 'MACEDO', 'NOGUEIRA', 'OLIVEIRA', 'PACHECO',
    'QUEIROZ', 'RAMALHO', 'SALGADO', 'TAVARES', 'UCHOA', 'VALENTE', 'XAVIER', 'ZANETTI',
];

// Um aluno inativo, pra testar que o QR dele é recusado no login.
const ALUNO_INATIVO = 'WERNECK';

function montarAlunos() {
    $alunos = [];
    $numero = 0;
    foreach (ESQUADROES as $esquadrao => [$curso, $serie, $turma]) {
        foreach (ESQUADRILHAS as $esquadrilha) {
            for ($i = 0; $i < ALUNOS_POR_ESQUADRILHA; $i++) {
                $alunos[] = novoAluno($numero, NOMES_GUERRA[$numero], $esquadrao, $esquadrilha, $curso, $serie, $turma, 1);
                $numero++;
            }
        }
    }

    $primeiroEsquadrao = array_key_first(ESQUADROES);
    [$curso, $serie, $turma] = ESQUADROES[$primeiroEsquadrao];
    $alunos[] = novoAluno($numero, ALUNO_INATIVO, $primeiroEsquadrao, ESQUADRILHAS[0], $curso, $serie, $turma, 0);

    return $alunos;
}

function novoAluno($numero, $nomeGuerra, $esquadrao, $esquadrilha, $curso, $serie, $turma, $ativo) {
    $identidade = sprintf('DEV-%04d', $numero + 1);
    return [
        'nome_guerra' => $nomeGuerra,
        'sexo' => $numero % 3 === 1 ? 'F' : 'M',
        'especialidade' => ESPECIALIDADES[$numero % count(ESPECIALIDADES)],
        'identidade_militar' => $identidade,
        'milhao' => sprintf('26/8%03d', $numero + 1),
        'esquadrao' => $esquadrao,
        'esquadrilha' => $esquadrilha,
        'curso' => $curso,
        'serie' => $serie,
        'turma' => $turma,
        'ativo' => $ativo,
        'qrcode_hash' => hash('sha256', PREFIXO_HASH . $identidade),
    ];
}

// ---------------------------------------------------------------------
// SQL
// ---------------------------------------------------------------------

function gerarSql(array $alunos) {
    $q = fn($texto) => "'" . str_replace("'", "''", $texto) . "'";

    $linhas = [];
    foreach ($alunos as $a) {
        $linhas[] = sprintf(
            "  ('GS', %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, (SELECT id FROM turmas WHERE nome = %s), %d)",
            $q($a['especialidade']), $q($a['nome_guerra']), $q($a['sexo']), $q($a['identidade_militar']), $q($a['qrcode_hash']),
            $q($a['milhao']), $q($a['esquadrao']), $q($a['esquadrilha']), $q($a['curso']), $q($a['serie']), $q($a['turma']), $a['ativo']
        );
    }

    return "SET NAMES utf8mb4;\n\n"
        . "-- Seed de DESENVOLVIMENTO: alunos fictícios com QR code, pra testar o login da\n"
        . "-- PWA \"Área Funcional\" num banco local. Os QR codes estão em docs/dev/qrcodes-seed.html.\n"
        . "--\n"
        . "-- GERADO por scripts/dev/gerar-seed-qrcodes.php — não edite à mão.\n"
        . "-- NUNCA carregue em produção: os qrcode_hash abaixo são públicos.\n"
        . "--\n"
        . "-- Roda por cima de database/init_db.sql (+ migrations). Pode rodar de novo:\n"
        . "-- quem já existe só tem o qrcode_hash e o ativo restaurados.\n\n"
        . "INSERT INTO alunos\n"
        . "  (posto_graduacao, especialidade, nome_guerra, sexo, identidade_militar, qrcode_hash, milhao, esquadrao, esquadrilha, curso, serie, turma_id, ativo)\n"
        . "VALUES\n"
        . implode(",\n", $linhas) . "\n"
        . "ON DUPLICATE KEY UPDATE qrcode_hash = VALUES(qrcode_hash), ativo = VALUES(ativo);\n";
}

// ---------------------------------------------------------------------
// QR code — versão 5, correção M, modo byte: cabe os 64 caracteres do hash
// com folga (até 84). Implementação mínima só pra esse caso, pra não trazer
// dependência pro projeto.
// ---------------------------------------------------------------------

const QR_TAMANHO = 37;              // módulos por lado na versão 5
const QR_BLOCOS = 2;                // versão 5-M: 2 blocos…
const QR_DADOS_POR_BLOCO = 43;      // …de 43 codewords de dados…
const QR_CORRECAO_POR_BLOCO = 24;   // …e 24 de correção de erro cada
const QR_MASCARA = 0;               // (linha + coluna) % 2 == 0

function gfMultiplicar($x, $y) {
    $z = 0;
    for ($i = 7; $i >= 0; $i--) {
        $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
        $z ^= (($y >> $i) & 1) * $x;
    }
    return $z;
}

function reedSolomonDivisor($grau) {
    $resultado = array_fill(0, $grau, 0);
    $resultado[$grau - 1] = 1;
    $raiz = 1;
    for ($i = 0; $i < $grau; $i++) {
        for ($j = 0; $j < $grau; $j++) {
            $resultado[$j] = gfMultiplicar($resultado[$j], $raiz);
            if ($j + 1 < $grau) {
                $resultado[$j] ^= $resultado[$j + 1];
            }
        }
        $raiz = gfMultiplicar($raiz, 2);
    }
    return $resultado;
}

function reedSolomonResto(array $dados, array $divisor) {
    $resto = array_fill(0, count($divisor), 0);
    foreach ($dados as $byte) {
        $fator = $byte ^ array_shift($resto);
        $resto[] = 0;
        foreach ($divisor as $i => $coeficiente) {
            $resto[$i] ^= gfMultiplicar($coeficiente, $fator);
        }
    }
    return $resto;
}

/**
 * @return bool[][] matriz [linha][coluna], true = módulo escuro
 */
function qrMatriz($texto) {
    $capacidade = QR_BLOCOS * QR_DADOS_POR_BLOCO;
    if (strlen($texto) > $capacidade - 2) {
        throw new InvalidArgumentException('texto grande demais pro QR versão 5-M');
    }

    // ----- dados: modo byte (0100) + tamanho (8 bits) + bytes + terminador + preenchimento
    $bits = '0100' . sprintf('%08b', strlen($texto));
    foreach (str_split($texto) as $caractere) {
        $bits .= sprintf('%08b', ord($caractere));
    }
    $bits .= str_repeat('0', min(4, $capacidade * 8 - strlen($bits)));
    $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
    $dados = array_map('bindec', str_split($bits, 8));
    for ($preenchimento = 0xEC; count($dados) < $capacidade; $preenchimento ^= 0xEC ^ 0x11) {
        $dados[] = $preenchimento;
    }

    // ----- correção de erro por bloco, depois intercala dados e correção
    $divisor = reedSolomonDivisor(QR_CORRECAO_POR_BLOCO);
    $blocos = array_chunk($dados, QR_DADOS_POR_BLOCO);
    $correcoes = array_map(fn($bloco) => reedSolomonResto($bloco, $divisor), $blocos);
    $codewords = [];
    foreach ([[$blocos, QR_DADOS_POR_BLOCO], [$correcoes, QR_CORRECAO_POR_BLOCO]] as [$grupo, $tamanho]) {
        for ($i = 0; $i < $tamanho; $i++) {
            foreach ($grupo as $bloco) {
                $codewords[] = $bloco[$i];
            }
        }
    }

    // ----- padrões fixos
    $n = QR_TAMANHO;
    $modulos = array_fill(0, $n, array_fill(0, $n, false));
    $fixo = array_fill(0, $n, array_fill(0, $n, false));
    $marcar = function ($linha, $coluna, $escuro) use (&$modulos, &$fixo, $n) {
        if ($linha >= 0 && $linha < $n && $coluna >= 0 && $coluna < $n) {
            $modulos[$linha][$coluna] = (bool) $escuro;
            $fixo[$linha][$coluna] = true;
        }
    };

    for ($i = 0; $i < $n; $i++) { // linhas de sincronismo
        $marcar(6, $i, $i % 2 === 0);
        $marcar($i, 6, $i % 2 === 0);
    }
    foreach ([[3, 3], [3, $n - 4], [$n - 4, 3]] as [$cl, $cc]) { // localizadores + separador
        for ($dl = -4; $dl <= 4; $dl++) {
            for ($dc = -4; $dc <= 4; $dc++) {
                $distancia = max(abs($dl), abs($dc));
                $marcar($cl + $dl, $cc + $dc, $distancia !== 2 && $distancia !== 4);
            }
        }
    }
    for ($dl = -2; $dl <= 2; $dl++) { // padrão de alinhamento (único na versão 5)
        for ($dc = -2; $dc <= 2; $dc++) {
            $marcar($n - 7 + $dl, $n - 7 + $dc, max(abs($dl), abs($dc)) !== 1);
        }
    }

    // ----- informação de formato (nível M = 00, máscara) com BCH(15,5)
    $formato = (0b00 << 3) | QR_MASCARA;
    $resto = $formato;
    for ($i = 0; $i < 10; $i++) {
        $resto = ($resto << 1) ^ (($resto >> 9) * 0x537);
    }
    $bitsFormato = (($formato << 10) | $resto) ^ 0x5412;
    $bit = fn($i) => (($bitsFormato >> $i) & 1) === 1;
    for ($i = 0; $i <= 5; $i++) {
        $marcar($i, 8, $bit($i));
    }
    $marcar(7, 8, $bit(6));
    $marcar(8, 8, $bit(7));
    $marcar(8, 7, $bit(8));
    for ($i = 9; $i < 15; $i++) {
        $marcar(8, 14 - $i, $bit($i));
    }
    for ($i = 0; $i < 8; $i++) {
        $marcar(8, $n - 1 - $i, $bit($i));
    }
    for ($i = 8; $i < 15; $i++) {
        $marcar($n - 15 + $i, 8, $bit($i));
    }
    $marcar($n - 8, 8, true); // módulo sempre escuro

    // ----- dados em zigue-zague, de baixo pra cima a partir do canto inferior direito
    $totalBits = count($codewords) * 8;
    $indice = 0;
    for ($direita = $n - 1; $direita >= 1; $direita -= 2) {
        if ($direita === 6) {
            $direita = 5;
        }
        for ($vertical = 0; $vertical < $n; $vertical++) {
            for ($j = 0; $j < 2; $j++) {
                $coluna = $direita - $j;
                $subindo = (($direita + 1) & 2) === 0;
                $linha = $subindo ? $n - 1 - $vertical : $vertical;
                if (!$fixo[$linha][$coluna] && $indice < $totalBits) {
                    $modulos[$linha][$coluna] = (($codewords[$indice >> 3] >> (7 - ($indice & 7))) & 1) === 1;
                    $indice++;
                }
            }
        }
    }

    // ----- máscara 0 nos módulos de dados
    for ($linha = 0; $linha < $n; $linha++) {
        for ($coluna = 0; $coluna < $n; $coluna++) {
            if (!$fixo[$linha][$coluna] && ($linha + $coluna) % 2 === 0) {
                $modulos[$linha][$coluna] = !$modulos[$linha][$coluna];
            }
        }
    }

    return $modulos;
}

function qrSvg($texto, $titulo) {
    $margem = 4; // zona de silêncio exigida pela norma
    $lado = QR_TAMANHO + 2 * $margem;
    $caminho = '';
    foreach (qrMatriz($texto) as $linha => $colunas) {
        // Um retângulo por sequência de módulos escuros na linha, não um por módulo.
        for ($coluna = 0; $coluna < QR_TAMANHO; $coluna++) {
            if (!$colunas[$coluna]) {
                continue;
            }
            $inicio = $coluna;
            while ($coluna + 1 < QR_TAMANHO && $colunas[$coluna + 1]) {
                $coluna++;
            }
            $largura = $coluna - $inicio + 1;
            $caminho .= 'M' . ($inicio + $margem) . ' ' . ($linha + $margem) . "h{$largura}v1h-{$largura}z";
        }
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $lado . ' ' . $lado . '" shape-rendering="crispEdges" role="img" aria-label="'
        . htmlspecialchars($titulo) . '"><rect width="' . $lado . '" height="' . $lado . '" fill="#fff"/><path d="' . $caminho . '" fill="#000"/></svg>';
}

// ---------------------------------------------------------------------
// HTML
// ---------------------------------------------------------------------

function gerarHtml(array $alunos) {
    $e = fn($texto) => htmlspecialchars($texto);

    $secoes = '';
    $grupos = [];
    foreach ($alunos as $a) {
        $grupos[$a['esquadrao']][] = $a;
    }
    foreach ($grupos as $esquadrao => $doEsquadrao) {
        $cartoes = '';
        foreach ($doEsquadrao as $a) {
            $identificacao = "AL {$a['milhao']} {$a['especialidade']} {$a['nome_guerra']}";
            $cartoes .= '<article class="cartao' . ($a['ativo'] ? '' : ' inativo') . '">'
                . qrSvg($a['qrcode_hash'], "QR code de $identificacao")
                . '<h3>' . $e($a['nome_guerra']) . '</h3>'
                . '<p>' . $e("{$a['milhao']} · {$a['especialidade']} · esquadrilha {$a['esquadrilha']}") . '</p>'
                . ($a['ativo'] ? '' : '<p class="aviso">INATIVO — o login tem que ser recusado</p>')
                . '<button type="button" data-hash="' . $e($a['qrcode_hash']) . '">Copiar código</button>'
                . '<code>' . $e($a['qrcode_hash']) . '</code>'
                . "</article>\n";
        }
        $secoes .= '<section><h2>' . $e($esquadrao) . "</h2>\n<div class=\"grade\">\n$cartoes</div></section>\n";
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Argos — QR codes do seed de desenvolvimento</title>
<!-- GERADO por scripts/dev/gerar-seed-qrcodes.php — não edite à mão. -->
<style>
  body { margin: 0; padding: 24px 16px; font: 15px/1.45 system-ui, sans-serif; color: #16202b; background: #f3f5f8; }
  header, section { max-width: 1100px; margin: 0 auto 28px; }
  h1 { margin: 0 0 8px; font-size: 22px; }
  h2 { margin: 0 0 12px; font-size: 16px; text-transform: uppercase; letter-spacing: .06em; color: #47586b; }
  header p { margin: 0 0 6px; max-width: 70ch; }
  .grade { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 14px; }
  .cartao { background: #fff; border: 1px solid #d5dbe3; border-radius: 10px; padding: 14px; text-align: center; break-inside: avoid; }
  .cartao svg { display: block; width: 100%; max-width: 220px; margin: 0 auto 10px; }
  .cartao h3 { margin: 0; font-size: 16px; }
  .cartao p { margin: 2px 0 8px; color: #47586b; font-size: 13px; }
  .cartao code { display: block; margin-top: 8px; font-size: 10px; word-break: break-all; color: #47586b; user-select: all; }
  .cartao button { font: inherit; font-size: 13px; padding: 5px 12px; border: 1px solid #b6c0cc; border-radius: 6px; background: #fff; cursor: pointer; }
  .cartao button:hover { background: #eef2f6; }
  .inativo { background: #fff6f5; border-color: #e3b5ae; }
  .aviso { color: #a3271a !important; font-weight: 600; }
  @media print {
    body { background: #fff; padding: 0; }
    header p, .cartao button { display: none; }
    .grade { grid-template-columns: repeat(3, 1fr); }
  }
</style>
</head>
<body>
<header>
  <h1>Argos — QR codes do seed de desenvolvimento</h1>
  <p>Alunos <strong>fictícios</strong> de <code>database/seed_dev.sql</code>. Abra a Área Funcional (<code>/web/app/</code>) no celular,
     toque em escanear e aponte pra um destes códigos — ou copie o código e cole no campo de login.</p>
  <p>Só valem num banco local com o seed carregado. Nunca em produção.</p>
</header>
$secoes<script>
document.querySelectorAll('button[data-hash]').forEach((botao) => {
  botao.addEventListener('click', async () => {
    await navigator.clipboard.writeText(botao.dataset.hash);
    botao.textContent = 'Copiado';
    setTimeout(() => { botao.textContent = 'Copiar código'; }, 1500);
  });
});
</script>
</body>
</html>

HTML;
}

// ---------------------------------------------------------------------

chdir(dirname(__DIR__, 2));

$alunos = montarAlunos();
$saidas = [SAIDA_SQL => gerarSql($alunos), SAIDA_HTML => gerarHtml($alunos)];

if (in_array('--checar', $argv, true)) {
    $desatualizados = [];
    foreach ($saidas as $arquivo => $conteudo) {
        $atual = is_file($arquivo) ? str_replace("\r\n", "\n", file_get_contents($arquivo)) : null;
        if ($atual !== $conteudo) {
            $desatualizados[] = $arquivo;
        }
    }
    if ($desatualizados) {
        fwrite(STDERR, "Desatualizado(s): " . implode(', ', $desatualizados) . " — rode php scripts/dev/gerar-seed-qrcodes.php e commite.\n");
        exit(1);
    }
    echo "Seed de desenvolvimento em dia (" . count($alunos) . " alunos).\n";
    exit(0);
}

foreach ($saidas as $arquivo => $conteudo) {
    if (!is_dir(dirname($arquivo))) {
        mkdir(dirname($arquivo), 0777, true);
    }
    file_put_contents($arquivo, $conteudo);
    echo "gerado $arquivo\n";
}
echo count($alunos) . " alunos (" . count(array_filter($alunos, fn($a) => !$a['ativo'])) . " inativo).\n";
