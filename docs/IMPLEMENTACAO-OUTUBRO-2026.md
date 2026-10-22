# Implementação das melhorias — Projeto Integrador 2026

## Entregue nesta versão

### Fase 1 — correções e segurança
- [x] `index.html` redireciona para o CMS dinâmico `index.php`.
- [x] Removido login de emergência com credenciais fixas se o banco falhar.
- [x] Sessão regenerada após login e cadastro.
- [x] Script de bootstrap do administrador funciona apenas via CLI e exige senha forte definida no ambiente.
- [x] Banco novo não cria contas de demonstração com senhas conhecidas nem histórico/auditoria fictícios.
- [x] Exclusão de mídia exige POST, CSRF e validação de caminho.
- [x] Upload valida MIME no servidor, gera nome aleatório e restringe execução de scripts em `uploads/media`.

### Fase 2 — edição do conteúdo
- [x] O editor existente gerencia campos de seção e itens internos (cards, etapas, listas e marcadores).
- [x] Editores podem salvar rascunhos; somente administradores podem publicar diretamente.
- [x] O histórico registra snapshot dos campos e dos itens internos.
- [x] Rascunhos são guardados em `sections.draft_data` sem sobrescrever a seção publicada.
- [x] Publicar aplica o conteúdo do editor e limpa o rascunho.

### Fase 3 — preview e publicação
- [x] Mantido o preview existente e a alternância de dispositivos.
- [x] A publicação atualiza o conteúdo público; salvar rascunho não altera os campos publicados.
- [x] As páginas administrativas verificam autenticação antes de processar POSTs; configurações exigem papel de administrador.
- [x] A restauração de versões ocorre em transação e recupera os itens internos quando o snapshot os contém.

### Fase 4 — biblioteca e usabilidade
- [x] Removidos arquivos fictícios da biblioteca de mídia.
- [x] Adicionado campo para texto alternativo das mídias.
- [x] Pré-visualização da biblioteca distingue imagens, vídeos e documentos.
- [x] URL base do projeto pode ser configurada por `SITE_URL`.

### Fase 5 — SEO e manutenção
- [x] Campo de título SEO e metadados Open Graph/Twitter.
- [x] Documentação de implantação e configuração de segurança.
- [x] Validação sintática dos arquivos PHP.

## Dependências que precisam ser configuradas/testadas no servidor

- **Backup automático:** exige tarefa agendada/cron com permissões de escrita e política de retenção; a preferência do painel sozinha não cria um agendador do sistema operacional.
- **Publicação agendada:** requer worker/cron executando publicações na hora marcada; habilitar a opção não substitui esse processo.
- **E-mail:** configure SMTP ou serviço transacional e teste entregabilidade. A função `mail()` depende do servidor e pode não estar configurada.
- **IA para conteúdo:** precisa de provedor/API key, política de privacidade e revisão humana antes de publicar.
- **Permissões:** faça teste de ponta a ponta com contas `admin` e `editor`; revise os endpoints para garantir que cada ação administrativa está restrita ao papel adequado.
- **Teste integrado:** não foi possível validar consultas contra um MySQL real nem executar um navegador automatizado neste ambiente. Faça os testes listados no README antes de usar em produção.

## Validação executada nesta entrega

`php -l` foi executado nos arquivos PHP do projeto sem erros de sintaxe. Isso não substitui testes funcionais de banco de dados, upload e permissões.
