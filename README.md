# Tunísia CMS — Painel Administrativo Completo

Sistema de Gerenciamento de Conteúdo (CMS) completo, seguro e integrado ao site público do **Projeto Integrador 2026 — Tunísia**.

---

## 🚀 Tecnologias Utilizadas

- **PHP 8.2+** (Backend estruturado com PDO e boas práticas)
- **HTML5 & Tailwind CSS** (Compilado localmente para produção, sem depender de Play CDN)
- **Lucide Icons** (Ícones locais em SVG)
- **JavaScript ES6+** (Interatividades, preview responsivo, mapa dinâmico)
- **MySQL / MariaDB** (Gerenciável via phpMyAdmin)
- **Apache** (Compatível com XAMPP e servidores de hospedagem PHP padrão)

---

## 🗄️ Como Importar o Banco de Dados no phpMyAdmin

1. Abra o **XAMPP Control Panel** e inicie os módulos **Apache** e **MySQL**.
2. Acesse o **phpMyAdmin** no seu navegador: `http://localhost/phpmyadmin/`.
3. Na barra lateral esquerda, clique em **Novo** (New) e crie um banco de dados com o nome:
   - **Nome:** `projeto_tunisia`
   - **Agrupamento (Collation):** `utf8mb4_unicode_ci`
4. Selecione o banco de dados `projeto_tunisia` recém-criado.
5. Clique na aba superior **Importar** (Import).
6. Clique no botão **Escolher arquivo** e selecione o arquivo:
   - `c:\Users\r5\Documents\CursoTI-Senac\ProjetoIntegrador-2026\database.sql`
7. Role até o final da página e clique em **Executar** (Go).
8. O banco de dados estará pronto e populado com todas as seções e dados iniciais do Projeto Tunísia!

---

## 🔑 Acesso ao Painel Administrativo

- **URL de Login:** `http://localhost/ProjetoIntegrador-2026/admin/index.php`
- **Acesso:** use uma conta cadastrada no banco de dados. Não há autenticação de emergência com senha fixa e o `database.sql` **não cria nenhuma conta**: crie o primeiro administrador com `admin/create_admin.php` (veja abaixo).
- **Proteção de login:** após 5 tentativas inválidas em 15 minutos o IP é bloqueado temporariamente (configurável por `LOGIN_MAX_ATTEMPTS` e `LOGIN_WINDOW_MINUTES`).
- Para criar ou redefinir o administrador, execute `admin/create_admin.php` somente pelo terminal com `ADMIN_EMAIL`, `ADMIN_PASSWORD` (mínimo de 12 caracteres) e `ADMIN_NAME` definidos no ambiente. O script não funciona pelo navegador.

### Criação / Redefinição Segura do Primeiro Administrador

Se desejar recriar ou atualizar o primeiro administrador de forma isolada e segura:
1. Pelo terminal com PHP:
   ```powershell
   $env:ADMIN_EMAIL="admin@seudominio.com"
   $env:ADMIN_PASSWORD="uma-senha-forte-e-unica"
   $env:ADMIN_NAME="Administrador"
   & "C:\xampp\php\php.exe" admin\create_admin.php
   ```
2. **Não execute esse script pelo navegador.** Ele aceita somente execução via terminal.

---

## 💻 Telas e Funcionalidades Implementadas

1. **Dashboard (`admin/dashboard.php`)**:
   - Indicadores reais de visualizações, seções totais, publicações ativas e rascunhos.
   - Listagem direta de cada área do site com status de publicação e data relativa.
   - Atividades recentes e banner informativo de suporte.
2. **Conteúdo do Site (`admin/content.php`)**:
   - Visualização em cards de todas as seções reais (Hero, Projeto Integrador, Mapa, Celebrações, Bambalouni/Empreendedorismo, Modelos de Negócio, etc.).
   - Filtros instantâneos: *Todos*, *Publicados* e *Rascunhos*.
3. **Editor de Conteúdo (`admin/editor.php`)**:
   - Edição de títulos, subtítulos, botões, links e substituição de imagens.
   - **Preview vivo lado a lado** simulando desktop e tablet com atualização em tempo real.
   - Botões "Salvar rascunho" e "Publicar alterações".
4. **Biblioteca de Mídia (`admin/media.php`)**:
   - Upload de imagens com validação segura de MIME type e tamanho máximo.
   - Grade de miniaturas com dados de arquivo, cópia de caminho e exclusão com confirmação.
5. **Preview do Site (`admin/preview.php`)**:
   - Visualizador completo do site com alternador responsivo (Desktop, Tablet, Mobile) e botão para publicar todas as pendências.
6. **Histórico, Comparar e Restaurar (`admin/history.php`)**:
   - Registro de histórico por responsável e data.
   - Modal de comparação entre versão anterior e versão atual.
   - Modal de restauração segura que grava uma nova versão no histórico sem perda de dados.
