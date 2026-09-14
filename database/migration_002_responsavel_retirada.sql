-- Torna explícito quem de fato abriu cada retirada: até aqui, "aluno_servico_id"
-- podia ser qualquer aluno ativo escolhido livremente por quem estava logado no
-- painel, sem nenhum vínculo com a própria sessão — ou seja, dava pra atribuir a
-- retirada ao nome de outra pessoa. Agora quem abre é sempre a própria pessoa
-- logada, gravada automaticamente pelo servidor (nunca por escolha do usuário).
--
-- painel_usuario_id: FK pra painel_usuarios quando a sessão é uma conta normal
-- do painel. NULL quando o login veio da ponte do Ikarus37 (admin_usuarios não
-- tem uma linha em painel_usuarios pra referenciar).
-- responsavel_nome: nome de exibição gravado no momento da abertura — funciona
-- nos dois casos acima e não muda se a conta for renomeada depois.

ALTER TABLE retiradas
  MODIFY aluno_servico_id INT NULL,
  ADD COLUMN painel_usuario_id INT NULL AFTER aluno_servico_id,
  ADD COLUMN responsavel_nome VARCHAR(100) NULL AFTER painel_usuario_id,
  ADD FOREIGN KEY (painel_usuario_id) REFERENCES painel_usuarios(id);
