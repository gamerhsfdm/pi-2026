# Implementação — Fases 1 a 3 (segurança, funcionalidades, desempenho e SEO)

> Limitação desta entrega: o ambiente onde o código foi alterado não tinha PHP nem MySQL.
> Os arquivos PHP passaram por verificação de balanceamento/estrutura, mas **não** por `php -l`
> nem por testes reais. Rode `sh scripts/validar-php.sh` e o checklist do final antes de publicar.

## Fase 1 — Correções críticas de segurança

| Item | O que foi feito | Onde |
|---|---|---|
| Conta com senha pública | O `INSERT` do admin `admin@projetotunisia.local` e a senha em comentário foram **removidos** do SQL | `database.sql` |
| Arquivos expostos | `.htaccess` bloqueia `.sql .md .json .lock .sh .log .example`, e as pastas `config includes docs scripts cache .git node_modules` (também com `.htaccess` próprio em cada pasta) | `.htaccess`, `*/.htaccess` |
| Força bruta no login | Tabela `login_attempts`: bloqueio por IP (5 falhas/15 min) e por e-mail (limite 4× maior, contra ataques distribuídos, sem permitir trancar a conta de outra pessoa com facilidade). A tabela é criada sozinha em instalações antigas | `includes/functions.php`, `admin/index.php` |
| Enumeração de contas | `password_verify` roda sempre (mesmo com e-mail inexistente) e a mensagem de erro é única | `admin/index.php` |
| Cookie com e-mail | Removido o "Lembrar de mim" que gravava o e-mail em texto puro; o cookie antigo é apagado no próximo login. A senha digitada também deixou de ser reenviada no HTML após erro | `admin/index.php` |
| Logout via GET | Agora exige **POST + CSRF**; um GET apenas mostra uma tela de confirmação | `admin/logout.php`, `includes/admin_header.php` |
| Cabeçalhos HTTP | CSP, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, COOP e HSTS (em HTTPS) | `config/database.php` |
| Sessão/cookies | `use_strict_mode`, `use_only_cookies`, cookie `Secure` em HTTPS, redirecionamento opcional `FORCE_HTTPS=1` | `config/database.php` |
| `.env` decorativo | `config/database.php` agora carrega o `.env`; `APP_ENV` padrão é **production** (erros nunca na tela) | `config/database.php`, `.env.example` |

**Atenção — limitação conhecida da CSP:** o painel usa `onclick=` e `<script>` inline, por isso `script-src` mantém `'unsafe-inline'`. A CSP ainda bloqueia frames de terceiros, `<object>`, `base-uri`/`form-action` externos e origens não listadas. Para endurecer mais, será preciso migrar os handlers inline para arquivos `.js` e usar *nonces*.

### Se você já tinha importado o banco antigo
1. Crie seu administrador: `ADMIN_EMAIL=... ADMIN_PASSWORD=... php admin/create_admin.php`
2. Execute `php scripts/check-security.php`. Se ele alertar sobre `admin@projetotunisia.local`, remova a conta:
   `DELETE FROM users WHERE email = 'admin@projetotunisia.local';`
3. Em produção, defina `APP_ENV=production`, `APP_DEBUG=false` e um usuário MySQL próprio (não `root` sem senha).

## Fase 2 — Funcionalidades que antes existiam só na tela

| Item | O que foi feito | Onde |
|---|---|---|
| Publicação agendada | Coluna `sections.publish_at`. No editor, **administradores** escolhem data/hora (Brasília) e clicam em *Agendar publicação*: o conteúdo é salvo como rascunho e publicado sozinho no horário, gerando versão no histórico, auditoria, notificação e limpeza do cache. Dá para cancelar o agendamento. Respeita a opção "Permitir publicação agendada" | `includes/scheduler.php`, `admin/editor.php` |
| Execução do agendador | (1) **cron** `* * * * * php scripts/cron.php`; (2) hospedagem sem terminal: `https://seusite/cron.php?token=CRON_TOKEN`; (3) reserva automática: visitas ao site/painel verificam no máx. 1×/min, depois de enviar a resposta. Trava `GET_LOCK` evita publicação duplicada | `scripts/cron.php`, `cron.php` |
| Backup automático | Dump SQL em PHP puro (não depende de `mysqldump`) + `uploads/` em `.zip`, retenção `BACKUP_KEEP`, nome com sufixo aleatório e pasta protegida. Diário via cron quando "Criar backup automático" está ligado; botão **Fazer backup agora** e download no painel (*Configurações → Fluxo de publicação*) | `includes/backup.php`, `scripts/backup.php`, `admin/download_backup.php` |
| E-mail por SMTP | Cliente SMTP próprio (STARTTLS/SSL, AUTH LOGIN, validação de certificado) configurado pelo `.env`; substitui o `mail()`. Envio depois da resposta (não trava a página). Botão de e-mail de teste no painel. **Não foi possível instalar o PHPMailer** (sem acesso à internet no ambiente); a interface `sendAppMail()` permite trocá-lo depois sem mexer no resto | `includes/Mailer.php` |
| Recuperação de senha | "Esqueci minha senha" → `forgot_password.php` → e-mail com link de **uso único e 1 hora**; só o hash SHA-256 do token fica no banco; resposta idêntica exista ou não o e-mail; limites de 3 pedidos/h por conta e 5/h por IP; senha mínima de 12 caracteres | `admin/forgot_password.php`, `admin/reset_password.php` |
| Migração automática | Bancos antigos recebem `publish_at`, `password_resets` e `login_attempts` sozinhos (memorizado em `cache/schema-v2-*.ok`) | `includes/schema.php` |
| Upload no editor | A extensão do arquivo agora vem do tipo MIME detectado, nunca do nome enviado pelo usuário (corrige um risco de upload com extensão arbitrária) | `admin/editor.php` |

