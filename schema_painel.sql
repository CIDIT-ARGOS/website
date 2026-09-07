-- ================= USUÁRIOS DO PAINEL (cadeia de comando) =================
-- Diferente de admin_usuarios (que é da área técnica /ikarus37).
-- Cargos com visão de todo o CA não têm esquadrão amarrado (NULL).
-- Cargos de esquadrão só enxergam o próprio esquadrão.
CREATE TABLE painel_usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(100) NOT NULL,
  usuario VARCHAR(50) NOT NULL UNIQUE,
  senha_hash VARCHAR(255) NOT NULL,
  cargo ENUM('CMD_CA', 'SUBCMD_CA', 'CMD_ESQUADRAO', 'ENC_ESQUADRAO', 'AUX_ESQUADRAO') NOT NULL,
  esquadrao VARCHAR(50) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_login DATETIME NULL,

  CONSTRAINT chk_esquadrao_por_cargo CHECK (
    (cargo IN ('CMD_CA', 'SUBCMD_CA') AND esquadrao IS NULL) OR
    (cargo IN ('CMD_ESQUADRAO', 'ENC_ESQUADRAO', 'AUX_ESQUADRAO') AND esquadrao IS NOT NULL)
  )
);
