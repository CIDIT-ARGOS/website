<?php
// Rodapé de versão — canto inferior esquerdo, discreto, em todas as páginas
// do Painel e do Ikarus37. Ver core/versao.php.
$versaoArgos = require __DIR__ . '/versao.php';
?>
<div style="position:fixed; left:10px; bottom:8px; font-size:11px; font-family:'Segoe UI',system-ui,sans-serif; color:#9aa5b8; background:rgba(255,255,255,0.85); padding:2px 8px; border-radius:6px; pointer-events:none; z-index:9999; user-select:none;">
    Argos <?= htmlspecialchars($versaoArgos['versao']) ?> · <?= htmlspecialchars($versaoArgos['commit']) ?> · <?= htmlspecialchars($versaoArgos['data']) ?>
</div>