### Configurar o cron
- **Linux/hospedagem:** `* * * * * /usr/bin/php /caminho/ProjetoIntegrador-2026/scripts/cron.php >/dev/null 2>&1`
- **Windows/XAMPP:** Agendador de Tarefas, a cada 1 minuto: `C:\xampp\php\php.exe C:\xampp\htdocs\ProjetoIntegrador-2026\scripts\cron.php`
- **Sem terminal:** defina `CRON_TOKEN` (16+ caracteres) e agende um "ping" por minuto na URL `/cron.php?token=...` (ex.: cron-job.org).

## Extra — Aparência e layout por seção (painel)

Em **Conteúdo → editar seção → "🎨 Aparência e layout"** (abaixo dos campos, acima de "Publicação agendada"). O painel tem **8 estilos prontos** (Claro elegante, Escuro, Degradê, Suave, Editorial, Impacto, Oceano, Pôr do sol) e **6 abas**:

| Aba | Controles |
|---|---|
| 📐 Layout | Alinhamento, largura do conteúdo, espaço acima/abaixo, altura mínima (meia tela, ¾, tela cheia), distância título→conteúdo, cards por linha (1–4), espaço entre cards, ocultar no celular ou no computador |
| 🖼️ Fundo | Cor sólida ou degradê (2 cores + direção); **imagem de fundo** escolhida na biblioteca de Mídia, com **escurecimento ajustável** e **parallax** |
| 🔤 Texto | Fonte do título/texto (14 opções), tamanho, peso, MAIÚSCULAS, itálico, espaço entre letras, altura da linha, efeito (degradê/sombra), detalhe sob o título (linha, barra, pontos), tamanho e altura de linha do texto |
| 🎨 Cores | Título, texto e destaque, com paleta rápida de 8 cores |
| 🃏 Cards e botões | Estilo (plano, borda, sombra suave/forte, **vidro**, borda de destaque), cantos, efeito ao passar o mouse (subir/brilho/ampliar), cor de fundo e de texto dos cards, formato dos botões |
| ✨ Efeitos | Animação ao rolar (surgir de baixo, fade, zoom, entrar pelas laterais), ocultar o selo |

- **Preview ao vivo** no mockup do editor e **aviso de contraste** (WCAG).
- Segue o fluxo do conteúdo: vale como **rascunho**, só vai ao site ao **publicar** (ou na **publicação agendada**), entra no **histórico** e volta junto com a versão restaurada.
- Só valores de listas fixas, cores `#RRGGBB` e caminhos de imagem de `uploads/media` ou `assets/img` são aceitos: **nenhum CSS livre** chega ao site.
- Dados em `sections.style_data` (JSON), coluna criada sozinha por `includes/schema.php`. CSS em `assets/css/section-styles.css`. Sem personalização, o site fica como antes.
- Google Fonts: ao usar uma dessas fontes, o navegador do visitante baixa o arquivo do Google. "Sistema", "Serifada" e "Monoespaçada" não usam rede.

**Banner principal (vídeo):** o `<video>` cobre toda a seção, então qualquer fundo ficava escondido atrás dele. Agora, ao definir **imagem, cor ou degradê** de fundo no banner, o vídeo é substituído automaticamente (e o preload do poster some). Na aba *Fundo* do banner há ainda os interruptores *Ocultar o vídeo* e *Remover o escurecimento padrão do banner*. Para voltar ao vídeo: fundo em "Padrão"/"Nenhuma" e interruptores desligados.

**Correções feitas junto:** o preview do editor sobrepunha as linhas de títulos grandes (altura de linha fixa em px pelo Tailwind); o alinhamento "Esquerda/Direita" não atingia o cabeçalho das seções (o CSS do site fixava `text-align: center`); a palavra destacada no título ficava minúscula; títulos eram impressos sem escape; e "Sobre o Projeto" encostava título e cards nas bordas da tela (agora tem respiro lateral).