7. **Configurações (`admin/settings.php`)**:
   - **Geral:** Nome, descrição, idioma principal, fuso horário e e-mail.
   - **Publicação:** Exigir revisão, backup automático, publicação agendada e retenção.
   - **Equipe e Acesso:** Listagem de usuários e modal para cadastrar administradores e editores.

---

## 🎨 Compilação do Tailwind CSS

O projeto já inclui o CSS compilado pronto para produção em `assets/css/app.css`.

Caso queira fazer novas alterações nos arquivos e recompilar o Tailwind:
```powershell
npm run build
# ou
npx tailwindcss -i ./assets/css/input.css -o ./assets/css/app.css --minify
```



## Atualizações de manutenção e segurança (outubro de 2026)

- O site `index.html` agora encaminha para `index.php`, que é a entrada dinâmica ligada ao CMS; assim evita-se manter duas versões divergentes do conteúdo.
- O histórico grava snapshots dos campos da seção e dos itens internos (cards/etapas/marcadores), e a restauração atualiza ambos em uma transação, mantendo compatibilidade com versões antigas sem lista de itens.
- A exclusão de mídia passou a exigir POST, token CSRF e validação do caminho dentro da biblioteca de uploads.
- Uploads validam o MIME detectado no servidor, usam nomes aleatórios e registram o usuário responsável.
- A biblioteca não exibe mais arquivos de demonstração como se fossem registros do banco e permite cadastrar texto alternativo para imagens.
- O login não usa mais credenciais fixas de fallback quando o MySQL está fora do ar; os IDs de sessão são regenerados no login e no cadastro.
- As configurações gerais incluem título SEO e a página pública inclui metadados Open Graph/Twitter.

### Verificações recomendadas antes de publicar

1. Faça backup do banco e da pasta `uploads/`.
2. Configure `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` e `DB_PASS` no ambiente; não publique `.env`.
3. Confirme permissões de escrita apenas na pasta de uploads necessária e impeça execução de scripts dentro dela no Apache.
4. Teste login, edição de uma seção, publicação, upload, exclusão CSRF, comparação e restauração de uma versão com itens.
5. Configure HTTPS em produção e revise as permissões de cada papel (administrador/editor).
6. Recursos que dependem de infraestrutura externa — envio real de e-mail, backups automáticos agendados, agendamento de publicação em segundo plano e geração de texto por IA — exigem cron/SMTP/provedor e devem ser configurados e testados no servidor de destino.

**Importante para instalações antigas:** se o banco já foi importado anteriormente, altere as senhas de todas as contas de demonstração e remova usuários que não serão utilizados. A versão atual do `database.sql` não cria contas com senhas conhecidas.

### URL base do projeto

Por padrão, o projeto assume `http://localhost/ProjetoIntegrador-2026`. Em outra pasta ou na raiz do domínio, defina a variável de ambiente `SITE_URL` antes de iniciar o PHP (por exemplo, `SITE_URL=` para a raiz). Copie `.env.example` para `.env`: ele agora é carregado automaticamente por `config/database.php` (variáveis já definidas no servidor têm prioridade).

### Rascunhos sem impacto na publicação

Ao usar **Salvar rascunho** no editor, o conteúdo e os itens são armazenados em `sections.draft_data`; a versão publicada continua sendo servida pelo site. Ao usar **Publicar alterações**, o snapshot do editor é aplicado aos campos e itens publicados e o rascunho é removido.

Cadastros públicos ficam inativos até um administrador aprová-los em **Configurações → Equipe e acesso**. A área de configurações é restrita a administradores.

---

## Fases 1 a 3 — segurança, desempenho e SEO

Resumo do que mudou (agendamento, backup, SMTP e recuperação de senha incluídos; detalhes e checklist em [`docs/IMPLEMENTACAO-FASES-1-3.md`](docs/IMPLEMENTACAO-FASES-1-3.md)):

- **Segurança:** SQL sem conta padrão, `.htaccess` bloqueando `.sql`/`config`/`includes`/`docs`, limite de tentativas de login, logout por POST + CSRF, cabeçalhos HTTP de segurança, `.env` carregado de verdade e `APP_ENV=production` por padrão.
- **Funcionalidades:** publicação agendada, backup automático (+ botão manual), e-mail por SMTP e recuperação de senha por e-mail.
- **Aparência por seção:** alinhamento, largura, fundo (cor/degradê), 14 fontes, tamanhos, cores, estilo dos cards e animações, com preview ao vivo e estilos prontos.
- **Desempenho:** vídeo do hero de 47 MB para ~7 MB, imagens em WebP (com fallback), miniaturas no upload, cache de página, consulta única de itens, registro de visitas fora do caminho crítico.
- **SEO:** `sitemap.xml`, `robots.txt`, canonical, Open Graph com imagem 1200×630 e dados estruturados JSON-LD.

Depois de instalar, rode no terminal: `php scripts/check-security.php`.

