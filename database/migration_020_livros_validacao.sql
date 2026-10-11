SET NAMES utf8mb4;

-- Função do aluno de serviço nesta sessão do app: Aluno de Dia à Esquadrilha,
-- ao Esquadrão ou ao Corpo de Alunos. É ela que diz qual livro é o dele e de
-- quem ele valida os livros. NULL = esquadrilha. Provisório, como o posto de
-- serviço da sessão: o próprio aluno escolhe ao entrar, até existir integração
-- com a escala de serviço.
ALTER TABLE api_sessoes
  ADD COLUMN funcao_servico ENUM('esquadrilha', 'esquadrao', 'ca') NULL AFTER esquadrilha_servico;

-- Tramitação do Livro do Dia. Um livro por dia em cada nível:
--   esquadrilha → enviado ao Aluno de Dia ao Esquadrão, que valida ou devolve
--   esquadrao   → reúne as esquadrilhas; enviado ao Aluno de Dia ao CA, que valida ou devolve
--   ca          → reúne os esquadrões; enviado é o Livro do Dia ao Corpo de Alunos
-- O corpo do livro continua sendo montado das chamadas e das dispensas; aqui
-- ficam a situação, as alterações escritas à mão e, depois de enviado, o texto
-- como foi enviado (`conteudo`) — pra que o que foi validado não mude depois.
-- esquadrao/esquadrilha vazios ('') quando não se aplicam ao nível, pra chave
-- única funcionar (NULL não colide em UNIQUE).
CREATE TABLE livros (
  id INT AUTO_INCREMENT PRIMARY KEY,
  data DATE NOT NULL,
  nivel ENUM('esquadrilha', 'esquadrao', 'ca') NOT NULL,
  esquadrao VARCHAR(50) NOT NULL DEFAULT '',
  esquadrilha VARCHAR(50) NOT NULL DEFAULT '',
  status ENUM('rascunho', 'enviado', 'validado', 'devolvido') NOT NULL DEFAULT 'rascunho',
  observacoes TEXT NULL,
  conteudo MEDIUMTEXT NULL,
  editado_por_aluno_id INT NULL,
  enviado_por_aluno_id INT NULL,
  enviado_em DATETIME NULL,
  -- quem validou OU devolveu (o status diz qual dos dois)
  validado_por_aluno_id INT NULL,
  validado_em DATETIME NULL,
  devolucao_motivo VARCHAR(500) NULL,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_livro (data, nivel, esquadrao, esquadrilha),
  FOREIGN KEY (editado_por_aluno_id) REFERENCES alunos(id) ON DELETE SET NULL,
  FOREIGN KEY (enviado_por_aluno_id) REFERENCES alunos(id) ON DELETE SET NULL,
  FOREIGN KEY (validado_por_aluno_id) REFERENCES alunos(id) ON DELETE SET NULL
);
