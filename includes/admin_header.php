<?php
/**
 * Layout do Cabeçalho e Sidebar do Painel Tunísia CMS
 * Com notificações dinâmicas (badge contador e dropdown), menu de usuário com avatar real e links funcionais
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scheduler.php';
requireAuth();
registerSchedulerCheck(); // publicações agendadas (reserva sem cron)

// Garante dados atualizados do usuário atual
$pdo = getPDO();
$currentUser = getCurrentUser();
if ($pdo && !empty($currentUser['id'])) {
    $stmtU = $pdo->prepare("SELECT id, name, email, role, avatar FROM users WHERE id = ? LIMIT 1");
    $stmtU->execute([$currentUser['id']]);
    $uData = $stmtU->fetch(PDO::FETCH_ASSOC);
    if ($uData) {
        $_SESSION['user'] = array_merge($_SESSION['user'] ?? [], $uData);
        $currentUser = $_SESSION['user'];
    }
}

$unreadNotifCount = getUnreadNotificationsCount();
$activePage = $activePage ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="pt-BR" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'Painel') ?> — Tunísia CMS</title>
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/app.css?v=<?= assetVersion('assets/css/app.css') ?>">
    <script src="<?= SITE_URL ?>/admin/assets/js/lucide.min.js"></script>
    <style>
        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background-color: #cbd5e1;
            border-radius: 9999px;
        }

        /* ===== Modo Escuro Minimalista e Sofisticado (Slate Theme) ===== */
        html.dark {
            color-scheme: dark;
        }
        html.dark body { 
            background-color: #0f172a !important; 
            color: #94a3b8 !important; 
        }
        /* Superfícies principais (Cards, Sidebar, Topo) */
        html.dark aside,
        html.dark .hd-bar,
        html.dark .bg-white,
        html.dark .notifications-hero,
        html.dark .notification-stat,
        html.dark .notifications-list { 
            background: #1e293b !important; 
            border-color: #334155 !important; 
            color: #e2e8f0 !important; 
        }
        /* Bordas sutis */
        html.dark .border-slate-200, 
        html.dark .border-slate-100,
        html.dark .border-slate-200\/80,
        html.dark .border-slate-200\/70,
        html.dark .border-slate-200\/60,
        html.dark .divide-slate-100 > * + *,
        html.dark .divide-slate-200 > * + *,
        html.dark #adminSidebar { 
            border-color: #334155 !important; 
        }
        /* Itens de lista no Dashboard e Histórico */
        html.dark .dash-section-item,
        html.dark .divide-y > div {
            border-color: #334155 !important;
            background: transparent !important;
        }
        html.dark .dash-section-item:hover,
        html.dark .divide-y > div:hover {
            background-color: #243247 !important;
        }
        /* Botões de Ação Excluir em modo escuro */
        html.dark button[title="Excluir seção"],
        html.dark button[title="Mover para lixeira"],
        html.dark .border-red-200,
        html.dark .border-rose-200 {
            background-color: rgba(239, 68, 68, 0.1) !important;
            border-color: rgba(239, 68, 68, 0.25) !important;
            color: #f87171 !important;
        }
        html.dark button[title="Excluir seção"]:hover,
        html.dark button[title="Mover para lixeira"]:hover,
        html.dark .hover\:bg-red-50:hover,
        html.dark .hover\:bg-rose-50:hover {
            background-color: rgba(239, 68, 68, 0.2) !important;
            border-color: #f87171 !important;
            color: #fca5a5 !important;
        }
        /* Botão Enviar Arquivos e Botões Primários */
        html.dark label[for="fileUploadInput"],
        html.dark .bg-indigo-600 {
            background-color: #4f46e5 !important;
            color: #ffffff !important;
            border: 1px solid #6366f1 !important;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25) !important;
        }
        html.dark label[for="fileUploadInput"]:hover,
        html.dark .bg-indigo-600:hover {
            background-color: #4338ca !important;
        }
        /* Simulação de Navegador e Preview do Site */
        html.dark .bg-slate-200\/50,
        html.dark #previewFrameContainer {
            background-color: #0b0f19 !important;
            border-color: #334155 !important;
        }
        html.dark .bg-slate-50\/50 {
            background-color: #1e293b !important;
        }
        html.dark #previewIframe {
            background-color: #0b0f19 !important;
        }
        /* Tipografia */
        html.dark .text-slate-900, 
        html.dark .text-slate-800, 
        html.dark .notifications-title,
        html.dark .notifications-section-title,
        html.dark .notification-stat-number,
        html.dark .notification-title,
        html.dark .notification-item-title,
        html.dark .notification-item-title span,
        html.dark .notifications-empty-title { 
            color: #f8fafc !important; 
        }
        /* Links e Abas Ativas (Menu Lateral e Configurações) */
        html.dark .bg-indigo-50,
        html.dark .hover\:bg-slate-50:hover,
        html.dark a.bg-indigo-50 {
            background-color: #1e2238 !important;
            border-color: #3b3a6e !important;
            color: #a5b4fc !important;
        }
        html.dark .text-indigo-700,
        html.dark .text-indigo-600 {
            color: #a5b4fc !important;
        }
        html.dark .bg-indigo-50 i,
        html.dark a.bg-indigo-50 i {
            color: #818cf8 !important;
        }
        html.dark .text-slate-700,
        html.dark .text-slate-600 { 
            color: #cbd5e1 !important; 
        }
        html.dark .text-slate-500, 
        html.dark .text-slate-400,
        html.dark .notifications-description,
        html.dark .notification-message,
        html.dark .notification-stat-label,
        html.dark .notifications-section-subtitle,
        html.dark .notification-meta { 
            color: #94a3b8 !important; 
        }
        /* Fundos secundários e inputs */
        html.dark .bg-slate-50, 
        html.dark .bg-slate-100,
        html.dark .notification-filter-group,
        html.dark .notification-avatar { 
            background-color: #0f172a !important; 
            border-color: #334155 !important; 
            color: #cbd5e1 !important; 
        }
        html.dark input, 
        html.dark textarea, 
        html.dark select { 
            background-color: #0f172a !important; 
            border-color: #334155 !important; 
            color: #f8fafc !important; 
        }
        html.dark input:focus, 
        html.dark textarea:focus, 
        html.dark select:focus { 
            border-color: #6366f1 !important; 
            background-color: #0f172a !important; 
        }
        /* Componentes de Notificações na página */
        html.dark .notification-item {
            border-color: #334155 !important;
            background: #1e293b !important;
        }
        html.dark .notification-item:hover {
            background: #243247 !important;
        }
        html.dark .notification-item.is-unread {
            background: #1e2238 !important;
            border-left: 3px solid #6366f1 !important;
        }
        html.dark .notification-item.is-unread:hover {
            background: #252b45 !important;
        }
        html.dark .notification-filter {
            color: #94a3b8;
        }
        html.dark .notification-filter.active {
            background: #334155 !important;
            color: #f8fafc !important;
        }
        html.dark .notification-filter-count {
            background: #1e293b;
            color: #94a3b8;
        }
        html.dark .notification-filter.active .notification-filter-count {
            background: #475569;
            color: #f8fafc;
        }
        html.dark .notification-mark-all,
        html.dark .notification-read-action {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #cbd5e1 !important;
        }
        html.dark .notification-mark-all:hover,
        html.dark .notification-read-action:hover {
            background: #334155 !important;
            color: #f8fafc !important;
        }
        html.dark .notification-section-link {
            color: #818cf8 !important;
        }
        /* Topo e Dropdowns */
        html.dark .hd-bar {
            background: #1e293b !important;
            border-color: #334155 !important;
        }
        html.dark .hd-search input {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }
        html.dark .hd-kbd {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #94a3b8 !important;
        }
        html.dark .hd-icon-btn { 
            color: #94a3b8; 
        }
        html.dark .hd-icon-btn:hover { 
            background: #334155; 
            color: #f8fafc; 
        }
        html.dark .hd-pop { 
            background-color: #1e293b !important; 
            border-color: #334155 !important; 
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4) !important;
        }
        html.dark .hd-pop-head, 
        html.dark .hd-pop-foot, 
        html.dark .hd-user { 
            border-color: #334155 !important; 
            background: #1e293b !important;
        }
        html.dark .hd-pop-foot button {
            color: #cbd5e1;
        }
        html.dark .hd-pop-foot button:hover {
            background: #334155;
            color: #f8fafc;
        }
        html.dark .hd-n-item { 
            border-color: #334155 !important; 
            color: #cbd5e1 !important; 
        }
        html.dark .hd-n-item:hover { 
            background: #243247 !important; 
        }
        html.dark .hd-n-item.unread { 
            background: #1e2238 !important; 
        }
        html.dark .hd-n-title b {
            color: #f8fafc !important;
        }
        html.dark .hd-menu a { 
            color: #cbd5e1; 
        }
        html.dark .hd-menu a:hover { 
            background: #334155; 
            color: #f8fafc; 
        }
        html.dark .hd-menu hr {
            border-color: #334155;
        }
        html.dark .hd-profile-btn:hover,
        html.dark .hd-profile-btn[aria-expanded="true"] { 
            background: #334155; 
            border-color: #475569; 
        }
        html.dark .hd-profile-meta b {
            color: #f8fafc !important;
        }
        html.dark .faq-answer { 
            background-color: #0f172a !important; 
            color: #cbd5e1 !important;
            border-color: #334155 !important;
        }
        html.dark .faq-item {
            border-color: #334155 !important;
        }
        html.dark .faq-btn:hover {
            background-color: #243247 !important;
        }
        html.dark .gs-item.is-active {
            background-color: #334155 !important;
        }
        html.dark .gs-ic {
            background-color: #0f172a !important;
            color: #94a3b8 !important;
        }
        html.dark .gs-title {
            color: #f8fafc !important;
        }
        html.dark .gs-sub {
            color: #94a3b8 !important;
        }
        html.dark .gs-title mark, html.dark .gs-sub mark {
            background: #4338ca !important;
            color: #f8fafc !important;
        }
        html.dark .gs-foot {
            border-color: #334155 !important;
            color: #64748b !important;
        }

        /* ===== Topo: notificações e menu do usuário (independente do build do Tailwind) ===== */
        .hd-actions{display:flex;align-items:center;gap:8px}
        .hd-pop-wrap{position:relative}
        .hd-icon-btn{position:relative;width:40px;height:40px;display:inline-flex;align-items:center;justify-content:center;border:0;background:none;border-radius:12px;color:#64748b;cursor:pointer;transition:background .15s,color .15s}
        .hd-icon-btn:hover,.hd-icon-btn[aria-expanded="true"]{background:#f1f5f9;color:#0f172a}
        .hd-icon-btn svg{width:20px;height:20px}
        .hd-badge{position:absolute;top:0;right:-2px;min-width:17px;height:17px;padding:0 4px;border-radius:999px;background:#4f46e5;color:#fff;font-size:10px;font-weight:700;line-height:17px;text-align:center;border:2px solid #fff;box-sizing:content-box;transform:scale(.9);transform-origin:top right}
        .hd-badge[hidden]{display:none}
        .hd-profile-btn{display:flex;align-items:center;gap:10px;padding:4px 10px 4px 4px;border:1px solid transparent;border-radius:999px;background:none;cursor:pointer;transition:background .15s,border-color .15s}
        .hd-profile-btn:hover,.hd-profile-btn[aria-expanded="true"]{background:#f8fafc;border-color:#e2e8f0}
        .hd-profile-meta{display:flex;flex-direction:column;text-align:left;line-height:1.2;min-width:0}
        .hd-profile-meta b{font-size:12.5px;font-weight:600;color:#0f172a;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .hd-profile-meta small{font-size:11px;color:#94a3b8}
        .hd-chev{width:14px;height:14px;color:#94a3b8;transition:transform .15s}
        .hd-profile-btn[aria-expanded="true"] .hd-chev{transform:rotate(180deg)}
        .hd-avatar{width:34px;height:34px;border-radius:999px;background:#e2e8f0;border:1px solid #cbd5e1;color:#334155;font-size:13px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0}
        .hd-avatar img{width:100%;height:100%;object-fit:cover;display:block}
        .hd-avatar.lg{width:44px;height:44px;font-size:16px}
        .hd-avatar.sm{width:36px;height:36px;font-size:13px}

        .hd-pop{position:absolute;right:0;top:calc(100% + 8px);z-index:60;background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 12px 32px -8px rgba(15,23,42,.18),0 2px 6px rgba(15,23,42,.05);overflow:hidden}
        .hd-pop[hidden]{display:none}
        .hd-pop-head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #f1f5f9}
        .hd-pop-head strong{font-size:14px;color:#0f172a}
        .hd-pop-head a{font-size:12px;font-weight:600;color:#4f46e5;text-decoration:none}
        .hd-pop-head a:hover{text-decoration:underline}
        .hd-pop-foot{padding:10px 16px;border-top:1px solid #f1f5f9;background:#f8fafc;text-align:center}
        .hd-pop-foot button{font-size:12px;font-weight:600;color:#475569;background:none;border:0;cursor:pointer;padding:4px 8px;border-radius:8px}
        .hd-pop-foot button:hover{color:#4338ca;background:#eef2ff}

        .hd-notif{width:384px;max-width:calc(100vw - 24px)}
        .hd-notif-list{max-height:380px;overflow-y:auto}
        .hd-n-item{display:flex;gap:12px;align-items:flex-start;padding:12px 16px;text-decoration:none;color:inherit;border-bottom:1px solid #f1f5f9;position:relative;transition:background .12s}
        .hd-n-item:last-child{border-bottom:0}
        .hd-n-item:hover{background:#f8fafc}
        .hd-n-item.unread{background:#f5f7ff}
        .hd-n-item.unread:hover{background:#eef2ff}
        .hd-n-body{min-width:0;flex:1}
        .hd-n-title{display:flex;align-items:center;justify-content:space-between;gap:8px}
        .hd-n-title b{font-size:13px;font-weight:600;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .hd-n-time{font-size:11px;color:#94a3b8;white-space:nowrap;flex-shrink:0}
        .hd-n-msg{margin:2px 0 0;font-size:12px;line-height:1.45;color:#64748b;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
        .hd-n-dot{width:8px;height:8px;border-radius:999px;background:#4f46e5;flex-shrink:0;margin-top:6px}
        .hd-n-state{padding:32px 16px;text-align:center;font-size:12.5px;color:#94a3b8}
        .hd-n-state svg{width:28px;height:28px;margin:0 auto 8px;display:block;color:#cbd5e1}
        .hd-n-state.err{color:#e11d48}

        .hd-profile{width:268px}
        .hd-user{display:flex;gap:12px;align-items:center;padding:16px;border-bottom:1px solid #f1f5f9}
        .hd-user-info{min-width:0}
        .hd-user-info b{display:block;font-size:13.5px;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .hd-user-info span{display:block;font-size:12px;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .hd-role{display:inline-block !important;margin-top:5px;padding:2px 8px;border-radius:6px;background:#eef2ff;color:#4338ca !important;font-size:11px !important;font-weight:600}
        .hd-menu{padding:6px}
        .hd-menu a{display:flex;align-items:center;gap:12px;padding:9px 10px;border-radius:10px;font-size:13px;font-weight:500;color:#334155;text-decoration:none;transition:background .12s}
        .hd-menu a:hover{background:#f1f5f9;color:#0f172a}
        .hd-menu a svg{width:17px;height:17px;color:#94a3b8;flex-shrink:0}
        .hd-menu a:hover svg{color:#4f46e5}
        .hd-menu hr{border:0;border-top:1px solid #f1f5f9;margin:6px 4px}
        .hd-menu a.danger{color:#be123c}
        .hd-menu a.danger svg{color:#fb7185}
        .hd-menu a.danger:hover{background:#fff1f2;color:#9f1239}
        .hd-menu a.danger:hover svg{color:#e11d48}

        /* ===== Estrutura responsiva: gaveta lateral, barra superior e busca ===== */
        /* O painel usa rolagem interna. A altura explícita impede que o shell termine
           antes da viewport e deixe o body exibindo uma área vazia abaixo do editor. */
        html,body{height:100%;min-height:100%;overflow:hidden}
        .admin-shell{height:100vh;height:100dvh;min-height:100vh;overflow:hidden}
        .adm-main{padding:16px;min-height:0;overscroll-behavior:contain}
        @media (min-width:640px){.adm-main{padding:24px}}
        @media (min-width:1024px){.adm-main{padding:32px}}
        .adm-main img,.adm-main video{max-width:100%}

        .hd-bar{position:relative;z-index:30;display:flex;align-items:center;gap:8px;height:64px;padding:0 12px;background:#fff;border-bottom:1px solid #e2e8f0;flex-shrink:0}
        @media (min-width:640px){.hd-bar{padding:0 24px;gap:12px}}
        @media (min-width:1024px){.hd-bar{padding:0 32px}}
        .hd-burger{display:inline-flex;flex-shrink:0}
        .hd-crumbs{display:none;align-items:center;gap:8px;font-size:14px;color:#94a3b8;white-space:nowrap;flex-shrink:0}
        .hd-crumbs strong{font-weight:600;color:#334155}
        @media (min-width:1280px){.hd-crumbs{display:flex}}
        .hd-bar .hd-actions{margin-left:auto;flex-shrink:0}

        .hd-search{position:relative;flex:1 1 0;min-width:0;max-width:460px}
        .hd-search input{box-sizing:border-box;width:100%;height:40px;padding:0 40px 0 38px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;font-size:13px;color:#0f172a;transition:border-color .15s,background .15s,box-shadow .15s;-webkit-appearance:none;appearance:none}
        .hd-search input::-webkit-search-cancel-button{display:none}
        .hd-search input::placeholder{color:#94a3b8}
        .hd-search input:focus{outline:none;border-color:#6366f1;background:#fff;box-shadow:0 0 0 3px rgba(99,102,241,.12)}
        .hd-search-ic{position:absolute;left:12px;top:50%;width:16px;height:16px;margin-top:-8px;color:#94a3b8;pointer-events:none}
        .hd-kbd{position:absolute;right:10px;top:50%;transform:translateY(-50%);font:600 11px/1 system-ui,sans-serif;color:#94a3b8;border:1px solid #e2e8f0;background:#fff;border-radius:6px;padding:4px 7px;pointer-events:none;display:none}
        @media (min-width:768px){.hd-kbd{display:block}}
        .hd-search.has-text .hd-kbd,.hd-search:focus-within .hd-kbd{display:none}
        .hd-search-clear{position:absolute;right:6px;top:50%;margin-top:-14px;width:28px;height:28px;border:0;border-radius:8px;background:none;color:#94a3b8;cursor:pointer;display:inline-flex;align-items:center;justify-content:center}
        .hd-search-clear[hidden]{display:none}
        .hd-search-clear:hover{background:#e2e8f0;color:#0f172a}
        .hd-search-clear svg{width:14px;height:14px}

        .hd-results{left:0;right:auto;width:max(100%,440px);max-height:min(70vh,460px);overflow-y:auto;padding:6px}
        .gs-label{padding:10px 10px 4px;font-size:11.5px;font-weight:600;color:#94a3b8}
        .gs-item{display:flex;align-items:center;gap:12px;padding:8px 10px;border-radius:10px;text-decoration:none;color:inherit;cursor:pointer}
        .gs-item.is-active{background:#eef2ff}
        .gs-ic{width:34px;height:34px;border-radius:9px;background:#f1f5f9;color:#64748b;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden}
        .gs-ic svg{width:16px;height:16px}
        .gs-ic img{width:100%;height:100%;object-fit:cover}
        .is-active .gs-ic{background:#e0e7ff;color:#4338ca}
        .gs-txt{min-width:0;flex:1}
        .gs-title{display:block;font-size:13px;font-weight:600;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .gs-sub{display:block;font-size:11.5px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px}
        .gs-title mark,.gs-sub mark{background:#fef08a;color:inherit;border-radius:3px;padding:0 1px}
        .gs-go{width:15px;height:15px;color:#6366f1;flex-shrink:0;opacity:0}
        .is-active .gs-go{opacity:1}
        .gs-state{padding:26px 16px;text-align:center;font-size:12.5px;color:#64748b;line-height:1.5}
        .gs-state a{color:#4f46e5;font-weight:600;text-decoration:none}
        .gs-state a:hover{text-decoration:underline}
        .gs-foot{display:none;padding:8px 10px 4px;margin-top:4px;border-top:1px solid #f1f5f9;font-size:11px;color:#94a3b8}
        @media (min-width:768px){.gs-foot{display:block}}
        @media (max-width:767px){
            .hd-results{position:fixed;left:12px;right:12px;top:68px;width:auto;max-height:calc(100vh - 84px)}
        }

        /* Gaveta lateral (abaixo de 1024px) */
        .hd-icon-btn.sb-close{display:none;margin-left:auto}
        .sb-overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:65;opacity:0;transition:opacity .2s}
        .sb-overlay[hidden]{display:none}
        .sb-overlay.show{opacity:1}
        body.sb-lock{overflow:hidden}
        @media (max-width:1023px){
            .sb{position:fixed;top:0;bottom:0;left:0;z-index:70;width:280px;max-width:86vw;overflow-y:auto;transform:translateX(-100%);visibility:hidden;transition:transform .22s ease,visibility 0s linear .22s;box-shadow:none}
            .sb.is-open{transform:none;visibility:visible;transition:transform .22s ease,visibility 0s;box-shadow:0 0 40px rgba(15,23,42,.25)}
            .hd-icon-btn.sb-close{display:inline-flex}
        }
        @media (min-width:1024px){.hd-icon-btn.hd-burger{display:none}.sb-overlay{display:none !important}}
        @media (prefers-reduced-motion:reduce){.sb,.sb.is-open,.sb-overlay{transition:none}}
        .hd-icon-btn:focus-visible,.hd-profile-btn:focus-visible,.hd-menu a:focus-visible,.hd-n-item:focus-visible,.hd-pop-foot button:focus-visible,.hd-pop-head a:focus-visible{outline:2px solid #6366f1;outline-offset:2px}
        @media (max-width:767px){.hd-profile-meta,.hd-chev{display:none}.hd-profile-btn{padding:4px}}
    </style>
</head>
<body class="h-full flex flex-col font-sans antialiased text-slate-800 bg-slate-100">

<div class="admin-shell flex">
    <!-- Sidebar -->
    <div id="sbOverlay" class="sb-overlay" hidden></div>
    <aside id="adminSidebar" class="sb w-64 bg-white border-r border-slate-200 flex flex-col justify-between shrink-0 shadow-sm z-20" aria-label="Menu principal">
        <div>
            <!-- Logo / Brand -->
            <div class="h-16 flex items-center px-6 gap-3 border-b border-slate-100">
                <div class="w-8 h-8 rounded-full bg-indigo-600 flex items-center justify-center text-white font-bold shadow-md shadow-indigo-100">
                    <i data-lucide="globe" class="w-5 h-5"></i>
                </div>
                <span class="text-xl font-bold tracking-tight text-slate-900">Tunísia CMS</span>
                <button type="button" id="sbClose" class="hd-icon-btn sb-close" aria-label="Fechar menu"><i data-lucide="x"></i></button>
            </div>

            <!-- Navigation Links -->
            <nav class="p-4 space-y-1.5 text-sm font-medium">
                <a href="<?= ADMIN_URL ?>/dashboard.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition <?= $activePage === 'dashboard' ? 'bg-indigo-50 text-indigo-700 font-semibold' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' ?>">
                    <i data-lucide="layout-grid" class="w-5 h-5 <?= $activePage === 'dashboard' ? 'text-indigo-600' : 'text-slate-400' ?>"></i>
                    <span>Dashboard</span>
                </a>

                <a href="<?= ADMIN_URL ?>/content.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition <?= $activePage === 'content' ? 'bg-indigo-50 text-indigo-700 font-semibold' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' ?>">
                    <i data-lucide="file-text" class="w-5 h-5 <?= $activePage === 'content' ? 'text-indigo-600' : 'text-slate-400' ?>"></i>
                    <span>Conteúdo do Site</span>
                </a>

                <a href="<?= ADMIN_URL ?>/media.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition <?= $activePage === 'media' ? 'bg-indigo-50 text-indigo-700 font-semibold' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' ?>">
                    <i data-lucide="image" class="w-5 h-5 <?= $activePage === 'media' ? 'text-indigo-600' : 'text-slate-400' ?>"></i>
                    <span>Biblioteca de mídia</span>
                </a>

                <a href="<?= ADMIN_URL ?>/preview.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition <?= $activePage === 'preview' ? 'bg-indigo-50 text-indigo-700 font-semibold' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' ?>">
                    <i data-lucide="monitor" class="w-5 h-5 <?= $activePage === 'preview' ? 'text-indigo-600' : 'text-slate-400' ?>"></i>
                    <span>Preview do site</span>
                </a>

                <a href="<?= ADMIN_URL ?>/history.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition <?= $activePage === 'history' ? 'bg-indigo-50 text-indigo-700 font-semibold' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' ?>">
                    <i data-lucide="history" class="w-5 h-5 <?= $activePage === 'history' ? 'text-indigo-600' : 'text-slate-400' ?>"></i>
                    <span>Histórico de versões</span>
                </a>

                <div class="pt-6 pb-2 px-3 text-xs font-semibold uppercase tracking-wider text-slate-400">Sistema</div>

                <a href="<?= ADMIN_URL ?>/notifications.php" class="flex items-center justify-between px-3 py-2.5 rounded-xl transition <?= $activePage === 'notifications' ? 'bg-indigo-50 text-indigo-700 font-semibold' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' ?>">
                    <div class="flex items-center gap-3">
                        <i data-lucide="bell" class="w-5 h-5 <?= $activePage === 'notifications' ? 'text-indigo-600' : 'text-slate-400' ?>"></i>
                        <span>Notificação</span>
                    </div>
                    <?php if ($unreadNotifCount > 0): ?>
                        <span id="sidebarNotifBadge" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-600 text-white">
                            <?= $unreadNotifCount ?>
                        </span>
                    <?php endif; ?>
                </a>

                <a href="<?= ADMIN_URL ?>/settings.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition <?= $activePage === 'settings' ? 'bg-indigo-50 text-indigo-700 font-semibold' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' ?>">
                    <i data-lucide="settings" class="w-5 h-5 <?= $activePage === 'settings' ? 'text-indigo-600' : 'text-slate-400' ?>"></i>
                    <span>Configuração</span>
                </a>

                <a href="<?= ADMIN_URL ?>/support.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition <?= $activePage === 'support' ? 'bg-indigo-50 text-indigo-700 font-semibold' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' ?>">
                    <i data-lucide="help-circle" class="w-5 h-5 <?= $activePage === 'support' ? 'text-indigo-600' : 'text-slate-400' ?>"></i>
                    <span>Suporte</span>
                </a>
            </nav>
        </div>

    </aside>

    <!-- Main Content Wrapper -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden bg-slate-50">
        <!-- Top Header Bar -->
        <header class="hd-bar">
            <button type="button" id="sbOpen" class="hd-icon-btn hd-burger" aria-label="Abrir menu" aria-controls="adminSidebar" aria-expanded="false"><i data-lucide="menu"></i></button>

            <!-- Breadcrumbs (só em telas largas) -->
            <div class="hd-crumbs">
                <span>Painel</span><span>&gt;</span><strong><?= e($pageTitle ?? 'Dashboard') ?></strong>
            </div>

            <!-- Busca global -->
            <div class="hd-search" id="globalSearch" role="search">
                <i data-lucide="search" class="hd-search-ic"></i>
                <input id="gsInput" type="search" name="q" autocomplete="off" spellcheck="false" placeholder="Buscar seções, mídia, ajuda…" aria-label="Buscar no painel" role="combobox" aria-expanded="false" aria-controls="gsPanel" aria-autocomplete="list">
                <kbd class="hd-kbd" id="gsKbd" title="Atalho: pressione / ou Ctrl+K">/</kbd>
                <button type="button" id="gsClear" class="hd-search-clear" hidden aria-label="Limpar busca"><i data-lucide="x"></i></button>
                <div id="gsPanel" class="hd-pop hd-results" role="listbox" aria-label="Resultados da busca" hidden></div>
            </div>

            <!-- Notificações e menu do usuário -->
            <div class="hd-actions">
                <?php
                    $avatarUrl = !empty($currentUser['avatar']) ? SITE_URL . '/' . ltrim($currentUser['avatar'], '/') : '';
                    $userInitial = strtoupper(mb_substr($currentUser['name'] ?? 'A', 0, 1));
                    $roleLabel = ($currentUser['role'] ?? '') === 'admin' ? 'Administrador' : 'Editor';
                ?>
                <!-- Alternador de Modo Escuro -->
                <button type="button" id="themeToggleBtn" class="hd-icon-btn" title="Alternar tema claro/escuro" aria-label="Alternar tema">
                    <i data-lucide="moon" id="themeMoonIcon"></i>
                    <i data-lucide="sun" id="themeSunIcon" class="hidden"></i>
                </button>

                <!-- Notificações -->
                <div class="hd-pop-wrap" id="notifDropdownContainer">
                    <button type="button" id="notifDropdownBtn" class="hd-icon-btn" title="Notificações" aria-label="Notificações" aria-haspopup="true" aria-expanded="false" aria-controls="notifDropdownMenu">
                        <i data-lucide="bell"></i>
                        <span id="notifBadge" class="hd-badge" <?= $unreadNotifCount > 0 ? '' : 'hidden' ?>><?= $unreadNotifCount > 9 ? '9+' : (int)$unreadNotifCount ?></span>
                    </button>
                    <div id="notifDropdownMenu" class="hd-pop hd-notif" hidden>
                        <div class="hd-pop-head">
                            <strong>Notificações</strong>
                            <a href="<?= ADMIN_URL ?>/notifications.php">Ver todas</a>
                        </div>
                        <div id="notifDropdownList" class="hd-notif-list custom-scrollbar">
                            <div class="hd-n-state">Carregando...</div>
                        </div>
                        <div class="hd-pop-foot">
                            <button type="button" id="notifMarkAll">Marcar todas como lidas</button>
                        </div>
                    </div>
                </div>

                <!-- Menu do usuário -->
                <div class="hd-pop-wrap" id="profileDropdownContainer">
                    <button type="button" id="profileDropdownBtn" class="hd-profile-btn" aria-haspopup="true" aria-expanded="false" aria-controls="profileDropdownMenu" aria-label="Menu da conta de <?= e($currentUser['name'] ?? 'Admin') ?>">
                        <span class="hd-avatar"><?php if ($avatarUrl): ?><img src="<?= e($avatarUrl) ?>" alt=""><?php else: ?><?= e($userInitial) ?><?php endif; ?></span>
                        <span class="hd-profile-meta">
                            <b><?= e($currentUser['name'] ?? 'Admin') ?></b>
                            <small><?= e($roleLabel) ?></small>
                        </span>
                        <i data-lucide="chevron-down" class="hd-chev"></i>
                    </button>
                    <div id="profileDropdownMenu" class="hd-pop hd-profile" hidden>
                        <div class="hd-user">
                            <span class="hd-avatar lg"><?php if ($avatarUrl): ?><img src="<?= e($avatarUrl) ?>" alt=""><?php else: ?><?= e($userInitial) ?><?php endif; ?></span>
                            <div class="hd-user-info">
                                <b><?= e($currentUser['name'] ?? 'Admin') ?></b>
                                <span><?= e($currentUser['email'] ?? '') ?></span>
                                <span class="hd-role"><?= e($roleLabel) ?></span>
                            </div>
                        </div>
                        <nav class="hd-menu">
                            <a href="<?= ADMIN_URL ?>/profile.php"><i data-lucide="user"></i> Meu perfil</a>
                            <a href="<?= ADMIN_URL ?>/settings.php"><i data-lucide="settings"></i> Configurações</a>
                            <hr>
                            <a href="<?= ADMIN_URL ?>/logout.php" class="danger" onclick="event.preventDefault(); document.getElementById('logoutForm').submit();"><i data-lucide="log-out"></i> Sair da conta</a>
                            <form id="logoutForm" method="POST" action="<?= ADMIN_URL ?>/logout.php" hidden>
                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                            </form>
                        </nav>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Body View Area -->
        <main class="adm-main flex-1 overflow-y-auto custom-scrollbar">

<script>
(function () {
    const API = '<?= ADMIN_URL ?>/api/notifications.php';
    const EDITOR = '<?= ADMIN_URL ?>/editor.php';
    const CSRF = '<?= getCsrfToken() ?>';

    const pops = {
        notif:   { btn: document.getElementById('notifDropdownBtn'),   menu: document.getElementById('notifDropdownMenu'),   box: document.getElementById('notifDropdownContainer') },
        profile: { btn: document.getElementById('profileDropdownBtn'), menu: document.getElementById('profileDropdownMenu'), box: document.getElementById('profileDropdownContainer') }
    };

    function setOpen(key, open) {
        const p = pops[key]; if (!p.menu) return;
        p.menu.hidden = !open;
        p.btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open && key === 'notif') loadNotifDropdown();
    }
    function closeAll(except) { Object.keys(pops).forEach(k => { if (k !== except) setOpen(k, false); }); document.dispatchEvent(new CustomEvent('hd:close-search')); }
    document.addEventListener('hd:close-pops', () => closeAll('__none__'));

    Object.keys(pops).forEach(key => {
        const p = pops[key]; if (!p.btn) return;
        p.btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const willOpen = p.menu.hidden;
            closeAll(key);
            setOpen(key, willOpen);
        });
    });
    document.addEventListener('click', (e) => {
        Object.keys(pops).forEach(k => { const p = pops[k]; if (p.box && !p.box.contains(e.target)) setOpen(k, false); });
    });
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        Object.keys(pops).forEach(k => { const p = pops[k]; if (p.menu && !p.menu.hidden) { setOpen(k, false); p.btn.focus(); } });
    });

    // ----- contadores (sino + menu lateral) -----
    function setUnread(n) {
        n = parseInt(n) || 0;
        const badge = document.getElementById('notifBadge');
        if (badge) { badge.hidden = n <= 0; badge.textContent = n > 9 ? '9+' : String(n); }
        const side = document.getElementById('sidebarNotifBadge');
        if (side) { side.style.display = n > 0 ? '' : 'none'; side.textContent = String(n); }
    }

    // ----- lista -----
    function el(tag, cls, text) { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
    function state(msg, err, icon) {
        const d = el('div', 'hd-n-state' + (err ? ' err' : ''));
        if (icon) d.innerHTML = icon;
        d.appendChild(document.createTextNode(msg));
        return d;
    }
    const BELL_OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8.7 3A6 6 0 0 1 18 8c0 2.9.6 4.8 1.3 6"/><path d="M17 17H3s3-2 3-9a4.67 4.67 0 0 1 .3-1.7"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/><path d="m2 2 20 20"/></svg>';

    function renderItem(n) {
        const unread = parseInt(n.is_read) === 0;
        const hasLink = !!n.section_id;
        const item = el(hasLink ? 'a' : 'div', 'hd-n-item' + (unread ? ' unread' : ''));
        if (hasLink) {
            item.href = EDITOR + '?id=' + encodeURIComponent(n.section_id);
            if (unread) item.addEventListener('click', () => {
                try { fetch(API + '?action=mark_read', { method: 'POST', keepalive: true, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ csrf_token: CSRF, id: n.id }) }); } catch (e) {}
            });
        }
        const av = el('span', 'hd-avatar sm');
        if (n.avatar_url) { const im = document.createElement('img'); im.src = n.avatar_url; im.alt = ''; av.appendChild(im); }
        else av.textContent = (n.user_name || 'U').charAt(0).toUpperCase();

        const body = el('div', 'hd-n-body');
        const title = el('div', 'hd-n-title');
        title.append(el('b', '', n.title || ''), el('span', 'hd-n-time', n.time_ago || ''));
        body.append(title, el('p', 'hd-n-msg', n.message || ''));

        item.append(av, body);
        if (unread) item.appendChild(el('span', 'hd-n-dot'));
        return item;
    }

    async function loadNotifDropdown() {
        const list = document.getElementById('notifDropdownList');
        try {
            const res = await fetch(API + '?action=get_latest');
            const data = await res.json();
            list.replaceChildren();
            if (data.success && data.notifications.length > 0) {
                data.notifications.forEach(n => list.appendChild(renderItem(n)));
                setUnread(data.unread_count);
            } else {
                list.appendChild(state('Nenhuma notificação por enquanto.', false, BELL_OFF));
            }
        } catch (e) {
            list.replaceChildren(state('Não foi possível carregar as notificações. Tente novamente.', true));
        }
    }

    async function markAllNotificationsRead() {
        try {
            const res = await fetch(API + '?action=mark_all_read', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ csrf_token: CSRF }) });
            const data = await res.json();
            if (data.success) { setUnread(0); loadNotifDropdown(); }
        } catch (e) {}
    }
    const markBtn = document.getElementById('notifMarkAll');
    if (markBtn) markBtn.addEventListener('click', markAllNotificationsRead);

    // mantem compatibilidade com chamadas antigas em outras páginas
    window.toggleNotifDropdown = () => pops.notif.btn.click();
    window.toggleProfileDropdown = () => pops.profile.btn.click();
    window.markAllNotificationsRead = markAllNotificationsRead;
    window.escapeHtml = function (str) { if (!str) return ''; const d = document.createElement('div'); d.textContent = str; return d.innerHTML; };

    // Polling discreto a cada 30 segundos
    let lastUnreadCount = <?= (int)$unreadNotifCount ?>;
    setInterval(async () => {
        try {
            const res = await fetch(API + '?action=get_latest');
            const data = await res.json();
            if (data && data.success) {
                setUnread(data.unread_count);
                if (data.unread_count > lastUnreadCount) {
                    // Atualiza dropdown se estiver aberto
                    if (!pops.notif.menu.hidden) loadNotifDropdown();
                }
                lastUnreadCount = data.unread_count;
            }
        } catch (e) {}
    }, 30000);

    // Controle do Tema Claro / Escuro
    const themeBtn = document.getElementById('themeToggleBtn');
    const moonIcon = document.getElementById('themeMoonIcon');
    const sunIcon = document.getElementById('themeSunIcon');
    function applyTheme(isDark) {
        document.documentElement.classList.toggle('dark', isDark);
        localStorage.setItem('tunisia_cms_theme', isDark ? 'dark' : 'light');
        if (moonIcon && sunIcon) {
            moonIcon.classList.toggle('hidden', isDark);
            sunIcon.classList.toggle('hidden', !isDark);
        }
    }
    const savedTheme = localStorage.getItem('tunisia_cms_theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    applyTheme(savedTheme === 'dark');

    if (themeBtn) {
        themeBtn.addEventListener('click', () => {
            const isDark = document.documentElement.classList.contains('dark');
            applyTheme(!isDark);
        });
    }
})();
</script>

<script>
/* ===== Gaveta do menu lateral (telas < 1024px) ===== */
(function () {
    const sb = document.getElementById('adminSidebar');
    const overlay = document.getElementById('sbOverlay');
    const openBtn = document.getElementById('sbOpen');
    const closeBtn = document.getElementById('sbClose');
    if (!sb || !openBtn) return;

    function open() {
        sb.classList.add('is-open');
        overlay.hidden = false;
        requestAnimationFrame(() => overlay.classList.add('show'));
        document.body.classList.add('sb-lock');
        openBtn.setAttribute('aria-expanded', 'true');
        const first = sb.querySelector('nav a'); if (first) setTimeout(() => first.focus({ preventScroll: true }), 50);
    }
    function close(returnFocus) {
        if (!sb.classList.contains('is-open')) return;
        sb.classList.remove('is-open');
        overlay.classList.remove('show');
        setTimeout(() => { overlay.hidden = true; }, 200);
        document.body.classList.remove('sb-lock');
        openBtn.setAttribute('aria-expanded', 'false');
        if (returnFocus) openBtn.focus();
    }
    openBtn.addEventListener('click', open);
    closeBtn && closeBtn.addEventListener('click', () => close(true));
    overlay.addEventListener('click', () => close(true));
    document.addEventListener('keydown', e => { if (e.key === 'Escape') close(true); });
    window.addEventListener('resize', () => { if (window.innerWidth >= 1024) close(false); });
})();

/* ===== Busca global ===== */
(function () {
    const API = '<?= ADMIN_URL ?>/api/search.php';
    const box = document.getElementById('globalSearch');
    const input = document.getElementById('gsInput');
    const panel = document.getElementById('gsPanel');
    const clearBtn = document.getElementById('gsClear');
    if (!box || !input || !panel) return;

    let items = [], active = -1, timer = null, ctrl = null, lastQuery = null, quickCache = null;

    // ---- utilidades ----
    const fold = ch => ch.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().charAt(0) || ch.toLowerCase();
    function foldStr(str) { let o = ''; for (const ch of str) o += fold(ch); return o; }
    function el(tag, cls, text) { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
    function icon(name) { const i = document.createElement('i'); i.setAttribute('data-lucide', name); return i; }

    function highlight(text, terms) {
        const frag = document.createDocumentFragment();
        text = String(text || '');
        if (!terms.length) { frag.textContent = text; return frag; }
        const f = foldStr(text); const marks = new Array(text.length).fill(false);
        terms.forEach(t => { if (!t) return; let i = f.indexOf(t); while (i !== -1) { for (let k = i; k < i + t.length; k++) marks[k] = true; i = f.indexOf(t, i + t.length); } });
        let buf = '', on = false;
        const flush = () => { if (!buf) return; if (on) frag.appendChild(el('mark', '', buf)); else frag.appendChild(document.createTextNode(buf)); buf = ''; };
        for (let i = 0; i < text.length; i++) { if (marks[i] !== on) { flush(); on = marks[i]; } buf += text[i]; }
        flush();
        return frag;
    }

    // ---- abrir / fechar painel ----
    function openPanel() { panel.hidden = false; input.setAttribute('aria-expanded', 'true'); }
    function closePanel() { panel.hidden = true; input.setAttribute('aria-expanded', 'false'); active = -1; input.removeAttribute('aria-activedescendant'); }
    function syncUi() {
        const has = input.value.length > 0;
        box.classList.toggle('has-text', has);
        clearBtn.hidden = !has;
    }

    // ---- render ----
    function render(data, query) {
        panel.replaceChildren(); items = []; active = -1;
        const terms = foldStr(query).split(/\s+/).filter(Boolean);

        if (!data.groups.length) {
            const st = el('div', 'gs-state');
            st.append(document.createTextNode('Nada encontrado para “' + query + '”.'), document.createElement('br'),
                      document.createTextNode('Confira a grafia ou '), Object.assign(el('a', '', 'abra um chamado no Suporte'), { href: '<?= ADMIN_URL ?>/support.php#chamado' }), document.createTextNode('.'));
            panel.appendChild(st); return;
        }
        let n = 0;
        data.groups.forEach(g => {
            panel.appendChild(el('div', 'gs-label', g.label));
            g.items.forEach(it => {
                const a = el('a', 'gs-item'); a.href = it.url; a.id = 'gs-opt-' + n; a.setAttribute('role', 'option'); a.dataset.index = n;
                const ic = el('span', 'gs-ic');
                if (it.thumb) { const im = document.createElement('img'); im.src = it.thumb; im.alt = ''; im.loading = 'lazy'; im.onerror = () => { im.remove(); ic.appendChild(icon(it.icon || 'image')); if (window.lucide) lucide.createIcons(); }; ic.appendChild(im); }
                else ic.appendChild(icon(it.icon || 'file'));
                const txt = el('span', 'gs-txt'); const t = el('span', 'gs-title'); const sub = el('span', 'gs-sub');
                t.appendChild(highlight(it.title, terms)); sub.appendChild(highlight(it.subtitle, terms));
                txt.append(t, sub);
                const go = icon('corner-down-left'); go.className = 'gs-go';
                a.append(ic, txt, go);
                a.addEventListener('mousemove', () => setActive(parseInt(a.dataset.index), false));
                panel.appendChild(a); items.push(a); n++;
            });
        });
        panel.appendChild(el('div', 'gs-foot', '↑ ↓ para navegar · Enter para abrir · Esc para fechar'));
        if (window.lucide) lucide.createIcons();
        if (query) setActive(0, false);
    }
    function renderState(msg) { panel.replaceChildren(el('div', 'gs-state', msg)); items = []; active = -1; }

    function setActive(i, scroll) {
        if (!items.length) return;
        if (i < 0) i = items.length - 1; if (i >= items.length) i = 0;
        items.forEach(x => x.classList.remove('is-active'));
        active = i; items[i].classList.add('is-active');
        input.setAttribute('aria-activedescendant', items[i].id);
        if (scroll !== false) items[i].scrollIntoView({ block: 'nearest' });
    }

    // ---- busca ----
    async function run(query) {
        if (query === lastQuery && !panel.hidden) return;
        lastQuery = query;
        if (query === '' && quickCache) { render(quickCache, ''); return; }
        if (ctrl) ctrl.abort(); ctrl = new AbortController();
        if (!items.length) renderState('Buscando…');
        try {
            const res = await fetch(API + '?q=' + encodeURIComponent(query), { signal: ctrl.signal, headers: { 'Accept': 'application/json' } });
            if (res.status === 401) { renderState('Sua sessão expirou. Entre novamente para buscar.'); return; }
            const data = await res.json();
            if (!data.success) throw new Error();
            if (query === '') quickCache = data;
            if (input.value.trim() === query) render(data, query);
        } catch (e) {
            if (e.name === 'AbortError') return;
            renderState('Não foi possível buscar agora. Tente novamente.');
        }
    }

    input.addEventListener('focus', () => { document.dispatchEvent(new CustomEvent('hd:close-pops')); openPanel(); run(input.value.trim()); });
    input.addEventListener('input', () => {
        syncUi(); openPanel();
        clearTimeout(timer);
        const q = input.value.trim();
        timer = setTimeout(() => run(q), q === '' ? 0 : 180);
    });
    input.addEventListener('keydown', e => {
        if (e.key === 'ArrowDown') { e.preventDefault(); if (panel.hidden) { openPanel(); run(input.value.trim()); } else setActive(active + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
        else if (e.key === 'Enter') { const t = items[active] || items[0]; if (t) { e.preventDefault(); window.location.href = t.href; } }
        else if (e.key === 'Escape') { if (!panel.hidden) { e.preventDefault(); e.stopPropagation(); closePanel(); } if (input.value) { input.value = ''; syncUi(); lastQuery = null; } else input.blur(); }
    });
    clearBtn.addEventListener('click', () => { input.value = ''; syncUi(); lastQuery = null; input.focus(); run(''); });
    document.addEventListener('click', e => { if (!box.contains(e.target)) closePanel(); });
    document.addEventListener('hd:close-search', closePanel);

    // atalhos: "/" ou Ctrl/Cmd+K
    document.addEventListener('keydown', e => {
        const tag = (e.target.tagName || '').toLowerCase();
        const typing = tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable;
        if ((e.key === '/' && !typing && !e.ctrlKey && !e.metaKey && !e.altKey) || ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k')) {
            e.preventDefault(); input.focus(); input.select();
        }
    });

    // Se a página tiver ?q= (ex.: biblioteca de mídia), preenche a busca com o termo
    const urlQ = new URLSearchParams(location.search).get('q');
    if (urlQ && /media\.php$/.test(location.pathname)) { input.value = urlQ; syncUi(); }
})();
</script>
