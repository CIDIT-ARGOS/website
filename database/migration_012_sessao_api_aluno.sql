SET NAMES utf8mb4;

-- Sessão de API por aluno (login via QR code) — issue #26. Até agora, a
-- API só exigia a chave do aplicativo (X-API-Key) pra qualquer operação:
-- com uma chave válida, dava pra listar/enumerar todo o efetivo e abrir
-- retirada sem provar quem de fato está fazendo a chamada. Isso cria uma
-- segunda camada: o aluno "loga" com o QR code dele (alunos.qrcode_hash)
-- e recebe um token de sessão — as rotas que fazem parte do fluxo do app
-- do aluno passam a exigir esse token além da chave.
CREATE TABLE api_sessoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  aluno_id INT NOT NULL,
  api_chave_id INT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_em DATETIME NOT NULL,
  ultimo_uso DATETIME NULL,
  ip VARCHAR(45) NULL,

  INDEX idx_expira_em (expira_em),
  FOREIGN KEY (aluno_id) REFERENCES alunos(id),
  FOREIGN KEY (api_chave_id) REFERENCES api_chaves(id)
);
