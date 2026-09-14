-- Sem isso, um SQL console/mysql CLI que não abre em utf8mb4 por padrão
-- corrompe todo acento inserido aqui (double-encoding — o mesmo bug que já
-- apareceu em produção). SET NAMES manda o SERVIDOR interpretar os bytes que
-- vêm a seguir como utf8mb4, independente da configuração do cliente.
SET NAMES utf8mb4;

-- =====================================================================
-- ARGOS — Script de inicialização completo do banco de dados
-- Consolida schema_alunos.sql + schema_painel.sql + schema_permissoes.sql
--   + schema_retiradas_v2.sql num único arquivo, já com as correções
--   (ENC_ESQUADRAO em vez de ENG_ESQUADRAO, sem a CHECK problemática).
--
-- ATENÇÃO: este script APAGA e recria as tabelas listadas abaixo.
-- Faça backup completo do banco antes de rodar (hPanel/phpMyAdmin → Exportar).
-- Depois de rodar, será necessário:
--   1. Recriar as contas em admin_usuarios e painel_usuarios (gerar_hash.php)
--   2. Reimportar o efetivo (CSV de alunos) via ikarus37/importar.php
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS api_logs;
DROP TABLE IF EXISTS api_chaves;
DROP TABLE IF EXISTS grupo_membros;
DROP TABLE IF EXISTS grupos;
DROP TABLE IF EXISTS cargo_permissoes;
DROP TABLE IF EXISTS permissoes;
DROP TABLE IF EXISTS retirada_itens;
DROP TABLE IF EXISTS retiradas;
DROP TABLE IF EXISTS motivos_falta;
DROP TABLE IF EXISTS alunos;
DROP TABLE IF EXISTS postos_graduacao;
DROP TABLE IF EXISTS painel_usuarios;
DROP TABLE IF EXISTS admin_usuarios;

SET FOREIGN_KEY_CHECKS = 1;

-- ================= ADMIN_USUARIOS (área técnica /ikarus37) =================
CREATE TABLE admin_usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(100) NOT NULL,
  usuario VARCHAR(50) NOT NULL UNIQUE,
  senha_hash VARCHAR(255) NOT NULL,
  nivel VARCHAR(30) NOT NULL DEFAULT 'admin',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_login DATETIME NULL
);

-- Admin básico pra conseguir entrar no /ikarus37 assim que o init terminar.
-- Usuário: admin | Senha: Argos@2026
-- TROQUE ESSA SENHA (Usuários administradores → redefinir senha) assim que logar pela primeira vez.
INSERT INTO admin_usuarios (nome, usuario, senha_hash, nivel) VALUES
('Administrador', 'admin', '$2b$10$s8xFb37RiTLh..3YbOEzpuDXgvOwMvF6PAbfl5fNVoizEp/3wq5Sa', 'super_admin');

-- ================= PAINEL_USUARIOS (cadeia de comando /painel) =================
CREATE TABLE painel_usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(100) NOT NULL,
  usuario VARCHAR(50) NOT NULL UNIQUE,
  senha_hash VARCHAR(255) NOT NULL,
  cargo VARCHAR(30) NOT NULL,
  esquadrao VARCHAR(50) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_login DATETIME NULL
);
-- Regra "esquadrao obrigatório só para cargos de esquadrão" é validada em PHP
-- (ikarus37/usuarios.php e painel/usuarios.php), não via CHECK — evita
-- incompatibilidade com versões do MySQL que não suportam CHECK de verdade.

-- ================= POSTOS / GRADUAÇÕES =================
CREATE TABLE postos_graduacao (
  codigo VARCHAR(10) PRIMARY KEY,
  exibicao VARCHAR(30) NOT NULL
);

INSERT INTO postos_graduacao (codigo, exibicao) VALUES
('GS', 'AL');

-- ================= ALUNOS =================
CREATE TABLE alunos (
  id INT AUTO_INCREMENT PRIMARY KEY,

  posto_graduacao VARCHAR(10) NOT NULL,
  quadro VARCHAR(50) NULL,
  especialidade VARCHAR(50) NULL,
  sub_especialidade VARCHAR(50) NULL,
  nome_guerra VARCHAR(50) NOT NULL,
  sexo ENUM('M', 'F') NOT NULL,
  identidade_militar VARCHAR(30) NOT NULL UNIQUE,
  organizacao_militar VARCHAR(100) NULL,
  setor VARCHAR(50) NULL,
  secao VARCHAR(50) NULL,
  ramal VARCHAR(10) NULL,

  qrcode_hash CHAR(64) NULL UNIQUE,

  milhao VARCHAR(20) NOT NULL UNIQUE,
  esquadrao VARCHAR(50) NOT NULL,
  esquadrilha VARCHAR(50) NOT NULL,
  curso ENUM('CFS', 'EAGS') NOT NULL,
  serie VARCHAR(10) NOT NULL,

  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_esquadrilha (esquadrilha),
  INDEX idx_especialidade (especialidade),
  INDEX idx_esquadrao (esquadrao),
  INDEX idx_qrcode_hash (qrcode_hash),

  FOREIGN KEY (posto_graduacao) REFERENCES postos_graduacao(codigo)
);

