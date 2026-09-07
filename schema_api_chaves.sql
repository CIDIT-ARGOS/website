-- ================= CHAVES DE API (Apps conectados) =================
-- Substitui a chave fixa em config.php por chaves reais, revogáveis, com log de uso.
CREATE TABLE api_chaves (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(100) NOT NULL,
  chave_hash CHAR(64) NOT NULL UNIQUE,
  chave_preview VARCHAR(8) NOT NULL, -- últimos caracteres, só pra identificar visualmente
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_por VARCHAR(50) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_uso DATETIME NULL
);

-- ================= LOG DE REQUISIÇÕES DA API =================
CREATE TABLE api_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  api_chave_id INT NULL,
  endpoint VARCHAR(100) NOT NULL,
  metodo VARCHAR(10) NOT NULL,
  status_code INT NOT NULL,
  ip VARCHAR(45) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_criado_em (criado_em),
  FOREIGN KEY (api_chave_id) REFERENCES api_chaves(id)
);

-- Migra a chave fixa que já estava em uso (config.php) pra não quebrar quem já testou a API.
INSERT INTO api_chaves (nome, chave_hash, chave_preview, criado_por) VALUES
('Chave inicial (migrada do config.php)', '98d41c1a6c7e80c8b4addcde33b4425c5180b590b34f388e81cfd494bb0638ef', '...f058', 'sistema');

-- ================= PERMISSÃO: GERENCIAR CHAVES DE API =================
INSERT INTO permissoes (chave, descricao) VALUES
('gerenciar_api', 'Criar, revogar e monitorar chaves de API (apps conectados)');

INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'ikarus37', 'super_admin', id FROM permissoes WHERE chave = 'gerenciar_api';
