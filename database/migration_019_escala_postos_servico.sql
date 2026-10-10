SET NAMES utf8mb4;

-- Quem está de serviço em cada posto. Diferente dos clubes, o posto não tem
-- membros fixos: a pessoa muda todo dia, e às vezes no meio do serviço (por
-- alguma pane). Cada linha é um período de alguém num posto — fim NULL quer
-- dizer que ele está de serviço agora. Trocar = encerrar a linha de quem sai
-- (com o motivo, se houver) e abrir a de quem entra; nada é apagado, então
-- fica o histórico de quem tirou cada serviço.
CREATE TABLE posto_servico_escalas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  posto_servico_id INT NOT NULL,
  aluno_id INT NOT NULL,
  inicio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fim DATETIME NULL,
  motivo_saida VARCHAR(255) NULL,
  registrado_por VARCHAR(100) NULL,
  painel_usuario_id INT NULL,

  INDEX idx_posto_aberta (posto_servico_id, fim),
  INDEX idx_aluno_aberta (aluno_id, fim),
  FOREIGN KEY (posto_servico_id) REFERENCES postos_servico(id),
  FOREIGN KEY (aluno_id) REFERENCES alunos(id),
  FOREIGN KEY (painel_usuario_id) REFERENCES painel_usuarios(id) ON DELETE SET NULL
);
