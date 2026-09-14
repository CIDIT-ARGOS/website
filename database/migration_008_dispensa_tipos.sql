SET NAMES utf8mb4;
-- Sem isso, um SQL console/mysql CLI que não abre em utf8mb4 por padrão
-- corrompe os acentos deste arquivo ao inserir (double-encoding).

-- "Dispensado de" vira um catálogo de tags (múltipla escolha) em vez de
-- texto solto — ORDEM UNIDA, FORMATURA, ENTRADA EM FORMA, EDUCAÇÃO FÍSICA,
-- SERVIÇO — mais um campo livre "específico" pra quando nenhuma tag serve.
-- dispensas.dispensado_de deixa de ser o campo principal e vira esse campo
-- livre (mantido pra não perder o que já foi cadastrado).

CREATE TABLE dispensa_tipos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(50) NOT NULL UNIQUE,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  ordem INT NOT NULL DEFAULT 0
);

INSERT INTO dispensa_tipos (nome, ordem) VALUES
('Ordem Unida', 1),
('Formatura', 2),
('Entrada em Forma', 3),
('Educação Física', 4),
('Serviço', 5);

CREATE TABLE dispensa_dispensa_tipos (
  dispensa_id INT NOT NULL,
  dispensa_tipo_id INT NOT NULL,

  PRIMARY KEY (dispensa_id, dispensa_tipo_id),
  FOREIGN KEY (dispensa_id) REFERENCES dispensas(id) ON DELETE CASCADE,
  FOREIGN KEY (dispensa_tipo_id) REFERENCES dispensa_tipos(id)
);

-- Reaproveita a permissão gerenciar_motivos (mesmo padrão de governança de
-- catálogo em nível de CA usado pra motivos de falta).
