-- ================= PERMISSÕES =================
-- Modelo único de permissões, compartilhado entre os dois sistemas de login
-- (admin_usuarios do /ikarus37 e painel_usuarios do /painel).
CREATE TABLE permissoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  chave VARCHAR(50) NOT NULL UNIQUE,
  descricao VARCHAR(150) NOT NULL
);

INSERT INTO permissoes (chave, descricao) VALUES
('gerenciar_usuarios_admin', 'Criar, editar e excluir usuários administradores técnicos (admin_usuarios)'),
('gerenciar_usuarios_painel', 'Criar, editar e excluir usuários do painel de comando (painel_usuarios)'),
('ver_todos_esquadroes', 'Visualizar efetivo e relatórios de todos os esquadrões, não só o próprio'),
('editar_efetivo', 'Editar dados de alunos no painel');

-- Associação: qual cargo, em qual sistema, tem qual permissão.
CREATE TABLE cargo_permissoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sistema ENUM('ikarus37', 'painel') NOT NULL,
  cargo VARCHAR(30) NOT NULL,
  permissao_id INT NOT NULL,

  UNIQUE KEY uk_sistema_cargo_permissao (sistema, cargo, permissao_id),
  FOREIGN KEY (permissao_id) REFERENCES permissoes(id)
);

-- ---------- Ikarus37 (cargo = nivel de admin_usuarios) ----------
INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'ikarus37', 'super_admin', id FROM permissoes
WHERE chave IN ('gerenciar_usuarios_admin', 'gerenciar_usuarios_painel');
-- admin, suporte: nenhuma permissão de gestão de usuários por padrão.

-- ---------- Painel (cargo = painel_usuarios.cargo) ----------
INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'painel', 'CMD_CA', id FROM permissoes
WHERE chave IN ('gerenciar_usuarios_painel', 'ver_todos_esquadroes', 'editar_efetivo');

INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'painel', 'SUBCMD_CA', id FROM permissoes
WHERE chave IN ('gerenciar_usuarios_painel', 'ver_todos_esquadroes', 'editar_efetivo');

INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'painel', 'CMD_ESQUADRAO', id FROM permissoes
WHERE chave IN ('editar_efetivo');

INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'painel', 'ENC_ESQUADRAO', id FROM permissoes
WHERE chave IN ('editar_efetivo');

-- AUX_ESQUADRAO: nenhuma permissão especial — só visualização (padrão de quem está logado).
