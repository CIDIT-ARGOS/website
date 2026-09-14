SET NAMES utf8mb4;
-- Sem isso, um SQL console/mysql CLI que não abre em utf8mb4 por padrão
-- corrompe os acentos deste arquivo ao inserir (double-encoding).

-- Fase B: classificação real dos motivos de falta (pra distinguir falta de
-- verdade de ausência justificada, que a Diretoria de Ensino não computa
-- como aula perdida) + Dispensas Médicas como entidade própria + as
-- permissões novas que essas duas telas exigem.

-- ================= MOTIVOS DE FALTA: classificação =================
ALTER TABLE motivos_falta
  ADD COLUMN codigo VARCHAR(10) NULL AFTER nome,
  ADD COLUMN classificacao ENUM('presente', 'ausente_nao_falta', 'falta') NOT NULL DEFAULT 'ausente_nao_falta' AFTER codigo;

-- Os 10 motivos antigos são desativados, não apagados — retirada_itens já
-- enviados referenciam esses ids, e apagar quebraria o histórico.
UPDATE motivos_falta SET ativo = 0
WHERE nome IN ('Serviço', 'Posto Médico', 'Hospital', 'Comissão', 'Dispensado', 'LNC', 'Detenção', 'Prisão', 'Outro', 'Sem Justificativa');

-- Legenda oficial dos ~30 códigos (substituindo os antigos como única opção
-- em novas chamadas, por decisão do usuário). Pra códigos que não dá pra
-- inferir com segurança o nome por extenso, o `nome` fica igual ao próprio
-- `codigo` como placeholder — revise pela tela de Domínio de Negócio
-- (Motivos de falta) e ajuste o que precisar; não muda nada no funcionamento.
--
-- ON DUPLICATE KEY UPDATE (não INSERT IGNORE) porque 3 desses nomes
-- (Comissão, Hospital, Serviço) colidem com o nome de motivos antigos que
-- acabaram de ser desativados acima — sem isso, a linha nova seria
-- silenciosamente ignorada por causa da UNIQUE KEY em `nome`, e o código
-- (CMSS/HOSP/SV) nunca entraria. Aqui a linha antiga é reaproveitada
-- (reativada + com o código novo), o que também preserva o id — melhor
-- ainda, porque `retirada_itens` antigos que já apontavam pra ela continuam
-- apontando pro mesmo motivo, sem precisar de nenhum remapeamento.
INSERT INTO motivos_falta (nome, codigo, classificacao, requer_observacao, ordem) VALUES
('PRE', 'PRE', 'presente', 0, 1),
('ADPT', 'ADPT', 'ausente_nao_falta', 0, 2),
('Atleta', 'ATL', 'ausente_nao_falta', 0, 3),
('AUX', 'AUX', 'ausente_nao_falta', 0, 4),
('BXD', 'BXD', 'ausente_nao_falta', 0, 5),
('Comissão', 'CMSS', 'ausente_nao_falta', 1, 6),
('CNTR', 'CNTR', 'ausente_nao_falta', 0, 7),
('COMP', 'COMP', 'ausente_nao_falta', 0, 8),
('CPU', 'CPU', 'ausente_nao_falta', 0, 9),
('DEF', 'DEF', 'ausente_nao_falta', 0, 10),
('Deslocamento', 'DESL', 'ausente_nao_falta', 0, 11),
('Dispensa Médica', 'DMED', 'ausente_nao_falta', 0, 12),
('Estudo', 'ESTD', 'ausente_nao_falta', 0, 13),
('Estágio', 'ESTG', 'ausente_nao_falta', 0, 14),
('Evento', 'EVNT', 'ausente_nao_falta', 1, 15),
('Falta sem Justificativa', 'FALT', 'falta', 0, 16),
('Guia', 'GUIA', 'ausente_nao_falta', 1, 17),
('Hospital', 'HOSP', 'ausente_nao_falta', 0, 18),
('Inspeção', 'INSP', 'ausente_nao_falta', 0, 19),
('Instrução', 'INST', 'ausente_nao_falta', 0, 20),
('JES', 'JES', 'ausente_nao_falta', 0, 21),
('LOC', 'LOC', 'ausente_nao_falta', 0, 22),
('Manutenção', 'MNT', 'ausente_nao_falta', 0, 23),
('Odontológico', 'ODO', 'ausente_nao_falta', 0, 24),
('PDSL', 'PDSL', 'ausente_nao_falta', 0, 25),
('Posto Médico do CA', 'PMCA', 'ausente_nao_falta', 0, 26),
('Sargenteação', 'SARG', 'ausente_nao_falta', 0, 27),
('SATC', 'SATC', 'ausente_nao_falta', 0, 28),
('Ação Social', 'SOCI', 'ausente_nao_falta', 0, 29),
('Serviço', 'SV', 'ausente_nao_falta', 0, 30),
('TRBD', 'TRBD', 'ausente_nao_falta', 0, 31)
ON DUPLICATE KEY UPDATE codigo = VALUES(codigo), classificacao = VALUES(classificacao),
  requer_observacao = VALUES(requer_observacao), ordem = VALUES(ordem), ativo = 1;

-- ================= DISPENSAS MÉDICAS =================
CREATE TABLE dispensas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  aluno_id INT NOT NULL,
  data_inicio DATE NOT NULL,
  data_termino DATE NOT NULL,
  numero VARCHAR(20) NULL,
  motivo VARCHAR(255) NOT NULL,
  dispensado_de VARCHAR(255) NULL,
  painel_usuario_id INT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (aluno_id) REFERENCES alunos(id),
  FOREIGN KEY (painel_usuario_id) REFERENCES painel_usuarios(id),
  INDEX idx_aluno_periodo (aluno_id, data_inicio, data_termino)
);

-- ================= Novas permissões =================
INSERT INTO permissoes (chave, descricao) VALUES
('gerenciar_motivos', 'Criar, editar e desativar motivos de falta e sua classificação (Controle do Domínio de Negócio)'),
('gerenciar_dispensas', 'Cadastrar e gerenciar dispensas médicas do efetivo');

INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'ikarus37', 'super_admin', id FROM permissoes WHERE chave IN ('gerenciar_motivos', 'gerenciar_dispensas');

INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'painel', cargo, p.id
FROM (SELECT 'CMD_CA' AS cargo UNION SELECT 'SUBCMD_CA') c
CROSS JOIN permissoes p
WHERE p.chave = 'gerenciar_motivos';

-- gerenciar_dispensas: mesmos cargos de editar_efetivo (dado de efetivo do
-- dia a dia do esquadrão, não governança de CA).
INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'painel', cargo, p.id
FROM (SELECT 'CMD_CA' AS cargo UNION SELECT 'SUBCMD_CA' UNION SELECT 'ADMIN_TECNICO' UNION SELECT 'CMD_ESQUADRAO' UNION SELECT 'ENC_ESQUADRAO') c
CROSS JOIN permissoes p
WHERE p.chave = 'gerenciar_dispensas';
