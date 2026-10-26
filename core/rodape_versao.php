<?php
// Rodapé institucional — no fim de todas as páginas do Painel e do Ikarus37:
// a quem o sistema pertence, a versão publicada (core/versao.php) e quem
// desenvolveu. Usa as variáveis de cor que cada página já define, com
// fallback pro caso de alguma tela não ter.
$versaoArgos = require __DIR__ . '/versao.php';
?>
<style>
.argos-rodape {
    margin-top: 40px;
    padding: 18px 24px 22px;
    border-top: 1px solid var(--border, #d5dce8);
    font-family: var(--fonte-corpo, 'Segoe UI', system-ui, sans-serif);
    font-size: 12px;
    line-height: 1.6;
    color: var(--text-muted, #5b6b82);
    text-align: center;
}
.argos-rodape strong { color: var(--text, #16202e); font-weight: 600; }
.argos-rodape .instituicao { font-size: 11px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; }
.argos-rodape a.forgejo { display: inline-flex; align-items: center; gap: 6px; color: inherit; text-decoration: none; font-weight: 600; }
.argos-rodape a.forgejo:hover { color: var(--accent, #123c7a); text-decoration: underline; }
.argos-rodape a.forgejo img { width: 16px; height: 16px; }
.argos-rodape code { font-family: var(--fonte-mono, ui-monospace, Consolas, monospace); font-size: 11px; }
@media print { .argos-rodape { display: none; } }
</style>
<footer class="argos-rodape">
    <div class="instituicao">Escola de Especialistas de Aeronáutica · Corpo de Alunos</div>
    <div>
        <strong>Argos <?= htmlspecialchars($versaoArgos['versao']) ?></strong>
        · <code><?= htmlspecialchars($versaoArgos['commit']) ?></code>
        · <?= htmlspecialchars($versaoArgos['data']) ?>
    </div>
    <div>Desenvolvido pelos membros do <strong>CIDIT</strong> — Esquadrão Prata · Turma Ikarus 37</div>
    <div><a class="forgejo" href="https://cosmos.simioni.dev.br/cidit/projeto-argos" target="_blank" rel="noopener"><img src="../images/forgejo.svg" alt="" width="16" height="16">Projeto no Forgejo</a></div>
</footer>
