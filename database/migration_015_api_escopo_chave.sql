SET NAMES utf8mb4;

-- Escopo das chaves de API (menor privilégio). Até aqui toda chave valia pra
-- API inteira — inclusive a do app, que é pública por natureza (vai pro
-- navegador de quem abre a PWA) e mesmo assim criava usuário do Painel.
--
--   app    só as rotas do app do aluno, e sempre junto com a sessão do aluno
--   admin  também os endpoints administrativos (alunos, grupos, usuários,
--          relatórios, situação) — chave de integração, nunca vai pro navegador
--
-- Toda chave que já existe vira "app": é o que mantém a PWA funcionando sem
-- mexer em nada e fecha o acesso administrativo. Integração que precise dos
-- endpoints administrativos gera uma chave "admin" em Ikarus37 → API.
ALTER TABLE api_chaves
  ADD COLUMN escopo ENUM('app', 'admin') NOT NULL DEFAULT 'app' AFTER chave_preview;
