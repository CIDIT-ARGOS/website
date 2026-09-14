-- Corrige texto com encoding UTF-8 duplicado (ex: "ServiÃ§o" em vez de "Serviço").
--
-- Causa: em algum momento esses valores foram inseridos via uma ferramenta
-- (provavelmente o console SQL do phpMyAdmin/Hostinger) cuja sessão não
-- estava configurada como utf8mb4. O MySQL recebeu os bytes UTF-8 corretos,
-- mas leu cada um como se fosse Latin-1 e regravou como utf8mb4 — dobrando
-- a codificação. As tabelas e colunas em si SEMPRE estiveram corretas
-- (utf8mb4_unicode_ci); o problema é só nesses valores específicos.
--
-- Este script não depende do charset da sessão que o executa — não há
-- nenhum acento digitado na query, só transformação de coluna pra coluna —
-- então pode rodar direto no console SQL do Hostinger sem se preocupar em
-- configurar utf8mb4 na sessão antes.
--
-- Verificado (Docker, cópia real dos dados de produção de 2026-09-12):
-- exatamente 11 linhas afetadas em 3 tabelas, a fórmula abaixo reconstruiu
-- o texto original corretamente em 100% dos casos (nenhum falso positivo).
-- Idempotente: rodar de novo não faz nada, porque o WHERE só pega o que
-- ainda estiver com a marca de encoding duplicado.

UPDATE alunos
SET nome_guerra = CONVERT(CAST(CONVERT(nome_guerra USING latin1) AS BINARY) USING utf8mb4)
WHERE HEX(nome_guerra) LIKE '%C383%';

UPDATE motivos_falta
SET nome = CONVERT(CAST(CONVERT(nome USING latin1) AS BINARY) USING utf8mb4)
WHERE HEX(nome) LIKE '%C383%';

UPDATE permissoes
SET descricao = CONVERT(CAST(CONVERT(descricao USING latin1) AS BINARY) USING utf8mb4)
WHERE HEX(descricao) LIKE '%C383%';

-- Conferência pós-fix: essa consulta deve retornar 0 linhas em todas as tabelas.
-- SELECT COUNT(*) FROM alunos WHERE HEX(nome_guerra) LIKE '%C383%';
-- SELECT COUNT(*) FROM motivos_falta WHERE HEX(nome) LIKE '%C383%';
-- SELECT COUNT(*) FROM permissoes WHERE HEX(descricao) LIKE '%C383%';
