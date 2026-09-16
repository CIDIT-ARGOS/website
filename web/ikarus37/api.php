<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/auth.php';

$conexao = conectarBanco();
$podeGerenciar = temPermissao($conexao, 'ikarus37', $_SESSION['admin_nivel'], 'gerenciar_api');

$mensagem = null;
$erro = null;
$chaveGerada = null;

// ---------- Criar nova chave ----------
if ($podeGerenciar && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar') {
    $nome = trim($_POST['nome'] ?? '');

    if ($nome === '') {
        $erro = "Dê um nome pra identificar essa chave (ex: \"App Android v1\").";
    } else {
        $chaveGerada = bin2hex(random_bytes(32)); // 64 caracteres hex
        $hash = hash('sha256', $chaveGerada);
        $preview = '...' . substr($chaveGerada, -6);

        $stmt = mysqli_prepare($conexao, "INSERT INTO api_chaves (nome, chave_hash, chave_preview, criado_por) VALUES (?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "ssss", $nome, $hash, $preview, $_SESSION['admin_usuario']);
        mysqli_stmt_execute($stmt);

        $mensagem = "Chave criada. Copie agora — ela não será mostrada de novo.";
    }
}

// ---------- Revogar / reativar ----------
if ($podeGerenciar && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'alternar_status') {
    $id = (int)$_POST['id'];
    mysqli_query($conexao, "UPDATE api_chaves SET ativo = 1 - ativo WHERE id = " . $id);
    $mensagem = "Status da chave atualizado.";
}

// ---------- Excluir ----------
if ($podeGerenciar && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir') {
    $id = (int)$_POST['id'];
    mysqli_query($conexao, "DELETE FROM api_logs WHERE api_chave_id = " . $id);
    mysqli_query($conexao, "DELETE FROM api_chaves WHERE id = " . $id);
    $mensagem = "Chave excluída (e o log associado a ela).";
}

$chaves = mysqli_fetch_all(mysqli_query($conexao, "SELECT * FROM api_chaves ORDER BY criado_em DESC"), MYSQLI_ASSOC);

$logs = mysqli_fetch_all(mysqli_query($conexao, "
    SELECT l.*, c.nome as chave_nome
    FROM api_logs l
    LEFT JOIN api_chaves c ON c.id = l.api_chave_id
    ORDER BY l.criado_em DESC
    LIMIT 50
"), MYSQLI_ASSOC);

$totalChamadas = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT COUNT(*) as total FROM api_logs"))['total'];
$totalErros = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT COUNT(*) as total FROM api_logs WHERE status_code >= 400"))['total'];
$totalChaves = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT COUNT(*) as total FROM api_chaves WHERE ativo = 1"))['total'];

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Ikarus37 — API</title>
<style>
    :root { --bg: #0f1115; --bg-card: #171a21; --border: #2a2e37; --text: #e6e8eb; --text-muted: #9aa1ac; --accent: #4f8cff; --danger: #ff5f5f; --ok: #4caf7d; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); overflow-x: hidden; }
    .topbar { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid var(--border); }
    .topbar .brand { font-weight: 600; }
    .topbar a { color: var(--text-muted); text-decoration: none; font-size: 13px; margin-left: 16px; }
    .topbar a:hover { color: var(--text); }
    .container { padding: 24px; max-width: 1100px; margin: 0 auto; }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
    input, select { padding: 8px 10px; background: #0f1115; border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 13px; }
    button { padding: 8px 14px; background: var(--accent); border: none; border-radius: 6px; color: #fff; font-size: 13px; cursor: pointer; }
    button.danger { background: var(--danger); }
    button.ghost { background: transparent; border: 1px solid var(--border); color: var(--text-muted); }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: 13px; }
    th, td { border: 1px solid var(--border); padding: 8px 10px; text-align: left; vertical-align: top; }
    th { background: #1d212a; color: var(--text-muted); }
    .erro { color: var(--danger); font-size: 13px; }
    .ok { color: var(--ok); font-size: 13px; }
    .form-linha { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .badge { font-size: 11px; padding: 2px 8px; border-radius: 999px; }
    .badge.ativo { background: #17301f; color: var(--ok); }
    .badge.inativo { background: #301717; color: var(--danger); }
    .badge.metodo { background: #1d212a; color: var(--accent); font-family: monospace; }
    .scroll-x { overflow-x: auto; min-width: 0; }
    .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 16px; }
    .kpi { background: #0f1115; border: 1px solid var(--border); border-radius: 8px; padding: 14px; text-align: center; }
    .kpi .valor { font-size: 24px; font-weight: 700; }
    .kpi .rotulo { color: var(--text-muted); font-size: 12px; margin-top: 4px; }
    .chave-nova { background: #0f1115; border: 1px solid var(--ok); border-radius: 8px; padding: 14px; font-family: monospace; font-size: 13px; word-break: break-all; margin-top: 10px; }
    pre { background: #0f1115; border: 1px solid var(--border); border-radius: 8px; padding: 14px; overflow-x: auto; font-size: 12px; color: #cde8ff; }
    .endpoint { border-bottom: 1px solid var(--border); padding: 14px 0; }
    .endpoint:last-child { border-bottom: none; }
    .metodo-tag { display: inline-block; font-family: monospace; font-size: 11px; padding: 2px 8px; border-radius: 4px; font-weight: bold; margin-right: 8px; }
    .metodo-get { background: #12301f; color: #4caf7d; }
    .metodo-post { background: #1a2a3a; color: #4f8cff; }
    .metodo-put { background: #3a2f12; color: #e0b84c; }
    .metodo-delete { background: #301717; color: #ff6b6b; }
    code.rota { font-family: monospace; font-size: 13px; }
    details summary { cursor: pointer; color: var(--accent); font-size: 12px; margin-top: 6px; }
    .i { display: inline-block; width: 13px; height: 13px; vertical-align: -2px; background-color: currentColor; -webkit-mask-image: var(--icon-url); mask-image: var(--icon-url); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; margin-right: 4px; }
</style>
</head>
<body>

<div class="topbar">
    <div class="brand">IKARUS37 <a href="index.php"><span class="i" style="--icon-url:url('../images/icons/arrow-left.svg')"></span>painel</a></div>
    <div><a href="index.php?logout=1"><span class="i" style="--icon-url:url('../images/icons/logout.svg')"></span>sair</a></div>
</div>

<div class="container">
    <h2>API</h2>
    <p style="color: var(--text-muted); font-size: 13px;">Chaves de acesso, monitoramento de requisições e documentação dos endpoints.</p>

    <div class="kpis">
        <div class="kpi"><div class="valor"><?= $totalChaves ?></div><div class="rotulo">Chaves ativas</div></div>
        <div class="kpi"><div class="valor"><?= $totalChamadas ?></div><div class="rotulo">Chamadas registradas</div></div>
        <div class="kpi"><div class="valor" style="color: <?= $totalErros > 0 ? 'var(--danger)' : 'var(--ok)' ?>"><?= $totalErros ?></div><div class="rotulo">Chamadas com erro</div></div>
    </div>

    <?php if ($erro): ?><p class="erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($mensagem): ?><p class="ok"><?= htmlspecialchars($mensagem) ?></p><?php endif; ?>

    <?php if ($chaveGerada): ?>
        <div class="card">
            <h4>Nova chave gerada</h4>
            <div class="chave-nova"><?= htmlspecialchars($chaveGerada) ?></div>
            <p style="color: var(--text-muted); font-size: 12px; margin-top: 8px;">
                Copie agora e guarde num lugar seguro. Depois de sair dessa tela, só o preview (últimos caracteres) fica visível.
                Uso: header <code>X-API-Key: <?= htmlspecialchars($chaveGerada) ?></code>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($podeGerenciar): ?>
    <div class="card">
        <h4>Nova chave de API (app conectado)</h4>
        <form method="post" class="form-linha">
            <input type="hidden" name="acao" value="criar">
            <input type="text" name="nome" placeholder='Nome do app (ex: "App Android v1")' required style="flex:1; min-width:200px;">
            <button type="submit">Gerar chave</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="card">
        <h4>Chaves existentes (apps conectados)</h4>
        <div class="scroll-x">
        <table>
            <tr>
                <th>Nome</th><th>Preview</th><th>Status</th><th>Criada por</th><th>Criada em</th><th>Último uso</th>
                <?php if ($podeGerenciar): ?><th>Ações</th><?php endif; ?>
            </tr>
            <?php foreach ($chaves as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($c['nome']) ?></td>
                    <td><code><?= htmlspecialchars($c['chave_preview']) ?></code></td>
                    <td><span class="badge <?= $c['ativo'] ? 'ativo' : 'inativo' ?>"><?= $c['ativo'] ? 'ativa' : 'revogada' ?></span></td>
                    <td><?= htmlspecialchars($c['criado_por'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($c['criado_em']) ?></td>
                    <td><?= htmlspecialchars($c['ultimo_uso'] ?? 'nunca usada') ?></td>
                    <?php if ($podeGerenciar): ?>
                    <td style="white-space:nowrap;">
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="acao" value="alternar_status">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button type="submit" class="ghost"><?= $c['ativo'] ? 'revogar' : 'reativar' ?></button>
                        </form>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Excluir esta chave e todo o log dela?');">
                            <input type="hidden" name="acao" value="excluir">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button type="submit" class="danger"><span class="i" style="--icon-url:url('../images/icons/trash.svg')"></span>excluir</button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($chaves)): ?>
                <tr><td colspan="7" style="color: var(--text-muted);">Nenhuma chave cadastrada.</td></tr>
            <?php endif; ?>
        </table>
        </div>
    </div>

    <div class="card">
        <h4>Log de requisições (últimas 50)</h4>
        <div class="scroll-x">
        <table>
            <tr><th>Data/Hora</th><th>Chave</th><th>Método</th><th>Endpoint</th><th>Status</th><th>IP</th></tr>
            <?php foreach ($logs as $l): ?>
                <tr>
                    <td><?= htmlspecialchars($l['criado_em']) ?></td>
                    <td><?= htmlspecialchars($l['chave_nome'] ?? '(chave inválida/removida)') ?></td>
                    <td><span class="badge metodo"><?= htmlspecialchars($l['metodo']) ?></span></td>
                    <td><code><?= htmlspecialchars($l['endpoint']) ?></code></td>
                    <td style="color: <?= $l['status_code'] >= 400 ? 'var(--danger)' : 'var(--ok)' ?>"><?= $l['status_code'] ?></td>
                    <td><?= htmlspecialchars($l['ip'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($logs)): ?>
                <tr><td colspan="6" style="color: var(--text-muted);">Nenhuma chamada registrada ainda.</td></tr>
            <?php endif; ?>
        </table>
        </div>
    </div>

    <div class="card">
        <h4>Documentação dos endpoints</h4>
        <p style="color: var(--text-muted); font-size: 13px;">
            Base: <code class="rota">https://sigsaeear.com/cidit/projetos/11/api/</code><br>
            Toda requisição precisa do header <code>X-API-Key: &lt;sua chave&gt;</code>. Corpo em JSON, respostas em JSON.
        </p>

        <div class="endpoint">
            <span class="metodo-tag metodo-get">GET</span><code class="rota">alunos.php</code> — lista alunos
            <p style="color: var(--text-muted); font-size: 12px;">Filtros opcionais: <code>?esquadrilha=</code>, <code>?especialidade=</code>, <code>?esquadrao=</code>, <code>?curso=</code>, <code>?qrcode_hash=</code>, <code>?id=</code> (um só), <code>?incluir_inativos</code></p>
        </div>
        <div class="endpoint">
            <span class="metodo-tag metodo-post">POST</span><code class="rota">alunos.php</code> — cria aluno
            <details><summary>ver exemplo de corpo</summary>
<pre>{
  "posto_graduacao": "GS", "nome_guerra": "TESTE", "sexo": "M",
  "identidade_militar": "26/9999", "milhao": "26/9999",
  "esquadrao": "PRATA", "esquadrilha": "A", "curso": "EAGS", "serie": "EAGS"
}</pre>
            </details>
        </div>
        <div class="endpoint">
            <span class="metodo-tag metodo-put">PUT</span><code class="rota">alunos.php?id=X</code> — atualiza aluno (mesmo corpo do POST)
        </div>
        <div class="endpoint">
            <span class="metodo-tag metodo-delete">DELETE</span><code class="rota">alunos.php?id=X</code> — inativa aluno (soft delete)
        </div>
        <div class="endpoint">
            <span class="metodo-tag metodo-get">GET</span><code class="rota">motivos.php</code> — lista motivos de falta ativos
        </div>
        <div class="endpoint">
            <span class="metodo-tag metodo-get">GET</span><code class="rota">retiradas.php</code> — lista retiradas
            <p style="color: var(--text-muted); font-size: 12px;">Filtros: <code>?esquadrao=</code>, <code>?status=</code>, <code>?tipo=</code>, <code>?id=</code></p>
        </div>
        <div class="endpoint">
            <span class="metodo-tag metodo-post">POST</span><code class="rota">retiradas.php</code> — abre uma retirada (já cria os itens, todos "presente")
            <details><summary>ver exemplo de corpo</summary>
<pre>{
  "tipo": "1_jornada",
  "agrupamento_tipo": "esquadrilha",
  "agrupamento_valor": "A",
  "esquadrao": "PRATA",
  "responsavel_nome": "AL 26/3138 SIN SIMIONI"
}</pre>
            </details>
            <p style="color: var(--text-muted); font-size: 12px;"><code>responsavel_nome</code> é obrigatório — a API ainda não tem login de usuário, então quem chama é responsável por informar quem de fato está abrindo a retirada (isso muda quando o app tiver autenticação própria). <code>aluno_servico_id</code> é opcional e legado.</p>
        </div>
        <div class="endpoint">
            <span class="metodo-tag metodo-put">PUT</span><code class="rota">retiradas.php?id=X&acao=enviar</code> — fecha a retirada e gera protocolo
        </div>
        <div class="endpoint">
            <span class="metodo-tag metodo-delete">DELETE</span><code class="rota">retiradas.php?id=X</code> — exclui a retirada e seus itens
        </div>
        <div class="endpoint">
            <span class="metodo-tag metodo-get">GET</span><code class="rota">retirada_itens.php?retirada_id=X</code> — lista o efetivo da retirada com status atual
        </div>
        <div class="endpoint">
            <span class="metodo-tag metodo-put">PUT</span><code class="rota">retirada_itens.php?retirada_id=X&aluno_id=Y</code> — marca presença/falta de um aluno
            <details><summary>ver exemplo de corpo</summary>
<pre>{ "presente": 0, "motivo_falta_id": 1, "observacao": "opcional" }</pre>
            </details>
        </div>
        <div class="endpoint">
            <span class="metodo-tag metodo-get">GET</span><code class="rota">grupos.php</code> — lista grupos ativos (ex: CIDIT)
            <p style="color: var(--text-muted); font-size: 12px;"><code>?id=X</code> retorna um grupo com seus membros.</p>
        </div>
        <div class="endpoint">
            <span class="metodo-tag metodo-post">POST</span><code class="rota">grupos.php</code> — cria grupo <code>{ "nome": "...", "categoria": "clube|servico|comissao" }</code>
            <p style="color: var(--text-muted); font-size: 12px;"><code>?id=X</code> + corpo <code>{ "aluno_id": Y }</code> adiciona um membro ao grupo X.</p>
        </div>
        <div class="endpoint">
            <span class="metodo-tag metodo-delete">DELETE</span><code class="rota">grupos.php?id=X&aluno_id=Y</code> — remove um membro do grupo
        </div>
        <div class="endpoint">
            <span class="metodo-tag metodo-get">GET</span><code class="rota">painel_usuarios.php</code> — lista usuários do Painel de Comando (sem senha)
            <p style="color: var(--text-muted); font-size: 12px;"><code>?id=X</code> retorna um usuário só.</p>
        </div>
        <div class="endpoint">
            <span class="metodo-tag metodo-post">POST</span><code class="rota">painel_usuarios.php</code> — cria usuário
            <details><summary>ver exemplo de corpo</summary>
<pre>{ "nome": "...", "usuario": "...", "senha": "...", "cargo": "CMD_ESQUADRAO", "esquadrao": "PRATA" }</pre>
            </details>
        </div>
        <div class="endpoint">
            <span class="metodo-tag metodo-put">PUT</span><code class="rota">painel_usuarios.php?id=X</code> — atualiza nome/cargo/esquadrão/ativo
            <p style="color: var(--text-muted); font-size: 12px;"><code>?id=X&acao=resetar_senha</code> + corpo <code>{ "nova_senha": "..." }</code> redefine a senha.</p>
        </div>
        <div class="endpoint">
            <span class="metodo-tag metodo-delete">DELETE</span><code class="rota">painel_usuarios.php?id=X</code> — exclui usuário
        </div>
        <div class="endpoint">
            <span class="metodo-tag metodo-get">GET</span><code class="rota">relatorios.php</code> — resumo, faltas por motivo e retiradas do período
            <p style="color: var(--text-muted); font-size: 12px;">Filtros: <code>?data_inicio=</code>, <code>?data_fim=</code>, <code>?tipo=</code>, <code>?esquadrao=</code></p>
        </div>
        <div class="endpoint">
            <span class="metodo-tag metodo-get">GET</span><code class="rota">situacao.php</code> — destinômetro: onde cada aluno está agora (presente/ausente e motivo), a partir da última chamada enviada em que apareceu
            <p style="color: var(--text-muted); font-size: 12px;">Filtros: <code>?esquadrao=</code>, <code>?esquadrilha=</code>, <code>?busca=</code> (nome ou milhão), <code>?somente_ausentes</code>. Retorna <code>resumo</code> (KPIs: efetivo, presentes, ausentes, sem registro, faltas por motivo) e <code>alunos</code> (lista).</p>
        </div>

        <p style="color: var(--text-muted); font-size: 12px; margin-top: 16px;">Exemplo com curl:</p>
<pre>curl -H "X-API-Key: SUA_CHAVE" \
  "https://sigsaeear.com/cidit/projetos/11/api/alunos.php?esquadrilha=A"</pre>
    </div>
</div>

<?php include __DIR__ . '/../../core/rodape_versao.php'; ?>
</body>
</html>
<?php mysqli_close($conexao); ?>
