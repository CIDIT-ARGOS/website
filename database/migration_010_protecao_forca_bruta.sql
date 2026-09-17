SET NAMES utf8mb4;

-- Bloqueio temporário por conta após tentativas de login seguidas erradas —
-- hoje não existe nenhum limite de tentativas em painel/index.php nem em
-- ikarus37/index.php, um script pode tentar senha infinitamente.
ALTER TABLE admin_usuarios
  ADD COLUMN tentativas_login INT NOT NULL DEFAULT 0,
  ADD COLUMN bloqueado_ate DATETIME NULL;

ALTER TABLE painel_usuarios
  ADD COLUMN tentativas_login INT NOT NULL DEFAULT 0,
  ADD COLUMN bloqueado_ate DATETIME NULL;
