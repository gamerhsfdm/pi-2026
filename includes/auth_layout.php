<?php
/** Layout simples e autônomo das telas de autenticação (recuperar/redefinir senha). */
function authPageStart(string $title, string $heading, string $subtitle = ''): void { ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?></title>
    <style>
        *{box-sizing:border-box} body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f1f5f9;font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#0f172a;padding:1rem}
        .card{background:#fff;width:100%;max-width:26rem;padding:2rem;border-radius:1.25rem;box-shadow:0 10px 30px rgba(15,23,42,.08)}
        h1{font-size:1.25rem;margin:0 0 .35rem} p.sub{margin:0 0 1.25rem;color:#64748b;font-size:.85rem;line-height:1.5}
        label{display:block;font-size:.75rem;font-weight:600;margin:0 0 .35rem;color:#334155}
        input[type=email],input[type=password]{width:100%;padding:.7rem .9rem;border:1px solid #e2e8f0;border-radius:.7rem;font-size:.9rem;background:#f8fafc;margin-bottom:1rem}
        input:focus{outline:2px solid #6366f1;border-color:transparent}
        button{width:100%;padding:.75rem;border:0;border-radius:.7rem;background:#0f172a;color:#fff;font-weight:600;font-size:.9rem;cursor:pointer}
        .msg{padding:.7rem .9rem;border-radius:.7rem;font-size:.8rem;margin-bottom:1rem;line-height:1.5}
        .err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca} .ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
        a.back{display:block;text-align:center;margin-top:1rem;font-size:.8rem;color:#64748b;text-decoration:none}
        small{display:block;color:#94a3b8;font-size:.7rem;margin:-.6rem 0 1rem}
    </style>
</head>
<body>
<main class="card">
    <h1><?= e($heading) ?></h1>
    <?php if ($subtitle !== ''): ?><p class="sub"><?= e($subtitle) ?></p><?php endif; ?>
<?php }

function authPageEnd(): void { ?>
    <a class="back" href="<?= e(ADMIN_URL) ?>/index.php">← Voltar ao login</a>
</main>
</body>
</html>
<?php }
