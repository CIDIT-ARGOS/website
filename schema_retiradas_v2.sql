-- ================= NOVOS CARGOS DO PAINEL =================
ALTER TABLE painel_usuarios MODIFY cargo ENUM(
  'CMD_CA', 'SUBCMD_CA', 'ADMIN_TECNICO', 'AUX_CA',
  'CMD_ESQUADRAO', 'ENC_ESQUADRAO', 'AUX_ESQUADRAO'
) NOT NULL;

-- (A CHECK de esquadrão-por-cargo foi removida: o MySQL da Hostinger não criou a constraint
-- original com esse nome, então o DROP falhava e travava o resto do script. A validação
-- já é feita em PHP, em ikarus37/usuarios.php e painel/usuarios.php.)

-- ================= NOVA PERMISSÃO: FAZER A CHAMADA =================
INSERT INTO permissoes (chave, descricao) VALUES
('registrar_retirada', 'Abrir, marcar presença/falta e enviar retiradas de falta (fazer a chamada)');

INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'painel', cargo, p.id
FROM (SELECT 'CMD_CA' as cargo UNION SELECT 'SUBCMD_CA' UNION SELECT 'CMD_ESQUADRAO' UNION SELECT 'ENC_ESQUADRAO' UNION SELECT 'AUX_ESQUADRAO') t
JOIN permissoes p ON p.chave = 'registrar_retirada';

-- ADMIN_TECNICO: acesso operacional total (efetivo + retiradas), mas não mexe na cadeia de comando.
INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'painel', 'ADMIN_TECNICO', id FROM permissoes
WHERE chave IN ('ver_todos_esquadroes', 'editar_efetivo', 'registrar_retirada');

-- AUX_CA: enxerga tudo e registra retiradas (ex: chamada do CIDIT), mas não edita efetivo nem usuários.
INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'painel', 'AUX_CA', id FROM permissoes
WHERE chave IN ('ver_todos_esquadroes', 'registrar_retirada');

-- ================= GRUPOS (ex: CIDIT) =================
-- Um grupo cruza esquadrões/esquadrilhas: retirada de falta própria, fora da estrutura de esquadrilha.
CREATE TABLE grupos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(50) NOT NULL UNIQUE,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO grupos (nome) VALUES ('CIDIT');

CREATE TABLE grupo_membros (
  grupo_id INT NOT NULL,
  aluno_id INT NOT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (grupo_id, aluno_id),
  FOREIGN KEY (grupo_id) REFERENCES grupos(id),
  FOREIGN KEY (aluno_id) REFERENCES alunos(id)
);

-- ================= RETIRADAS: agrupamento por grupo + esquadrão fixo =================
-- esquadrao é obrigatório quando agrupamento_tipo = 'esquadrilha' (esquadrilha "A" existe em vários esquadrões,
-- então sozinha não identifica o efetivo). Fica NULL quando agrupamento_tipo = 'grupo' (cruza esquadrões).
ALTER TABLE retiradas MODIFY agrupamento_tipo ENUM('esquadrilha', 'especialidade', 'grupo') NOT NULL;
ALTER TABLE retiradas ADD COLUMN esquadrao VARCHAR(50) NULL AFTER agrupamento_valor;
