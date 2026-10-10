SET NAMES utf8mb4;

-- Lançamentos do serviço, na ordem: almoço, 2ª Jornada, pernoite e 1ª Jornada
-- do dia seguinte. Faltava o almoço. Educação Física continua no ENUM só por
-- causa do histórico — deixa de ser oferecida nas telas.
ALTER TABLE retiradas
  MODIFY COLUMN tipo ENUM('1_jornada', '2_jornada', 'educacao_fisica', 'pernoite', 'almoco') NOT NULL;
