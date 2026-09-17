SET NAMES utf8mb4;

-- Turmas (issue #32): leva de alunos que entrou junto e vai se formar (sair
-- da escola) junto. "nome" (apelido, ex: "Fera Azul") é o identificador —
-- não curso+série, porque pode haver mais de uma turma do mesmo curso/série
-- ao mesmo tempo (ex: duas CFS na mesma série, como ocorreu na época do
-- Covid). "Formar turma" desativa a turma E todos os alunos vinculados a
-- ela de uma vez — ver core/turmas_core.php::formarTurma().
CREATE TABLE turmas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(50) NOT NULL UNIQUE,
  curso ENUM('CFS', 'EAGS') NOT NULL,
  serie VARCHAR(10) NULL,
  ano_ingresso SMALLINT NOT NULL,
  semestre_ingresso TINYINT NOT NULL,
  ano_formatura_previsto SMALLINT NULL,
  semestre_formatura_previsto TINYINT NULL,
  esquadrao_atual VARCHAR(50) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE alunos
  ADD COLUMN turma_id INT NULL AFTER serie,
  ADD INDEX idx_turma_id (turma_id),
  ADD FOREIGN KEY (turma_id) REFERENCES turmas(id) ON DELETE SET NULL;

-- Turmas atuais (setembro/2026) — cadastradas vazias; os alunos de cada uma
-- são vinculados depois pela tela de Efetivo/Turmas conforme a relação de
-- nomes for se consolidando.
INSERT INTO turmas (nome, curso, serie, ano_ingresso, semestre_ingresso, ano_formatura_previsto, semestre_formatura_previsto, esquadrao_atual) VALUES
('Fera Azul', 'CFS', '4', 2025, 1, 2026, 2, 'Esquadrão Azul'),
('Invictus', 'CFS', '3', 2025, 2, 2027, 1, 'Esquadrão Verde'),
('Ikarus', 'EAGS', NULL, 2026, 1, 2026, 2, 'Esquadrão Prata'),
('Warhank', 'CFS', '2', 2026, 1, 2027, 2, 'Esquadrão Amarelo'),
('Valhalla', 'CFS', '1', 2026, 2, 2028, 1, NULL);

-- Criar/"formar" turma é decisão de nível CA, não de esquadrão — mesmos
-- cargos de gerenciar_dominio, não os de editar_efetivo.
INSERT INTO permissoes (chave, descricao) VALUES
('gerenciar_turmas', 'Cadastrar turmas e "formá-las" (desativar a turma e todos os alunos dela) quando saem da escola');

INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'ikarus37', 'super_admin', id FROM permissoes WHERE chave = 'gerenciar_turmas';

INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'painel', cargo, p.id
FROM (SELECT 'CMD_CA' AS cargo UNION SELECT 'SUBCMD_CA') c
CROSS JOIN permissoes p
WHERE p.chave = 'gerenciar_turmas';
