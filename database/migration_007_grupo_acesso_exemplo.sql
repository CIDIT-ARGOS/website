SET NAMES utf8mb4;
-- Sem isso, um SQL console/mysql CLI que não abre em utf8mb4 por padrão
-- corrompe os acentos deste arquivo ao inserir (double-encoding).

-- Grupos de Acesso existia desde a Fase A mas ficava vazio na prática — o
-- usuário achou confuso sem um exemplo real. Este é o caso de uso que
-- justifica a existência do recurso: DEF não tem NENHUM cargo no sistema
-- (só painel_usuarios.cargo, que é CA/Esquadrão), então não tem como dar
-- acesso a alguém de lá sem ou (a) inventar um cargo artificial tipo
-- "CMD_DEF" só pra essa pessoa, ou (b) usar um grupo de acesso restrito à
-- unidade DEF — que é exatamente pra isso que o recurso existe.
--
-- Semente: um grupo "Comandante do DEF" com permissão de gerenciar as
-- unidades organizacionais (Galpões) e o Controle do Domínio de Negócio, sem
-- precisar virar CMD_CA/SUBCMD_CA pra isso. Sem membro nenhum ainda (fica pro
-- usuário adicionar a pessoa certa quando o DEF tiver um comandante
-- identificado no sistema) — serve de modelo pronto pra replicar o mesmo
-- padrão quando Galpões e Doutrina tiverem gente designada.
--
-- IMPORTANTE — limitação encontrada ao montar este exemplo: a restrição por
-- unidade em grupo_acesso_permissoes.unidade_id (ex: "só vale pra DEF") está
-- pronta no banco e no core (_temPermissaoViaGrupoAcesso), mas NENHUM lugar
-- do app hoje chama temPermissao() passando um $unidadeId de verdade — toda
-- chamada existente usa o padrão (null), que só bate com concessões também
-- sem unidade. Ou seja: uma concessão restrita a uma unidade específica não
-- tem efeito nenhum ainda, porque não existe tela que pergunte "essa ação é
-- sobre qual unidade?" antes de checar a permissão. Por isso a concessão
-- abaixo é SEM restrição de unidade (unidade_id NULL) — funciona de verdade
-- hoje. Amarrar isso de ponta a ponta (cada ação no Domínio de Negócio
-- checar a unidade do registro sendo editado) é trabalho pra uma rodada
-- futura, se/quando isso importar na prática.

INSERT INTO grupos_acesso (nome, descricao)
SELECT 'Comandante do DEF', 'Pode gerenciar unidades organizacionais e acessar o Controle do Domínio de Negócio, sem precisar de um cargo de CA. Modelo pra replicar em Galpões/Doutrina quando tiverem gente designada.'
WHERE NOT EXISTS (SELECT 1 FROM grupos_acesso WHERE nome = 'Comandante do DEF');

INSERT IGNORE INTO grupo_acesso_permissoes (grupo_acesso_id, permissao_id, unidade_id)
SELECT g.id, p.id, NULL
FROM grupos_acesso g
CROSS JOIN permissoes p
WHERE g.nome = 'Comandante do DEF' AND p.chave IN ('gerenciar_dominio', 'gerenciar_unidades');