-- ================= MOTIVOS DE FALTA =================
-- classificacao distingue falta de verdade (conta como aula perdida pra
-- Diretoria de Ensino) de ausência justificada (não conta) — ver Controle do
-- Domínio de Negócio > Motivos de falta.
CREATE TABLE motivos_falta (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(150) NOT NULL UNIQUE,
  codigo VARCHAR(10) NULL,
  classificacao ENUM('presente', 'ausente_nao_falta', 'falta') NOT NULL DEFAULT 'ausente_nao_falta',
  requer_observacao TINYINT(1) NOT NULL DEFAULT 0,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  ordem INT NOT NULL DEFAULT 0
);

-- Legenda oficial. Pra códigos que não dá pra inferir com segurança o nome
-- por extenso, `nome` fica igual ao próprio `codigo` como placeholder —
-- revise pela tela de Domínio de Negócio (Motivos de falta).
INSERT INTO motivos_falta (nome, codigo, classificacao, requer_observacao, ordem) VALUES
('Presente', 'PRE', 'presente', 0, 1),
('Adaptação', 'ADPT', 'ausente_nao_falta', 0, 2),
('Atleta', 'ATL', 'ausente_nao_falta', 0, 3),
('Auxiliar', 'AUX', 'ausente_nao_falta', 0, 4),
('Baixado (internado)', 'BXD', 'ausente_nao_falta', 0, 5),
('Comissão de Formatura', 'CMSS', 'ausente_nao_falta', 1, 6),
('Centros e clube', 'CNTR', 'ausente_nao_falta', 0, 7),
('Competição esportiva ou treinamento supervisionado', 'COMP', 'ausente_nao_falta', 0, 8),
('Cumprindo Punição', 'CPU', 'ausente_nao_falta', 0, 9),
('Atividade extra na Divisão de Ensino de Formação', 'DEF', 'ausente_nao_falta', 0, 10),
('Desligado', 'DESL', 'ausente_nao_falta', 0, 11),
('Dispensa médica (atrás da tropa)', 'DMED', 'ausente_nao_falta', 0, 12),
('Estudo obrigatório', 'ESTD', 'ausente_nao_falta', 0, 13),
('Estágio', 'ESTG', 'ausente_nao_falta', 0, 14),
('Eventos diversos, quando autorizados', 'EVNT', 'ausente_nao_falta', 1, 15),
('Falta', 'FALT', 'falta', 0, 16),
('Guia extraordinária', 'GUIA', 'ausente_nao_falta', 1, 17),
('Área do hospital (consulta ou atendimento ambulatorial)', 'HOSP', 'ausente_nao_falta', 0, 18),
('Inspeção de Saúde', 'INSP', 'ausente_nao_falta', 0, 19),
('Instruções diversas, desde que sob supervisão de instrutor', 'INST', 'ausente_nao_falta', 0, 20),
('Junta Especial de Saúde (junta médica)', 'JES', 'ausente_nao_falta', 0, 21),
('Locução', 'LOC', 'ausente_nao_falta', 0, 22),
('Equipe de manutenção', 'MNT', 'ausente_nao_falta', 0, 23),
('Odontoclínica', 'ODO', 'ausente_nao_falta', 0, 24),
('Processo de desligamento', 'PDSL', 'ausente_nao_falta', 0, 25),
('Posto Médico do CA', 'PMCA', 'ausente_nao_falta', 0, 26),
('Sargenteação', 'SARG', 'ausente_nao_falta', 0, 27),
('Serviço de Atendimento Clínico (fisioterapia, fonoaudiologia, nutricionista, otorrino e psicologia)', 'SATC', 'ausente_nao_falta', 0, 28),
('Sociedade', 'SOCI', 'ausente_nao_falta', 0, 29),
('Serviço', 'SV', 'ausente_nao_falta', 0, 30),
('Treinamento bandeiras históricas e Guarda Bandeira', 'TRBD', 'ausente_nao_falta', 0, 31);

