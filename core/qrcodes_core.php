<?php

// QR code dos alunos: é a credencial com que o aluno entra na Área Funcional.
//
// O sistema aceita QR com QUALQUER conteúdo (código curto, texto longo, link —
// o que vier na identidade). O que fica guardado em alunos.qrcode_hash é só a
// impressão digital (sha256) do que foi lido; o conteúdo do QR nunca é
// gravado nem aparece em log. No login o app manda o que leu, o servidor
// calcula a mesma impressão e compara.
//
// Usado pela API (/api/sessao.php) e pela tela de controle de QR codes do
// Painel e do Ikarus37 (core/qrcodes_tela.php).

// Folga pra QR denso (um QR versão 40 leva até uns 3 mil caracteres).
const QR_CONTEUDO_MAX = 4096;

/**
 * Impressão digital de um conteúdo de QR, ou null se estiver vazio ou grande
 * demais.
 *
 * Um QR cujo conteúdo JÁ é um código de 64 caracteres hexadecimais vale como
 * a própria impressão — era o único formato aceito antes, e é o dos QR de
 * teste do seed de desenvolvimento. Qualquer outro conteúdo passa por sha256.
 */
function impressaoDoQr($conteudo) {
    if (!is_string($conteudo)) {
        return null;
    }
    $conteudo = trim($conteudo);
    if ($conteudo === '' || strlen($conteudo) > QR_CONTEUDO_MAX) {
        return null;
    }
    if (preg_match('/^[a-f0-9]{64}$/i', $conteudo)) {
        return strtolower($conteudo);
    }
    return hash('sha256', $conteudo);
}

