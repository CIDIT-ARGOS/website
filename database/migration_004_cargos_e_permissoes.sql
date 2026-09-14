SET NAMES utf8mb4;
-- Sem isso, um SQL console/mysql CLI que não abre em utf8mb4 por padrão
-- corrompe os acentos deste arquivo ao inserir (double-encoding — reproduzido
-- e confirmado testando esta própria migração no Docker local).

-- Fase A (continuação): cargos deixam de ser ENUM fixo e viram um objeto do
-- domínio (tabela `cargos`), editável pelo Controle do Domínio de Negócio —
-- pra criar um cargo novo (ex: "Chefe de Galpão") não precisar mais de deploy.
--
-- Também fecha a lacuna de "como concedo permissão a um cargo sem mexer no
-- banco": tela nova de matriz cargo x permissão em cima de cargo_permissoes
-- (tabela que já existia, só não tinha UI própria ainda).
--
-- Migração real (não só aditiva): painel_usuarios.cargo e admin_usuarios.nivel
-- saem de ENUM pra VARCHAR(30) — os valores existentes continuam válidos como
-- string, nenhuma linha muda de valor.

CREATE TABLE IF NOT EXISTS cargos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sistema ENUM('painel', 'ikarus37') NOT NULL,
  chave VARCHAR(30) NOT NULL,
  nome VARCHAR(100) NOT NULL,
  escopo ENUM('ca', 'esquadrao') NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uk_sistema_chave (sistema, chave)
);

INSERT IGNORE INTO cargos (sistema, chave, nome, escopo) VALUES
('painel', 'CMD_CA', 'Comandante do CA', 'ca'),
('painel', 'SUBCMD_CA', 'Subcomandante do CA', 'ca'),
('painel', 'ADMIN_TECNICO', 'Administrador Técnico', 'ca'),
('painel', 'AUX_CA', 'Auxiliar CA', 'ca'),
('painel', 'CMD_ESQUADRAO', 'Comandante de Esquadrão', 'esquadrao'),
('painel', 'ENC_ESQUADRAO', 'Encarregado de Esquadrão', 'esquadrao'),
('painel', 'AUX_ESQUADRAO', 'Auxiliar de Esquadrão', 'esquadrao'),
('ikarus37', 'super_admin', 'Super administrador', NULL),
('ikarus37', 'admin', 'Administrador', NULL),
('ikarus37', 'suporte', 'Suporte', NULL);

-- ENUM -> VARCHAR: valores existentes são preservados como string.
ALTER TABLE painel_usuarios MODIFY cargo VARCHAR(30) NOT NULL;
ALTER TABLE admin_usuarios MODIFY nivel VARCHAR(30) NOT NULL DEFAULT 'admin';

INSERT IGNORE INTO permissoes (chave, descricao) VALUES
('gerenciar_cargos', 'Criar, editar e desativar cargos (Controle do Domínio de Negócio)'),
('acesso_tecnico_avancado', 'Console SQL, backup, exportar/importar tabela e editor de registro cru — ferramentas de emergência, não o uso do dia a dia');

INSERT IGNORE INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'ikarus37', 'super_admin', id FROM permissoes WHERE chave IN ('gerenciar_cargos', 'acesso_tecnico_avancado');

INSERT IGNORE INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'painel', cargo, p.id
FROM (SELECT 'CMD_CA' AS cargo UNION SELECT 'SUBCMD_CA') c
CROSS JOIN permissoes p
WHERE p.chave = 'gerenciar_cargos';
-- acesso_tecnico_avancado fica só com o Ikarus37 (super_admin) — o painel nunca
-- teve acesso ao console SQL/backup/import cru, e continua sem ter.
