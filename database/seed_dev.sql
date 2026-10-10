SET NAMES utf8mb4;

-- Seed de DESENVOLVIMENTO: alunos fictícios com QR code, pra testar o login da
-- PWA "Área Funcional" num banco local. Os QR codes estão em docs/dev/qrcodes-seed.html.
--
-- GERADO por scripts/dev/gerar-seed-qrcodes.php — não edite à mão.
-- NUNCA carregue em produção: os qrcode_hash abaixo são públicos.
--
-- Roda por cima de database/init_db.sql (+ migrations). Pode rodar de novo:
-- quem já existe só tem o qrcode_hash e o ativo restaurados.

INSERT INTO alunos
  (posto_graduacao, especialidade, nome_guerra, sexo, identidade_militar, qrcode_hash, milhao, esquadrao, esquadrilha, curso, serie, turma_id, ativo)
VALUES
  ('GS', 'SIN', 'ALMEIDA', 'M', 'DEV-0001', '1c48f22af217333de432b349746b787c1a1fe5b8181539039a070095b94290a4', '26/8001', 'Esquadrão Prata', 'A', 'EAGS', 'EAGS', (SELECT id FROM turmas WHERE nome = 'Ikarus'), 1),
  ('GS', 'BMA', 'BARROS', 'F', 'DEV-0002', '9c064bddefbae2bcd7a4c2e69648eac7e3e4e6dc0ccdc85db0659445a2b53134', '26/8002', 'Esquadrão Prata', 'A', 'EAGS', 'EAGS', (SELECT id FROM turmas WHERE nome = 'Ikarus'), 1),
  ('GS', 'BCT', 'CARDOSO', 'M', 'DEV-0003', '14094f69c55bef75e74f10fbc32d662afcf3f52d21babfba0d69615115519d17', '26/8003', 'Esquadrão Prata', 'A', 'EAGS', 'EAGS', (SELECT id FROM turmas WHERE nome = 'Ikarus'), 1),
  ('GS', 'SAD', 'DUARTE', 'M', 'DEV-0004', '964e95a1613ac1c0ded415ceaec20beff8747ba056dd7dba00581fc6889b5ebe', '26/8004', 'Esquadrão Prata', 'A', 'EAGS', 'EAGS', (SELECT id FROM turmas WHERE nome = 'Ikarus'), 1),
  ('GS', 'SIN', 'ESTEVES', 'F', 'DEV-0005', '62bae3d88047301a32833d36eb6bc7516f2f73862262484f735b6ed4b9a304e1', '26/8005', 'Esquadrão Prata', 'B', 'EAGS', 'EAGS', (SELECT id FROM turmas WHERE nome = 'Ikarus'), 1),
  ('GS', 'BMA', 'FARIAS', 'M', 'DEV-0006', 'f2ea227baea9af3a7a15cb50bb6edc66270a4574e828810048b1bf9601d90eaa', '26/8006', 'Esquadrão Prata', 'B', 'EAGS', 'EAGS', (SELECT id FROM turmas WHERE nome = 'Ikarus'), 1),
  ('GS', 'BCT', 'GOUVEIA', 'M', 'DEV-0007', '1f29d3e9857738f833a0ae4381df8cdcafd5baf2a3bfc6a93a6e7acc139b5ef0', '26/8007', 'Esquadrão Prata', 'B', 'EAGS', 'EAGS', (SELECT id FROM turmas WHERE nome = 'Ikarus'), 1),
  ('GS', 'SAD', 'HENRIQUES', 'F', 'DEV-0008', 'a0e655a5e9d41a3cf76747e3b74be072108d8a9cf5ed1b5d3d26f5abc9d67aa4', '26/8008', 'Esquadrão Prata', 'B', 'EAGS', 'EAGS', (SELECT id FROM turmas WHERE nome = 'Ikarus'), 1),
  ('GS', 'SIN', 'IBARRA', 'M', 'DEV-0009', 'f298914a9993996538e0f246f98c2606db268f2ee797e71e21f2f6f1f3f5556f', '26/8009', 'Esquadrão Azul', 'A', 'CFS', '4', (SELECT id FROM turmas WHERE nome = 'Fera Azul'), 1),
  ('GS', 'BMA', 'JARDIM', 'M', 'DEV-0010', '28a9b3cfb08cfef5fdb4eae617b070fd1219ca01fc9428cc7f56fdc94abe65a3', '26/8010', 'Esquadrão Azul', 'A', 'CFS', '4', (SELECT id FROM turmas WHERE nome = 'Fera Azul'), 1),
  ('GS', 'BCT', 'KLEIN', 'F', 'DEV-0011', '91d687df4c3ad5b9573ff79f4106868f8f2d08a0b015eda3da324f55a82709f5', '26/8011', 'Esquadrão Azul', 'A', 'CFS', '4', (SELECT id FROM turmas WHERE nome = 'Fera Azul'), 1),
  ('GS', 'SAD', 'LACERDA', 'M', 'DEV-0012', '6a7b41f600955f84aca31f5a6a7e4c1648d89660159dfe4dda5a0bd2574521cd', '26/8012', 'Esquadrão Azul', 'A', 'CFS', '4', (SELECT id FROM turmas WHERE nome = 'Fera Azul'), 1),
  ('GS', 'SIN', 'MACEDO', 'M', 'DEV-0013', '61622b84172589924207c7536eaf43a478802a9a6bde7e51b42f54ff19bbc38c', '26/8013', 'Esquadrão Azul', 'B', 'CFS', '4', (SELECT id FROM turmas WHERE nome = 'Fera Azul'), 1),
  ('GS', 'BMA', 'NOGUEIRA', 'F', 'DEV-0014', 'fcf7618fae82090109ebfd8d3f2e093a42914079bd8a6a7839826446b187a986', '26/8014', 'Esquadrão Azul', 'B', 'CFS', '4', (SELECT id FROM turmas WHERE nome = 'Fera Azul'), 1),
  ('GS', 'BCT', 'OLIVEIRA', 'M', 'DEV-0015', 'bbbac353eb7b54ab6828c35a03df9a6a865c386e8ab6f7fe92abef4875682c62', '26/8015', 'Esquadrão Azul', 'B', 'CFS', '4', (SELECT id FROM turmas WHERE nome = 'Fera Azul'), 1),
  ('GS', 'SAD', 'PACHECO', 'M', 'DEV-0016', '7ac422299bfa9df4e748ee710c175fc902d2bc3481fcd764276c7982d9ff6e9b', '26/8016', 'Esquadrão Azul', 'B', 'CFS', '4', (SELECT id FROM turmas WHERE nome = 'Fera Azul'), 1),
  ('GS', 'SIN', 'QUEIROZ', 'F', 'DEV-0017', 'bc7389f30051a9ef897028169c6c00dbc55315b88c9f2b531fdc23d69f32737d', '26/8017', 'Esquadrão Verde', 'A', 'CFS', '3', (SELECT id FROM turmas WHERE nome = 'Invictus'), 1),
  ('GS', 'BMA', 'RAMALHO', 'M', 'DEV-0018', '525a3b5e1accf23966eb181aae8ba9fdc4c71f9c20b99b2fcc152f541c16f9ff', '26/8018', 'Esquadrão Verde', 'A', 'CFS', '3', (SELECT id FROM turmas WHERE nome = 'Invictus'), 1),
  ('GS', 'BCT', 'SALGADO', 'M', 'DEV-0019', 'ecf7c07f793413f3557c06e8c249d2c63ecc5f1d3cf18d50649cb0275adc4891', '26/8019', 'Esquadrão Verde', 'A', 'CFS', '3', (SELECT id FROM turmas WHERE nome = 'Invictus'), 1),
  ('GS', 'SAD', 'TAVARES', 'F', 'DEV-0020', '4b6796630b19b4c4942e80651450c8b042275e0a09d02b41528fc0fa900b4fbb', '26/8020', 'Esquadrão Verde', 'A', 'CFS', '3', (SELECT id FROM turmas WHERE nome = 'Invictus'), 1),
  ('GS', 'SIN', 'UCHOA', 'M', 'DEV-0021', 'ad34875442d323fec882f7d284c16338e2572bbb5bb49344079bfca6b0bb6562', '26/8021', 'Esquadrão Verde', 'B', 'CFS', '3', (SELECT id FROM turmas WHERE nome = 'Invictus'), 1),
  ('GS', 'BMA', 'VALENTE', 'M', 'DEV-0022', '390edfe85c7fc05600437d12c358d09ea0dbde0232d6963b786d98f797b976a2', '26/8022', 'Esquadrão Verde', 'B', 'CFS', '3', (SELECT id FROM turmas WHERE nome = 'Invictus'), 1),
  ('GS', 'BCT', 'XAVIER', 'F', 'DEV-0023', '160c038eb4adeb42aba5541b8244bb6071b84448fc839fcf1ebcc746570ee894', '26/8023', 'Esquadrão Verde', 'B', 'CFS', '3', (SELECT id FROM turmas WHERE nome = 'Invictus'), 1),
  ('GS', 'SAD', 'ZANETTI', 'M', 'DEV-0024', '50322240b745c46aca82885a4580a9f7414f9f542bf5f6e1ebc30dd4ebbc6db1', '26/8024', 'Esquadrão Verde', 'B', 'CFS', '3', (SELECT id FROM turmas WHERE nome = 'Invictus'), 1),
  ('GS', 'SIN', 'WERNECK', 'M', 'DEV-0025', '67818d2d445071c9642a708fb6cd3b6c48cc57aafe8293b22c8d15983dd0febd', '26/8025', 'Esquadrão Prata', 'A', 'EAGS', 'EAGS', (SELECT id FROM turmas WHERE nome = 'Ikarus'), 0)
ON DUPLICATE KEY UPDATE qrcode_hash = VALUES(qrcode_hash), ativo = VALUES(ativo);

-- Usuários do Painel de Comando (senha de desenvolvimento em docs/dev/SEED-QRCODES.md).
INSERT INTO painel_usuarios (nome, usuario, senha_hash, cargo, esquadrao)
VALUES
  ('Comandante do CA (dev)', 'dev.ca', '$2y$10$XLF3AC8KhkJvkF./nQ6oZu3eqRXymi8mXXQuRsgWy73cNJK5ijBgG', 'CMD_CA', NULL),
  ('Comandante do Esquadrão Prata (dev)', 'dev.prata', '$2y$10$XLF3AC8KhkJvkF./nQ6oZu3eqRXymi8mXXQuRsgWy73cNJK5ijBgG', 'CMD_ESQUADRAO', 'Esquadrão Prata')
ON DUPLICATE KEY UPDATE senha_hash = VALUES(senha_hash), cargo = VALUES(cargo), esquadrao = VALUES(esquadrao), ativo = 1;
