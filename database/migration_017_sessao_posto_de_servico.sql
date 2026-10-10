SET NAMES utf8mb4;

-- Onde o aluno está de serviço nesta sessão do app. A retirada de faltas de um
-- esquadrão quase sempre é feita por alguém de outro esquadrão, então o que o
-- app mostra e lança segue o esquadrão/esquadrilha escolhidos ao entrar, não o
-- esquadrão de origem do aluno. NULL = o do próprio aluno. Provisório, até
-- existir integração com a escala de serviço.
ALTER TABLE api_sessoes
  ADD COLUMN esquadrao_servico VARCHAR(50) NULL AFTER ip,
  ADD COLUMN esquadrilha_servico VARCHAR(50) NULL AFTER esquadrao_servico;
