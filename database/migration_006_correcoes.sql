SET NAMES utf8mb4;
-- Sem isso, um SQL console/mysql CLI que não abre em utf8mb4 por padrão
-- corrompe os acentos deste arquivo ao inserir (double-encoding).

-- Correção da legenda de motivos de falta (a semente da migração 005 tinha
-- vários códigos com nome só "chutado" como placeholder — o usuário mandou o
-- significado oficial completo, esta migração aplica a versão certa) +
-- ajustes de UX pedidos: nome do motivo passa a caber uma descrição real
-- (alguns significados oficiais passam de 50 caracteres).
ALTER TABLE motivos_falta MODIFY nome VARCHAR(150) NOT NULL;

UPDATE motivos_falta SET nome = 'Presente' WHERE codigo = 'PRE';
UPDATE motivos_falta SET nome = 'Adaptação' WHERE codigo = 'ADPT';
UPDATE motivos_falta SET nome = 'Atleta' WHERE codigo = 'ATL';
UPDATE motivos_falta SET nome = 'Auxiliar' WHERE codigo = 'AUX';
UPDATE motivos_falta SET nome = 'Baixado (internado)' WHERE codigo = 'BXD';
UPDATE motivos_falta SET nome = 'Comissão de Formatura' WHERE codigo = 'CMSS';
UPDATE motivos_falta SET nome = 'Centros e clube' WHERE codigo = 'CNTR';
UPDATE motivos_falta SET nome = 'Competição esportiva ou treinamento supervisionado' WHERE codigo = 'COMP';
UPDATE motivos_falta SET nome = 'Cumprindo Punição' WHERE codigo = 'CPU';
UPDATE motivos_falta SET nome = 'Atividade extra na Divisão de Ensino de Formação' WHERE codigo = 'DEF';
UPDATE motivos_falta SET nome = 'Desligado' WHERE codigo = 'DESL';
UPDATE motivos_falta SET nome = 'Dispensa médica (atrás da tropa)' WHERE codigo = 'DMED';
UPDATE motivos_falta SET nome = 'Estudo obrigatório' WHERE codigo = 'ESTD';
UPDATE motivos_falta SET nome = 'Estágio' WHERE codigo = 'ESTG';
UPDATE motivos_falta SET nome = 'Eventos diversos, quando autorizados' WHERE codigo = 'EVNT';
UPDATE motivos_falta SET nome = 'Falta' WHERE codigo = 'FALT';
UPDATE motivos_falta SET nome = 'Guia extraordinária' WHERE codigo = 'GUIA';
UPDATE motivos_falta SET nome = 'Área do hospital (consulta ou atendimento ambulatorial)' WHERE codigo = 'HOSP';
UPDATE motivos_falta SET nome = 'Inspeção de Saúde' WHERE codigo = 'INSP';
UPDATE motivos_falta SET nome = 'Instruções diversas, desde que sob supervisão de instrutor' WHERE codigo = 'INST';
UPDATE motivos_falta SET nome = 'Junta Especial de Saúde (junta médica)' WHERE codigo = 'JES';
UPDATE motivos_falta SET nome = 'Locução' WHERE codigo = 'LOC';
UPDATE motivos_falta SET nome = 'Equipe de manutenção' WHERE codigo = 'MNT';
UPDATE motivos_falta SET nome = 'Odontoclínica' WHERE codigo = 'ODO';
UPDATE motivos_falta SET nome = 'Processo de desligamento' WHERE codigo = 'PDSL';
UPDATE motivos_falta SET nome = 'Posto Médico do CA' WHERE codigo = 'PMCA';
UPDATE motivos_falta SET nome = 'Sargenteação' WHERE codigo = 'SARG';
UPDATE motivos_falta SET nome = 'Serviço de Atendimento Clínico (fisioterapia, fonoaudiologia, nutricionista, otorrino e psicologia)' WHERE codigo = 'SATC';
UPDATE motivos_falta SET nome = 'Sociedade' WHERE codigo = 'SOCI';
UPDATE motivos_falta SET nome = 'Serviço' WHERE codigo = 'SV';
UPDATE motivos_falta SET nome = 'Treinamento bandeiras históricas e Guarda Bandeira' WHERE codigo = 'TRBD';