/** O aluno (ativo ou não) dono dessa impressão, ou null. */
function buscarAlunoPorImpressaoQr($conexao, $impressao) {
    $stmt = mysqli_prepare($conexao, "
        SELECT a.*, COALESCE(p.exibicao, a.posto_graduacao) AS posto_exibicao
        FROM alunos a
        LEFT JOIN postos_graduacao p ON p.codigo = a.posto_graduacao
        WHERE a.qrcode_hash = ?
    ");
    mysqli_stmt_bind_param($stmt, "s", $impressao);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

/**
 * Cadastra (ou troca) o QR de um aluno a partir do conteúdo lido.
 *
 * @return array{ok: bool, erro?: string, trocou?: bool}
 */
function definirQrDoAluno($conexao, $alunoId, $conteudo) {
    $impressao = impressaoDoQr($conteudo);
    if ($impressao === null) {
        return ['ok' => false, 'erro' => 'QR vazio ou grande demais (máximo de ' . QR_CONTEUDO_MAX . ' caracteres).'];
    }

    $stmt = mysqli_prepare($conexao, "SELECT id, qrcode_hash FROM alunos WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $alunoId);
    mysqli_stmt_execute($stmt);
    $aluno = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$aluno) {
        return ['ok' => false, 'erro' => 'Aluno não encontrado.'];
    }

    $dono = buscarAlunoPorImpressaoQr($conexao, $impressao);
    if ($dono && (int) $dono['id'] !== (int) $alunoId) {
        return ['ok' => false, 'erro' => "Esse QR já está cadastrado para {$dono['milhao']} {$dono['nome_guerra']}. Cada QR é de um aluno só."];
    }

    $stmt = mysqli_prepare($conexao, "UPDATE alunos SET qrcode_hash = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "si", $impressao, $alunoId);
    if (!mysqli_stmt_execute($stmt)) {
        return ['ok' => false, 'erro' => 'Erro ao gravar o QR: ' . mysqli_error($conexao)];
    }

    // QR trocado = credencial trocada: quem estava logado com o antigo sai.
    $trocou = $aluno['qrcode_hash'] !== null && $aluno['qrcode_hash'] !== $impressao;
    if ($trocou) {
        _encerrarSessoesDoAluno($conexao, $alunoId);
    }
    return ['ok' => true, 'trocou' => $trocou];
}

/** Tira o QR do aluno: ele deixa de conseguir entrar no app, e a sessão aberta cai. */
function removerQrDoAluno($conexao, $alunoId) {
    $stmt = mysqli_prepare($conexao, "UPDATE alunos SET qrcode_hash = NULL WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $alunoId);
    mysqli_stmt_execute($stmt);
    _encerrarSessoesDoAluno($conexao, $alunoId);
    return ['ok' => true];
}

function _encerrarSessoesDoAluno($conexao, $alunoId) {
    $stmt = mysqli_prepare($conexao, "DELETE FROM api_sessoes WHERE aluno_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $alunoId);
    mysqli_stmt_execute($stmt);
}

/**
 * Cobertura: quantos alunos ativos têm QR, no total e por esquadrão.
 * $escopo limita a um esquadrão (null = todos).
 *
 * @return array{total: int, com_qr: int, sem_qr: int, por_esquadrao: array}
 */
function coberturaDeQr($conexao, $escopo = null) {
    $sql = "SELECT esquadrao, COUNT(*) AS total, SUM(qrcode_hash IS NOT NULL) AS com_qr FROM alunos WHERE ativo = 1";
    if ($escopo !== null) {
        $sql .= " AND esquadrao = ?";
    }
    $sql .= " GROUP BY esquadrao ORDER BY esquadrao";

    $stmt = mysqli_prepare($conexao, $sql);
    if ($escopo !== null) {
        mysqli_stmt_bind_param($stmt, "s", $escopo);
    }
    mysqli_stmt_execute($stmt);

    $porEsquadrao = [];
    $total = 0;
    $comQr = 0;
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC) as $linha) {
        $linha['total'] = (int) $linha['total'];
        $linha['com_qr'] = (int) $linha['com_qr'];
        $linha['sem_qr'] = $linha['total'] - $linha['com_qr'];
        $porEsquadrao[] = $linha;
        $total += $linha['total'];
        $comQr += $linha['com_qr'];
    }

    return ['total' => $total, 'com_qr' => $comQr, 'sem_qr' => $total - $comQr, 'por_esquadrao' => $porEsquadrao];
}

/**
 * Alunos ativos com a situação do QR de cada um.
 * $filtros: esquadrao, situacao ('com' | 'sem'), busca (nome de guerra ou milhão), limite.
 * Devolve [linhas, total que bate com o filtro].
 */
function listarAlunosComSituacaoDoQr($conexao, $filtros = []) {
    $condicoes = ["a.ativo = 1"];
    $params = [];
    $tipos = "";

    if (!empty($filtros['esquadrao'])) {
        $condicoes[] = "a.esquadrao = ?";
        $params[] = $filtros['esquadrao'];
        $tipos .= "s";
    }
    if (($filtros['situacao'] ?? '') === 'com') {
        $condicoes[] = "a.qrcode_hash IS NOT NULL";
    } elseif (($filtros['situacao'] ?? '') === 'sem') {
        $condicoes[] = "a.qrcode_hash IS NULL";
    }
    if (!empty($filtros['busca'])) {
        $condicoes[] = "(a.nome_guerra LIKE ? OR a.milhao LIKE ?)";
        $termo = "%" . $filtros['busca'] . "%";
        $params[] = $termo;
        $params[] = $termo;
        $tipos .= "ss";
    }
    $where = "WHERE " . implode(" AND ", $condicoes);

    $stmt = mysqli_prepare($conexao, "SELECT COUNT(*) FROM alunos a $where");
    if ($params) {
        mysqli_stmt_bind_param($stmt, $tipos, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $total = (int) mysqli_stmt_get_result($stmt)->fetch_row()[0];

    $limite = max(1, (int) ($filtros['limite'] ?? 100));
    $stmt = mysqli_prepare($conexao, "
        SELECT a.id, a.posto_graduacao, COALESCE(p.exibicao, a.posto_graduacao) AS posto_exibicao,
               a.especialidade, a.nome_guerra, a.milhao, a.esquadrao, a.esquadrilha,
               (a.qrcode_hash IS NOT NULL) AS tem_qr
        FROM alunos a
        LEFT JOIN postos_graduacao p ON p.codigo = a.posto_graduacao
        $where
        ORDER BY a.esquadrao, a.esquadrilha, a.nome_guerra
        LIMIT $limite
    ");
    if ($params) {
        mysqli_stmt_bind_param($stmt, $tipos, ...$params);
    }
    mysqli_stmt_execute($stmt);

    return [mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC), $total];
}

/**
 * Cadastro em lote: uma linha por aluno, "milhão;conteúdo do QR" (aceita
 * também vírgula ou tabulação como separador — o que vier depois do primeiro
 * separador é o conteúdo, inteiro). $escopo limita a alunos de um esquadrão.
 *
 * @return array{gravados: int, erros: string[]}
 */
function importarQrsEmLote($conexao, $texto, $escopo = null) {
    $gravados = 0;
    $erros = [];

    foreach (preg_split('/\r\n|\r|\n/', (string) $texto) as $i => $linha) {
        $linha = trim($linha);
        if ($linha === '') {
            continue;
        }
        $numero = $i + 1;

        if (!preg_match('/^([^;,\t]+)[;,\t](.+)$/', $linha, $m)) {
            $erros[] = "Linha $numero: use o formato milhão;conteúdo do QR.";
            continue;
        }
        $milhao = trim($m[1]);

        $stmt = mysqli_prepare($conexao, "SELECT id, esquadrao FROM alunos WHERE milhao = ? AND ativo = 1");
        mysqli_stmt_bind_param($stmt, "s", $milhao);
        mysqli_stmt_execute($stmt);
        $aluno = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if (!$aluno || ($escopo !== null && $aluno['esquadrao'] !== $escopo)) {
            $erros[] = "Linha $numero: aluno de milhão $milhao não encontrado" . ($escopo !== null ? " no Esquadrão $escopo" : '') . ".";
            continue;
        }

        $resultado = definirQrDoAluno($conexao, (int) $aluno['id'], $m[2]);
        if ($resultado['ok']) {
            $gravados++;
        } else {
            $erros[] = "Linha $numero ($milhao): {$resultado['erro']}";
        }
    }

    return ['gravados' => $gravados, 'erros' => $erros];
}