-- ================= GRUPOS (ex: CIDIT) =================
-- categoria classifica o grupo: 'clube' (CIDIT, UNAEV...), 'servico', 'comissao'.
-- "Rotina" (Jornadas/EF/Pernoite) NÃO é categoria de grupo — é o campo `tipo` de `retiradas`,
-- porque não é um conjunto de alunos, é um tipo de formatura.
CREATE TABLE grupos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(50) NOT NULL UNIQUE,
  categoria ENUM('clube', 'servico', 'comissao') NOT NULL DEFAULT 'clube',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO grupos (nome, categoria) VALUES ('CIDIT', 'clube');

-- Todo grupo também vira uma opção de motivo de falta (ex: marcar "CIDIT" na chamada
-- normal do pernoite, sem precisar de uma retirada de grupo pra isso).
INSERT INTO motivos_falta (nome, requer_observacao, ordem)
SELECT g.nome, 0, (SELECT COALESCE(MAX(ordem), 0) FROM motivos_falta) + 1
FROM grupos g
WHERE NOT EXISTS (SELECT 1 FROM motivos_falta m WHERE m.nome = g.nome);

CREATE TABLE grupo_membros (
  grupo_id INT NOT NULL,
  aluno_id INT NOT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (grupo_id, aluno_id),
  FOREIGN KEY (grupo_id) REFERENCES grupos(id),
  FOREIGN KEY (aluno_id) REFERENCES alunos(id)
);

-- ================= RETIRADAS (chamadas) =================
CREATE TABLE retiradas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo ENUM('1_jornada', '2_jornada', 'educacao_fisica', 'pernoite') NOT NULL,
  agrupamento_tipo ENUM('esquadrilha', 'especialidade', 'grupo') NOT NULL,
  agrupamento_valor VARCHAR(50) NOT NULL,
  esquadrao VARCHAR(50) NULL, -- obrigatório quando agrupamento_tipo = 'esquadrilha'; NULL quando 'grupo'
  aluno_servico_id INT NULL, -- legado; quem abre a retirada agora é sempre a sessão logada (ver abaixo)
  painel_usuario_id INT NULL, -- quem abriu, quando logado como conta normal do painel
  responsavel_nome VARCHAR(100) NULL, -- nome gravado no momento da abertura (cobre também login via ponte do Ikarus37)
  status ENUM('pendente', 'enviada') NOT NULL DEFAULT 'pendente',
  protocolo VARCHAR(40) NULL UNIQUE,
  data_hora DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  enviada_em DATETIME NULL,

  FOREIGN KEY (aluno_servico_id) REFERENCES alunos(id),
  FOREIGN KEY (painel_usuario_id) REFERENCES painel_usuarios(id)
);

CREATE TABLE retirada_itens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  retirada_id INT NOT NULL,
  aluno_id INT NOT NULL,
  presente TINYINT(1) NOT NULL DEFAULT 1,
  motivo_falta_id INT NULL,
  observacao TEXT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uk_retirada_aluno (retirada_id, aluno_id),
  FOREIGN KEY (retirada_id) REFERENCES retiradas(id),
  FOREIGN KEY (aluno_id) REFERENCES alunos(id),
  FOREIGN KEY (motivo_falta_id) REFERENCES motivos_falta(id)
);

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

-- ================= PERMISSÕES =================
CREATE TABLE permissoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  chave VARCHAR(50) NOT NULL UNIQUE,
  descricao VARCHAR(150) NOT NULL
);

INSERT INTO permissoes (chave, descricao) VALUES
('gerenciar_usuarios_admin', 'Criar, editar e excluir usuários administradores técnicos (admin_usuarios)'),
('gerenciar_usuarios_painel', 'Criar, editar e excluir usuários do painel de comando (painel_usuarios)'),
('ver_todos_esquadroes', 'Visualizar efetivo e relatórios de todos os esquadrões, não só o próprio'),
('editar_efetivo', 'Editar dados de alunos no painel'),
('registrar_retirada', 'Abrir, marcar presença/falta e enviar retiradas de falta (fazer a chamada)'),
('gerenciar_api', 'Criar, revogar e monitorar chaves de API (apps conectados)');

CREATE TABLE cargo_permissoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sistema ENUM('ikarus37', 'painel') NOT NULL,
  cargo VARCHAR(30) NOT NULL,
  permissao_id INT NOT NULL,

  UNIQUE KEY uk_sistema_cargo_permissao (sistema, cargo, permissao_id),
  FOREIGN KEY (permissao_id) REFERENCES permissoes(id)
);

-- ---------- Ikarus37 ----------
INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'ikarus37', 'super_admin', id FROM permissoes
WHERE chave IN ('gerenciar_usuarios_admin', 'gerenciar_usuarios_painel', 'gerenciar_api');
-- admin, suporte: nenhuma permissão de gestão de usuários/API por padrão (só visualizam).

