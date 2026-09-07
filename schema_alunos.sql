-- ================= POSTOS / GRADUAÇÕES =================
-- Código interno (usado para sincronizar com o banco da Escola) x rótulo de exibição.
-- Ex.: código "GS" é exibido sempre como "AL".
CREATE TABLE postos_graduacao (
  codigo VARCHAR(10) PRIMARY KEY,
  exibicao VARCHAR(30) NOT NULL
);

INSERT INTO postos_graduacao (codigo, exibicao) VALUES
('GS', 'AL');

-- ================= ALUNOS =================
-- Um aluno é um militar com campos adicionais próprios da condição de aluno.
CREATE TABLE alunos (
  id INT AUTO_INCREMENT PRIMARY KEY,

  -- Dados militares
  posto_graduacao VARCHAR(10) NOT NULL,
  quadro VARCHAR(50) NULL,
  especialidade VARCHAR(50) NULL,
  sub_especialidade VARCHAR(50) NULL,
  nome_guerra VARCHAR(50) NOT NULL,
  sexo ENUM('M', 'F') NOT NULL,
  identidade_militar VARCHAR(30) NOT NULL UNIQUE,
  organizacao_militar VARCHAR(100) NULL,
  setor VARCHAR(50) NULL,
  secao VARCHAR(50) NULL,
  ramal VARCHAR(10) NULL,

  -- Identificação por QR Code (hash sha256 já usado nos demais sistemas da Escola)
  qrcode_hash CHAR(64) NULL UNIQUE,

  -- Dados específicos de aluno
  milhao VARCHAR(20) NOT NULL UNIQUE,
  esquadrao VARCHAR(50) NOT NULL,
  esquadrilha VARCHAR(50) NOT NULL,
  curso ENUM('CFS', 'EAGS') NOT NULL,
  serie VARCHAR(10) NOT NULL,

  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_esquadrilha (esquadrilha),
  INDEX idx_especialidade (especialidade),
  INDEX idx_esquadrao (esquadrao),
  INDEX idx_qrcode_hash (qrcode_hash),

  FOREIGN KEY (posto_graduacao) REFERENCES postos_graduacao(codigo)
);

-- ================= MOTIVOS DE FALTA =================
CREATE TABLE motivos_falta (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(50) NOT NULL UNIQUE,
  requer_observacao TINYINT(1) NOT NULL DEFAULT 0,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  ordem INT NOT NULL DEFAULT 0
);

INSERT INTO motivos_falta (nome, requer_observacao, ordem) VALUES
('Serviço', 0, 1),
('Posto Médico', 0, 2),
('Hospital', 0, 3),
('Comissão', 1, 4),
('Dispensado', 0, 5),
('LNC', 0, 6),
('Detenção', 0, 7),
('Prisão', 0, 8),
('Outro', 1, 9),
('Sem Justificativa', 0, 10);

-- ================= RETIRADAS (chamadas) =================
-- Já deixo pronto para a próxima etapa.
CREATE TABLE retiradas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo ENUM('1_jornada', '2_jornada', 'educacao_fisica', 'pernoite') NOT NULL,
  agrupamento_tipo ENUM('esquadrilha', 'especialidade') NOT NULL,
  agrupamento_valor VARCHAR(50) NOT NULL,
  aluno_servico_id INT NOT NULL,
  status ENUM('pendente', 'enviada') NOT NULL DEFAULT 'pendente',
  protocolo VARCHAR(40) NULL UNIQUE,
  data_hora DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  enviada_em DATETIME NULL,

  FOREIGN KEY (aluno_servico_id) REFERENCES alunos(id)
);

CREATE TABLE retirada_itens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  retirada_id INT NOT NULL,
  aluno_id INT NOT NULL,
  presente TINYINT(1) NOT NULL DEFAULT 1,
  motivo_falta_id INT NULL,
  observacao TEXT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uk_retirada_aluno (retirada_id, aluno_id),
  FOREIGN KEY (retirada_id) REFERENCES retiradas(id),
  FOREIGN KEY (aluno_id) REFERENCES alunos(id),
  FOREIGN KEY (motivo_falta_id) REFERENCES motivos_falta(id)
);
