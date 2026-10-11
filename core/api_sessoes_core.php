<?php

// Sessão de API por aluno — login via QR code. Segunda camada de
// autenticação (além da X-API-Key do aplicativo) pras rotas que fazem
// parte do fluxo do app do aluno: sem isso, uma chave de API sozinha
// dava acesso total de leitura/escrita à API inteira (issue #26).
//
// A sessão também guarda ONDE o aluno está de serviço (esquadrão e
// esquadrilha): quem tira as faltas de um esquadrão quase sempre é de outro,
// então o que o app mostra e lança segue o posto escolhido ao entrar, não o
// esquadrão de origem do aluno.

const API_SESSAO_TTL_HORAS = 16; // cobre um dia de serviço; loga de novo no próximo turno

// Função do aluno no serviço: Aluno de Dia à Esquadrilha, ao Esquadrão ou ao
// Corpo de Alunos. Diz qual Livro do Dia é o dele (core/livro_fluxo_core.php).
const FUNCOES_DE_SERVICO = ['esquadrilha', 'esquadrao', 'ca'];

/**
 * Cria uma sessão nova pro aluno e devolve o token (só existe em texto
 * puro aqui — no banco só fica o hash, mesmo padrão das chaves de API).
 */
function criarSessaoAluno($conexao, $alunoId, $apiChaveId, $ip) {
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $ttl = API_SESSAO_TTL_HORAS;

    $stmt = mysqli_prepare($conexao, "
        INSERT INTO api_sessoes (aluno_id, api_chave_id, token_hash, expira_em, ip)
        VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR), ?)
    ");
    mysqli_stmt_bind_param($stmt, "iisis", $alunoId, $apiChaveId, $hash, $ttl, $ip);
    mysqli_stmt_execute($stmt);

    return $token;
}

/**
 * Valida um token de sessão. Devolve a linha do aluno (ativo) se a sessão
 * existir e não tiver expirado, ou null caso contrário. Atualiza
 * ultimo_uso como efeito colateral quando válida.
 *
 * Além dos dados do aluno, a linha traz `sessao_id` e o posto de serviço:
 * `esquadrao_servico` e `esquadrilha_servico` — os escolhidos na sessão ou,
 * se ele ainda não escolheu, os do próprio aluno — e `funcao_servico`
 * (esquadrilha, esquadrao ou ca; esquadrilha se ele não escolheu).
 */
function validarSessaoAluno($conexao, $token) {
    if (!$token) {
        return null;
    }
    $hash = hash('sha256', $token);

    $stmt = mysqli_prepare($conexao, "
        SELECT a.*, COALESCE(p.exibicao, a.posto_graduacao) AS posto_exibicao,
               s.id AS sessao_id,
               COALESCE(s.esquadrao_servico, a.esquadrao) AS esquadrao_servico,
               COALESCE(s.esquadrilha_servico, a.esquadrilha) AS esquadrilha_servico,
               COALESCE(s.funcao_servico, 'esquadrilha') AS funcao_servico
        FROM api_sessoes s
        JOIN alunos a ON a.id = s.aluno_id
        LEFT JOIN postos_graduacao p ON p.codigo = a.posto_graduacao
        WHERE s.token_hash = ? AND s.expira_em > NOW() AND a.ativo = 1
    ");
    mysqli_stmt_bind_param($stmt, "s", $hash);
    mysqli_stmt_execute($stmt);
    $linha = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$linha) {
        return null;
    }

    mysqli_query($conexao, "UPDATE api_sessoes SET ultimo_uso = NOW() WHERE id = " . (int) $linha['sessao_id']);
    return $linha;
}

/**
 * Esquadrões e esquadrilhas que existem no efetivo ativo — as opções de
 * "onde você está de serviço". Devolve [['esquadrao' => ..., 'esquadrilhas' => [...]], ...].
 */
function listarPostosDeServicoSessao($conexao) {
    $resultado = mysqli_query($conexao, "
        SELECT DISTINCT esquadrao, esquadrilha FROM alunos WHERE ativo = 1 ORDER BY esquadrao, esquadrilha
    ");
    $porEsquadrao = [];
    while ($linha = mysqli_fetch_assoc($resultado)) {
        $porEsquadrao[$linha['esquadrao']][] = $linha['esquadrilha'];
    }

    $opcoes = [];
    foreach ($porEsquadrao as $esquadrao => $esquadrilhas) {
        $opcoes[] = ['esquadrao' => $esquadrao, 'esquadrilhas' => $esquadrilhas];
    }
    return $opcoes;
}

/**
 * Grava onde o aluno está de serviço nesta sessão. Só aceita esquadrão e
 * esquadrilha que existam no efetivo ativo.
 *
 * $funcao (esquadrilha, esquadrao ou ca) é a função dele no serviço — diz qual
 * Livro do Dia é o dele; null mantém a que já estava.
 *
 * @return array{ok: bool, erro?: string}
 */
function definirPostoDeServicoSessao($conexao, $sessaoId, $esquadrao, $esquadrilha, $funcao = null) {
    if ($funcao !== null && !in_array($funcao, FUNCOES_DE_SERVICO, true)) {
        return ['ok' => false, 'erro' => 'Função inválida. Use esquadrilha, esquadrao ou ca.'];
    }

    $stmt = mysqli_prepare($conexao, "SELECT 1 FROM alunos WHERE ativo = 1 AND esquadrao = ? AND esquadrilha = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "ss", $esquadrao, $esquadrilha);
    mysqli_stmt_execute($stmt);
    if (!mysqli_stmt_get_result($stmt)->fetch_row()) {
        return ['ok' => false, 'erro' => 'Esquadrão ou esquadrilha não encontrados no efetivo.'];
    }

    $stmt = mysqli_prepare($conexao, "
        UPDATE api_sessoes SET esquadrao_servico = ?, esquadrilha_servico = ?, funcao_servico = COALESCE(?, funcao_servico) WHERE id = ?
    ");
    mysqli_stmt_bind_param($stmt, "sssi", $esquadrao, $esquadrilha, $funcao, $sessaoId);
    mysqli_stmt_execute($stmt);
    return ['ok' => true];
}

/**
 * Encerra todas as sessões ativas do aluno (ex: se ele perder o celular —
 * ferramenta de emergência, não usada no fluxo normal ainda).
 */
function encerrarSessoesAluno($conexao, $alunoId) {
    $stmt = mysqli_prepare($conexao, "DELETE FROM api_sessoes WHERE aluno_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $alunoId);
    return mysqli_stmt_execute($stmt);
}