-- ---------- Painel ----------
INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'painel', 'CMD_CA', id FROM permissoes
WHERE chave IN ('gerenciar_usuarios_painel', 'ver_todos_esquadroes', 'editar_efetivo', 'registrar_retirada');

INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'painel', 'SUBCMD_CA', id FROM permissoes
WHERE chave IN ('gerenciar_usuarios_painel', 'ver_todos_esquadroes', 'editar_efetivo', 'registrar_retirada');

INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'painel', 'ADMIN_TECNICO', id FROM permissoes
WHERE chave IN ('ver_todos_esquadroes', 'editar_efetivo', 'registrar_retirada');

INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'painel', 'AUX_CA', id FROM permissoes
WHERE chave IN ('ver_todos_esquadroes', 'registrar_retirada');

INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'painel', 'CMD_ESQUADRAO', id FROM permissoes
WHERE chave IN ('editar_efetivo', 'registrar_retirada');

INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'painel', 'ENC_ESQUADRAO', id FROM permissoes
WHERE chave IN ('editar_efetivo', 'registrar_retirada');

INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'painel', 'AUX_ESQUADRAO', id FROM permissoes
WHERE chave IN ('registrar_retirada');
-- AUX_ESQUADRAO: só registrar_retirada (faz a chamada), não edita efetivo.

-- ================= CARGOS (catálogo, editável no Controle do Domínio) =================
-- painel_usuarios.cargo e admin_usuarios.nivel guardam a `chave` daqui como
-- texto solto (sem FK) — criar um cargo novo não exige deploy, só cadastro
-- aqui + conceder permissões em cargo_permissoes.
CREATE TABLE cargos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sistema ENUM('painel', 'ikarus37') NOT NULL,
  chave VARCHAR(30) NOT NULL,
  nome VARCHAR(100) NOT NULL,
  escopo ENUM('ca', 'esquadrao') NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uk_sistema_chave (sistema, chave)
);

INSERT INTO cargos (sistema, chave, nome, escopo) VALUES
('painel', 'CMD_CA', 'Comandante do CA', 'ca'),
('painel', 'SUBCMD_CA', 'Subcomandante do CA', 'ca'),
('painel', 'ADMIN_TECNICO', 'Administrador Técnico', 'ca'),
('painel', 'AUX_CA', 'Auxiliar CA', 'ca'),
('painel', 'CMD_ESQUADRAO', 'Comandante de Esquadrão', 'esquadrao'),
('painel', 'ENC_ESQUADRAO', 'Encarregado de Esquadrão', 'esquadrao'),
('painel', 'AUX_ESQUADRAO', 'Auxiliar de Esquadrão', 'esquadrao'),
('ikarus37', 'super_admin', 'Super administrador', NULL),
('ikarus37', 'admin', 'Administrador', NULL),
('ikarus37', 'suporte', 'Suporte', NULL);

INSERT INTO permissoes (chave, descricao) VALUES
('gerenciar_cargos', 'Criar, editar e desativar cargos (Controle do Domínio de Negócio)'),
('acesso_tecnico_avancado', 'Console SQL, backup, exportar/importar tabela e editor de registro cru — ferramentas de emergência, não o uso do dia a dia');

INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'ikarus37', 'super_admin', id FROM permissoes WHERE chave IN ('gerenciar_cargos', 'acesso_tecnico_avancado');

INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'painel', cargo, p.id
FROM (SELECT 'CMD_CA' AS cargo UNION SELECT 'SUBCMD_CA') c
CROSS JOIN permissoes p
WHERE p.chave = 'gerenciar_cargos';

-- ================= Motivos de falta e dispensas =================
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

-- ================= UNIDADES (árvore organizacional) =================
-- EEAR { DEF { Galpões }, CA { Doutrina, Esquadrões } }. Junto com grupos_acesso
-- abaixo, é uma SEGUNDA fonte de permissão (somada por OR à cargo_permissoes
-- acima) — cargo continua valendo do jeito que já vale; isso serve pra dar
-- acesso a unidades que não têm nenhum conceito de cargo (DEF, Galpões, Doutrina).
CREATE TABLE unidades (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(100) NOT NULL,
  tipo ENUM('escola', 'divisao', 'galpao', 'ca', 'esquadrao', 'doutrina') NOT NULL,
  unidade_pai_id INT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (unidade_pai_id) REFERENCES unidades(id)
);

