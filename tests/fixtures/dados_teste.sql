SET NAMES utf8mb4;

-- Dados fictícios pros testes de integração (tests/integracao_test.php).
-- Roda por cima de database/init_db.sql num banco descartável do CI —
-- NUNCA em produção. Os qrcode_hash são fixos pra o teste conseguir logar
-- na API como cada aluno sem precisar de câmera/QR de verdade.
--
-- Dois alunos no Esquadrão Prata/esquadrilha A (efetivo da retirada) e um
-- no Esquadrão Azul, pra provar que um esquadrão não enxerga o outro.

INSERT INTO alunos
  (posto_graduacao, especialidade, nome_guerra, sexo, identidade_militar, qrcode_hash, milhao, esquadrao, esquadrilha, curso, serie)
VALUES
  ('GS', 'SIN', 'TESTE ALFA',  'M', 'CI-0001', '1111111111111111111111111111111111111111111111111111111111111111', '26/9001', 'Esquadrão Prata', 'A', 'CFS', '1'),
  ('GS', 'SIN', 'TESTE BRAVO', 'F', 'CI-0002', '2222222222222222222222222222222222222222222222222222222222222222', '26/9002', 'Esquadrão Prata', 'A', 'CFS', '1'),
  ('GS', 'SIN', 'TESTE CHARLIE', 'M', 'CI-0003', '3333333333333333333333333333333333333333333333333333333333333333', '26/9003', 'Esquadrão Azul', 'A', 'CFS', '1');

-- Aluno inativo: o QR dele não pode abrir sessão.
INSERT INTO alunos
  (posto_graduacao, especialidade, nome_guerra, sexo, identidade_militar, qrcode_hash, milhao, esquadrao, esquadrilha, curso, serie, ativo)
VALUES
  ('GS', 'SIN', 'TESTE INATIVO', 'M', 'CI-0004', '4444444444444444444444444444444444444444444444444444444444444444', '26/9004', 'Esquadrão Prata', 'A', 'CFS', '1', 0);