## Fase 3 — Desempenho e SEO

| Item | Resultado |
|---|---|
| Vídeo do hero | 47,4 MB → ~7,4 MB (720p, H.264, sem áudio, `faststart`), com `poster` WebP de 11 KB, `preload="metadata"`, pausa fora da tela e **desligado** em economia de dados / movimento reduzido |
| Imagens | PNGs de 11,4 MB somando → ~1,1 MB em WebP. O site usa `<picture>` com WebP e fallback no original; `loading="lazy"` (logo do cabeçalho é `eager`) |
| Logo | 1,1 MB → 294 KB (719 px → 400 px) |
| Uploads novos | Geram `.webp` (até 2000 px) e `-thumb.webp` (480 px) automaticamente se a extensão **GD com WebP** estiver ativa; a grade da mídia usa a miniatura; excluir remove os derivados |
| Cache de página | HTML estático em `cache/index.html` (TTL `CACHE_TTL`, padrão 600 s) com `ETag`/304. É limpo **automaticamente** em qualquer ação administrativa auditada (publicar, restaurar, configurações, mídia, ordem das seções). Quem tem sessão do painel nunca recebe cache (o preview mostra sempre o atual) |
| Banco no site público | 1 consulta para todos os itens (antes: 2+ por seção); a migração inicial só roda se ainda não foi feita; o site público **não abre sessão** |
| Visitas | Cookie `pv_seen` evita consulta; a gravação acontece depois do envio da resposta; limpeza ocasional (visitas > 180 dias) |
| Cache do navegador | `mod_expires`/`mod_deflate` no `.htaccess`; CSS/JS com `?v=<data do arquivo>` para renovar quando mudarem |
| SEO | `robots.txt` e `sitemap.xml` dinâmicos, `canonical`, Open Graph/Twitter com `og-image.jpg` (1200×630), JSON-LD (`WebSite` + `EducationalOrganization`), favicon, `robots` meta |

### Variáveis novas (`.env`)
`APP_ENV`, `APP_DEBUG`, `PUBLIC_URL`, `FORCE_HTTPS`, `TRUST_PROXY`, `LOGIN_MAX_ATTEMPTS`, `LOGIN_WINDOW_MINUTES`, `CACHE_TTL`, `SMTP_HOST/PORT/SECURE/USER/PASS`, `MAIL_FROM`, `MAIL_FROM_NAME`, `SMTP_VERIFY_TLS`, `CRON_TOKEN`, `BACKUP_KEEP`, `BACKUP_DIR`, `BACKUP_INCLUDE_UPLOADS`.

### Requisitos do servidor
- Apache com `mod_rewrite`, `mod_headers`, `mod_expires`, `mod_deflate` e `AllowOverride All` (padrão do XAMPP já serve; todos os blocos têm `IfModule`).
- PHP 8.2+ com `pdo_mysql`, `fileinfo`, `zip` (backup completo; sem ela o backup só leva o banco) e, para WebP/miniaturas automáticos, `gd` com suporte a WebP.
- A pasta `cache/` precisa de permissão de escrita (se não houver, o site simplesmente funciona sem cache).

## Checklist de testes (manual)
1. `sh scripts/validar-php.sh` (sem erros) e `php scripts/check-security.php`.
2. Importar `database.sql` em banco vazio → criar admin pelo terminal → logar.
3. Errar a senha 5× → aparece o bloqueio; aguardar a janela ou apagar `login_attempts`.
4. Menu do usuário → **Sair da conta** (um clique) e abrir `/admin/logout.php` direto (deve pedir confirmação).
5. Abrir o site anônimo 2× e verificar o cabeçalho `X-Cache: MISS` e depois `HIT`; editar/publicar uma seção e confirmar que o site muda imediatamente.
6. Enviar uma imagem na mídia → conferir `.webp` e `-thumb.webp` em `uploads/media/`; excluir e conferir remoção.
7. Acessar `/sitemap.xml`, `/robots.txt` e tentar baixar `/database.sql`, `/config/database.php`, `/.env` (devem dar 403/404).
8. Editor (admin): agendar uma seção para daqui a 2 minutos → rodar `php scripts/cron.php` (ou esperar) → conferir publicação, histórico e site.
9. "Esqueci minha senha": e-mail de teste em *Configurações → Fluxo de publicação*, depois fluxo completo; o link só funciona 1 vez.
10. *Fazer backup agora* → baixar o `.zip` → confirmar `database.sql` e `uploads/`; testar a restauração em um banco de teste.
11. Editor: abrir *Aparência e layout*, aplicar "Escuro", publicar e conferir no site; trocar fonte e ver o preview; restaurar uma versão antiga no histórico.
12. Testar o site no celular com "Economizar dados" e com movimento reduzido (o vídeo não deve carregar).
