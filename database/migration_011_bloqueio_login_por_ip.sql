SET NAMES utf8mb4;

-- Complementa a migration_010 (bloqueio por conta): aquela sozinha não
-- trava um ataque de password-spray (1 senha comum testada em muitos
-- usuários diferentes — nenhuma conta individual chega a 5 erros). Isso
-- aqui trava por IP de origem, independente de qual usuário está sendo
-- tentado. Ver issue #23.
CREATE TABLE login_ip_tentativas (
  ip VARCHAR(45) PRIMARY KEY,
  tentativas INT NOT NULL DEFAULT 0,
  bloqueado_ate DATETIME NULL,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
