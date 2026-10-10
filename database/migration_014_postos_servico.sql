SET NAMES utf8mb4;

-- Postos de serviço (ex: Aluno de Dia, Plantão, Sentinela): catálogo editável
-- em Controle do Domínio de Negócio → Postos de serviço. Na chamada, quando o
-- motivo da ausência é "Serviço", dá pra dizer em qual posto o aluno está.
--
-- O código (core/servicos_core.php, painel/retirada_marcar.php) já consultava
-- essa tabela, mas nenhuma migration a criava.
CREATE TABLE postos_servico (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(100) NOT NULL UNIQUE,
  codigo VARCHAR(10) NULL,
  ordem INT NOT NULL DEFAULT 0,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE retirada_itens
  ADD COLUMN servico_id INT NULL AFTER motivo_falta_id,
  ADD CONSTRAINT fk_retirada_itens_servico FOREIGN KEY (servico_id) REFERENCES postos_servico(id) ON DELETE SET NULL;
