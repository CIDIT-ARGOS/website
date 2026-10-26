# Changelog

O que mudou em cada versão do Argos publicada em produção. A seção de cada versão também vai para as notas da [Release no Forgejo](https://cosmos.simioni.dev.br/cidit/projeto-argos/releases), junto com a lista de commits.

Ao preparar uma versão, acrescente uma seção `## [vX.Y.Z] — AAAA-MM-DD` no topo, antes de criar a tag.

## [v1.2.4] — 2026-10-26

### Página inicial
- As imagens das telas **Principal** e **Login** foram trocadas por prints atuais do app.
- O link do projeto agora leva ao **Forgejo**, que tem o logo no topo e no rodapé. Os links do GitHub saíram.

### Painel de Comando e Ikarus37
- O rodapé ganhou o link "Projeto no Forgejo".
- Na documentação da API do Ikarus37 entraram os endpoints `servico.php`, `postos_servico.php`, `dispensas.php` e `livro.php`.

### Documentação e divulgação (não mudam o sistema no servidor)
- A **Wiki** do projeto, no Forgejo, foi criada com a apresentação para a feira de projetos científicos, o passo a passo de cada interface e o guia da API.
- O gerador de capturas de tela (`scripts/dev/capturar-telas.mjs`) produz as imagens da Wiki e da página inicial a partir do ambiente de demonstração.
- Foram adicionados o **vídeo promocional** e o **banner da apresentação** (`divulgacao/video/`).
- Este changelog foi criado, e as notas da Release passaram a trazer o resumo da versão.

### Publicação
- A pasta `divulgacao/` fica fora do pacote de produção.

## [v1.2.3] — 2026-10-11

Publica o que a v1.2.2 trazia, que não chegou a ir para o servidor:
- O sistema passou a usar o **horário de Brasília**, no PHP e no banco. Antes as chamadas da noite caíam no dia seguinte.
- **Easter egg:** tocar no olho do Argos, no app, faz cair um raio vermelho.
- **Correção na publicação:** o endpoint temporário de deploy não depende mais de um arquivo que ainda não estava no servidor. Foi isso que interrompeu a v1.2.2.

## [v1.2.2] — 2026-10-11

Não publicada: o deploy parou na etapa de manutenção. O conteúdo saiu na v1.2.3.

## [v1.2.1] — 2026-10-10

- **Correção urgente:** a versão foi para o endereço dos arquivos (`?v=`), para a hospedagem não entregar scripts antigos. Era isso que deixava o leitor de QR Code sem funcionar.
- O app ganhou o botão **Instalar o app no celular**.
- O QR Code passou a aceitar qualquer conteúdo, até o tamanho de um SHA-256.

## [v1.2.0] — 2026-10-10

- **Livro do Dia em três níveis, com validação:** Aluno de Dia à Esquadrilha → ao Esquadrão → ao Corpo de Alunos. Cada nível valida ou devolve o livro de baixo.
- O **motivo da falta** passou a ser escolhido num painel de botões, no app e no Painel.
- **Leitor de QR Code:** roda localmente, sem nada vindo da internet, com o detector nativo e o jsQR juntos.
- Controle de QR Codes no Painel e no Ikarus37.

## [v1.1.0] — 2026-10-10

- **Postos de serviço:** o aluno escolhe onde está de serviço; o Oficial Médico entra na dispensa.
- Livro do Dia e dispensas médicas no app.
- No Painel, quem está em pane agora aparece na página inicial, e o Efetivo tem números clicáveis.
- Nova identidade visual (marca ARG(olho)S) no app, no Painel e no Ikarus37, com a versão no rodapé.
- API com escopo por chave e erros no padrão das RFCs.
- Ambiente de demonstração com dados fictícios e testes de todas as telas.

## [v1.0.2] — 2026-10-10

- O deploy passou a conferir o envio arquivo por arquivo e a repetir o teste de fumaça.

## [v1.0.1] e [v1.0.0] — 2026-10-08

- Primeira versão em produção: a Área Funcional (app), o Painel de Comando e o Ikarus37, com a publicação automática pelo Forgejo.
