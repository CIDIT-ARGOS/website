<?php

// Sessão de API por aluno — login via QR code. Segunda camada de
// autenticação (além da X-API-Key do aplicativo) pras rotas que fazem
// parte do fluxo do app do aluno: sem isso, uma chave de API sozinha
// dava acesso total de leitura/escrita à API inteira (issue #26).

const API_SESSAO_TTL_HORAS = 16; // cobre um dia de serviço; loga de novo no próximo turno

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
 */
function validarSessaoAluno($conexao, $token) {
    if (!$token) {
        return null;
    }
    $hash = hash('sha256', $token);

    $stmt = mysqli_prepare($conexao, "
        SELECT s.id AS sessao_id, a.*, COALESCE(p.exibicao, a.posto_graduacao) AS posto_exibicao
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
    unset($linha['sessao_id']);
    return $linha;
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
