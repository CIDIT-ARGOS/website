SET NAMES utf8mb4;

-- Quem concedeu a dispensa: o Oficial Médico responsável. Obrigatório nas
-- dispensas novas (validado no código); as antigas ficam sem.
ALTER TABLE dispensas
  ADD COLUMN medico_responsavel VARCHAR(100) NULL AFTER motivo;
