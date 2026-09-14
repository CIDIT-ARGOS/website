-- Permissões e grupos estilo POSIX/UNIX + a árvore organizacional da escola.
--
-- Aditivo: não mexe em painel_usuarios, cargo_permissoes nem em nenhum cargo que já
-- exista. cargo_permissoes continua valendo exatamente como hoje; isso aqui é uma
-- SEGUNDA fonte de permissão, somada por OR — igual o UNIX combina permissão do
-- dono do arquivo com permissão de grupo. Contas que já existem continuam com o
-- acesso que já tinham, sem nenhuma migração de dado arriscada.
--
-- Serve pra dar acesso a EEAR, DEF, Galpões e Doutrina — que hoje não têm nenhum
-- conceito de cargo no sistema — sem forçar um cargo artificial pra encaixar.

-- ================= UNIDADES (árvore organizacional) =================
CREATE TABLE unidades (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(100) NOT NULL,
  tipo ENUM('escola', 'divisao', 'galpao', 'ca', 'esquadrao', 'doutrina') NOT NULL,
  unidade_pai_id INT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (unidade_pai_id) REFERENCES unidades(id)
);

-- ================= GRUPOS DE ACESSO (estilo POSIX; não confundir com a tabela
-- `grupos`, que são agrupamentos de retirada tipo CIDIT — conceito diferente) =====
CREATE TABLE grupos_acesso (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(100) NOT NULL UNIQUE,
  descricao VARCHAR(255) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Pertencimento: um painel_usuario pode estar em vários grupos_acesso. unidade_id
-- opcional deixa o mesmo grupo (ex: "Comandante") ser reaproveitado em unidades
-- diferentes (a pessoa é "Comandante" + unidade = Esquadrão Prata).
CREATE TABLE grupo_acesso_membros (
  id INT AUTO_INCREMENT PRIMARY KEY,
  grupo_acesso_id INT NOT NULL,
  painel_usuario_id INT NOT NULL,
  unidade_id INT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uk_membro (grupo_acesso_id, painel_usuario_id, unidade_id),
  FOREIGN KEY (grupo_acesso_id) REFERENCES grupos_acesso(id),
  FOREIGN KEY (painel_usuario_id) REFERENCES painel_usuarios(id),
  FOREIGN KEY (unidade_id) REFERENCES unidades(id)
);

-- O que cada grupo concede. unidade_id NULL = concede em qualquer unidade que o
-- membro estiver vinculado; preenchido = só quando o vínculo do membro bate com
-- essa unidade específica.
CREATE TABLE grupo_acesso_permissoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  grupo_acesso_id INT NOT NULL,
  permissao_id INT NOT NULL,
  unidade_id INT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uk_permissao (grupo_acesso_id, permissao_id, unidade_id),
  FOREIGN KEY (grupo_acesso_id) REFERENCES grupos_acesso(id),
  FOREIGN KEY (permissao_id) REFERENCES permissoes(id),
  FOREIGN KEY (unidade_id) REFERENCES unidades(id)
);

-- ================= Semente da árvore organizacional =================
INSERT INTO unidades (nome, tipo, unidade_pai_id) VALUES ('EEAR', 'escola', NULL);
SET @eear = LAST_INSERT_ID();

INSERT INTO unidades (nome, tipo, unidade_pai_id) VALUES
  ('DEF', 'divisao', @eear),
  ('CA', 'ca', @eear);
SET @ca = (SELECT id FROM unidades WHERE nome = 'CA' AND unidade_pai_id = @eear);

INSERT INTO unidades (nome, tipo, unidade_pai_id) VALUES ('Doutrina', 'doutrina', @ca);

-- Esquadrões que já existem no efetivo (alunos.esquadrao) entram como unidades
-- desde já; novos esquadrões (e os Galpões, um por especialidade) são cadastrados
-- pelo Controle do Domínio de Negócio conforme forem existindo.
INSERT INTO unidades (nome, tipo, unidade_pai_id)
SELECT DISTINCT esquadrao, 'esquadrao', @ca FROM alunos WHERE esquadrao IS NOT NULL;

-- ================= Novas permissões do catálogo =================
INSERT INTO permissoes (chave, descricao) VALUES
  ('gerenciar_dominio', 'Acessar o Controle do Domínio de Negócio (CRUD dos objetos do sistema)'),
  ('gerenciar_unidades', 'Criar, editar e excluir unidades organizacionais (EEAR, DEF, Galpões, CA, Esquadrões, Doutrina)'),
  ('gerenciar_grupos_acesso', 'Criar grupos de acesso, gerenciar membros e conceder/revogar permissões');

-- Ikarus37 (super_admin) já gerencia tudo isso, no mesmo padrão das permissões existentes.
INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'ikarus37', 'super_admin', id FROM permissoes
WHERE chave IN ('gerenciar_dominio', 'gerenciar_unidades', 'gerenciar_grupos_acesso');

-- CMD_CA e SUBCMD_CA (visão de todo o CA) também podem gerenciar essa área no painel.
INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'painel', cargo, p.id
FROM (SELECT 'CMD_CA' AS cargo UNION SELECT 'SUBCMD_CA') c
CROSS JOIN permissoes p
WHERE p.chave IN ('gerenciar_dominio', 'gerenciar_unidades', 'gerenciar_grupos_acesso');
