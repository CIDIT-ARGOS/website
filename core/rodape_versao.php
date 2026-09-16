<?php
// Rodapé de versão — canto inferior esquerdo, em todas as páginas do Painel
// e do Ikarus37. Ver core/versao.php.
//
// Some junto ao fundo da página em repouso (sem "adesivo" branco por cima do
// conteúdo) e ganha contraste só no hover — discreto de longe, nítido de perto.
// Usa var(--text-muted), já definida no :root de cada página, com fallback
// pro caso de alguma tela não ter essa variável.
$versaoArgos = require __DIR__ . '/versao.php';
?>
<style>
.argos-rodape-versao {
    position: fixed;
    left: 14px;
    bottom: 10px;
    z-index: 40;
    display: flex;
    align-items: baseline;
    gap: 5px;
    padding: 3px 7px;
    border-radius: 5px;
    font-family: 'Segoe UI', system-ui, sans-serif;
    font-size: 11px;
    letter-spacing: .01em;
    color: var(--text-muted, #8a94a6);
    background: transparent;
    opacity: .55;
    user-select: none;
    white-space: nowrap;
    transition: opacity .15s ease, background-color .15s ease;
}
.argos-rodape-versao:hover {
    opacity: 1;
    background: var(--bg-card, #ffffff);
    box-shadow: 0 1px 3px rgba(16, 24, 40, .12);
}
.argos-rodape-versao code {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 10.5px;
}
</style>
<div class="argos-rodape-versao">
    <span>Argos <?= htmlspecialchars($versaoArgos['versao']) ?></span>
    <span>·</span>
    <code><?= htmlspecialchars($versaoArgos['commit']) ?></code>
    <span>·</span>
    <span><?= htmlspecialchars($versaoArgos['data']) ?></span>
</div>