-- ================= GRUPOS DE ACESSO (estilo POSIX) =================
-- Não confundir com a tabela `grupos` acima (agrupamentos de retirada tipo CIDIT).
CREATE TABLE grupos_acesso (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(100) NOT NULL UNIQUE,
  descricao VARCHAR(255) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE grupo_acesso_membros (
  id INT AUTO_INCREMENT PRIMARY KEY,
  grupo_acesso_id INT NOT NULL,
  painel_usuario_id INT NOT NULL,
  unidade_id INT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uk_membro (grupo_acesso_id, painel_usuario_id, unidade_id),
  FOREIGN KEY (grupo_acesso_id) REFERENCES grupos_acesso(id),
  FOREIGN KEY (painel_usuario_id) REFERENCES painel_usuarios(id),
  FOREIGN KEY (unidade_id) REFERENCES unidades(id)
);

CREATE TABLE grupo_acesso_permissoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  grupo_acesso_id INT NOT NULL,
  permissao_id INT NOT NULL,
  unidade_id INT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uk_permissao (grupo_acesso_id, permissao_id, unidade_id),
  FOREIGN KEY (grupo_acesso_id) REFERENCES grupos_acesso(id),
  FOREIGN KEY (permissao_id) REFERENCES permissoes(id),
  FOREIGN KEY (unidade_id) REFERENCES unidades(id)
);

INSERT INTO unidades (nome, tipo, unidade_pai_id) VALUES ('EEAR', 'escola', NULL);
SET @eear = LAST_INSERT_ID();

INSERT INTO unidades (nome, tipo, unidade_pai_id) VALUES
  ('DEF', 'divisao', @eear),
  ('CA', 'ca', @eear);
SET @ca = (SELECT id FROM unidades WHERE nome = 'CA' AND unidade_pai_id = @eear);

INSERT INTO unidades (nome, tipo, unidade_pai_id) VALUES ('Doutrina', 'doutrina', @ca);

-- Esquadrões existentes (se o efetivo já tiver sido semeado) entram como unidade
-- desde já; numa instalação do zero (alunos ainda vazia) essa consulta não acha
-- nada, e os esquadrões/galpões são cadastrados pelo Controle do Domínio de Negócio.
INSERT INTO unidades (nome, tipo, unidade_pai_id)
SELECT DISTINCT esquadrao, 'esquadrao', @ca FROM alunos WHERE esquadrao IS NOT NULL;

INSERT INTO permissoes (chave, descricao) VALUES
  ('gerenciar_dominio', 'Acessar o Controle do Domínio de Negócio (CRUD dos objetos do sistema)'),
  ('gerenciar_unidades', 'Criar, editar e excluir unidades organizacionais (EEAR, DEF, Galpões, CA, Esquadrões, Doutrina)'),
  ('gerenciar_grupos_acesso', 'Criar grupos de acesso, gerenciar membros e conceder/revogar permissões');

INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'ikarus37', 'super_admin', id FROM permissoes
WHERE chave IN ('gerenciar_dominio', 'gerenciar_unidades', 'gerenciar_grupos_acesso');

INSERT INTO cargo_permissoes (sistema, cargo, permissao_id)
SELECT 'painel', cargo, p.id
FROM (SELECT 'CMD_CA' AS cargo UNION SELECT 'SUBCMD_CA') c
CROSS JOIN permissoes p
WHERE p.chave IN ('gerenciar_dominio', 'gerenciar_unidades', 'gerenciar_grupos_acesso');

-- ================= CHAVES DE API (Apps conectados) =================
CREATE TABLE api_chaves (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(100) NOT NULL,
  chave_hash CHAR(64) NOT NULL UNIQUE,
  chave_preview VARCHAR(8) NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_por VARCHAR(50) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_uso DATETIME NULL
);

-- Migra a chave fixa que já estava em uso (config.php) pra não quebrar quem já testou a API.
-- Chave original: d0cf67c0a3b8464aacb2ed36f01b1fbb2af8d7623d804c3aa5eef732c6b3f058
INSERT INTO api_chaves (nome, chave_hash, chave_preview, criado_por) VALUES
('Chave inicial (migrada do config.php)', '98d41c1a6c7e80c8b4addcde33b4425c5180b590b34f388e81cfd494bb0638ef', '...f058', 'sistema');

-- ================= LOG DE REQUISIÇÕES DA API =================
CREATE TABLE api_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  api_chave_id INT NULL,
  endpoint VARCHAR(100) NOT NULL,
  metodo VARCHAR(10) NOT NULL,
  status_code INT NOT NULL,
  ip VARCHAR(45) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_criado_em (criado_em),
  FOREIGN KEY (api_chave_id) REFERENCES api_chaves(id)
);
