<?php
/**
 * Envio de e-mail por SMTP (sem dependências externas).
 *
 * Configuração pelo .env:
 *   SMTP_HOST, SMTP_PORT (587), SMTP_SECURE (tls = STARTTLS | ssl | none),
 *   SMTP_USER, SMTP_PASS, MAIL_FROM, MAIL_FROM_NAME, SMTP_VERIFY_TLS (1)
 * Sem SMTP_HOST configurado, cai para a função mail() do PHP (depende do servidor).
 */
require_once __DIR__ . '/../config/database.php';

function mailConfig(): array {
    return [
        'host'       => trim((string)envValue('SMTP_HOST', '')),
        'port'       => (int)envValue('SMTP_PORT', '587'),
        'secure'     => strtolower(trim((string)envValue('SMTP_SECURE', 'tls'))),
        'user'       => (string)envValue('SMTP_USER', ''),
        'pass'       => (string)envValue('SMTP_PASS', ''),
        'from'       => trim((string)envValue('MAIL_FROM', '')),
        'from_name'  => trim((string)envValue('MAIL_FROM_NAME', 'Projeto Tunísia')),
        'verify_tls' => envValue('SMTP_VERIFY_TLS', '1') !== '0',
    ];
}

function mailerIsConfigured(): bool {
    return mailConfig()['host'] !== '';
}

/** Remove quebras de linha de valores de cabeçalho (impede injeção de cabeçalhos). */
function mailHeaderSafe(string $value): string {
    return trim((string)preg_replace('/[\r\n]+/', ' ', $value));
}

function mailEncodeHeader(string $value): string {
    $value = mailHeaderSafe($value);
    return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
}

/** Endereço do remetente: MAIL_FROM, ou SMTP_USER (se for e-mail), ou no-reply@domínio-do-site. */
function mailFromAddress(array $cfg): string {
    if ($cfg['from'] !== '' && filter_var($cfg['from'], FILTER_VALIDATE_EMAIL)) return $cfg['from'];
    if ($cfg['user'] !== '' && filter_var($cfg['user'], FILTER_VALIDATE_EMAIL)) return $cfg['user'];
    $host = parse_url(siteBaseUrl(), PHP_URL_HOST) ?: 'localhost';
    return 'no-reply@' . preg_replace('/[^A-Za-z0-9.\-]/', '', (string)$host);
}

/**
 * Envia um e-mail de texto simples (UTF-8).
 * @return array{ok: bool, error: string}
 */
function sendAppMail(string $to, string $subject, string $body): array {
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Endereço de destino inválido.'];
    }
    $cfg = mailConfig();
    $from = mailFromAddress($cfg);
    $fromName = mailEncodeHeader($cfg['from_name'] !== '' ? $cfg['from_name'] : 'Projeto Tunísia');
    $fromHeader = (strpos($fromName, '=?UTF-8?') === 0 ? $fromName : '"' . str_replace(['"', '\\'], '', $fromName) . '"') . ' <' . $from . '>';
    $domain = substr(strrchr($from, '@') ?: '@localhost', 1);

    $headers = [
        'Date: ' . date('r'),
        'From: ' . $fromHeader,
        'To: <' . $to . '>',
        'Subject: ' . mailEncodeHeader($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];
    $encodedBody = chunk_split(base64_encode($body), 76, "\r\n");

    if ($cfg['host'] === '') {
        // Sem SMTP: usa mail() (pode não estar configurado no servidor).
        if (!function_exists('mail')) return ['ok' => false, 'error' => 'SMTP não configurado e mail() indisponível.'];
        $extra = implode("\r\n", array_slice($headers, 1, 1)) . "\r\n" . implode("\r\n", array_slice($headers, 4));
        $ok = @mail($to, mailEncodeHeader($subject), $encodedBody, $extra);
        return $ok ? ['ok' => true, 'error' => ''] : ['ok' => false, 'error' => 'A função mail() do servidor falhou. Configure o SMTP no .env.'];
    }

    $message = implode("\r\n", $headers) . "\r\n\r\n" . $encodedBody;
    return smtpDeliver($cfg, $from, $to, $message);
}

/**
 * Agenda o envio para depois que a resposta HTTP já foi entregue ao usuário
 * (não trava a página e não revela, pelo tempo de resposta, se um e-mail existe).
 */
function queueAppMail(string $to, string $subject, string $body): void {
    register_shutdown_function(function () use ($to, $subject, $body) {
        finishResponse();
        $result = sendAppMail($to, $subject, $body);
        if (!$result['ok']) {
            error_log('Falha ao enviar e-mail: ' . $result['error']);
        }
    });
}

/** Conversa SMTP completa. Nunca registra credenciais nos erros. */
function smtpDeliver(array $cfg, string $from, string $to, string $message): array {
    $fail = function ($fp, string $msg) {
        if (is_resource($fp)) { @fwrite($fp, "QUIT\r\n"); @fclose($fp); }
        return ['ok' => false, 'error' => $msg];
    };

    $secure = in_array($cfg['secure'], ['ssl', 'tls', 'none'], true) ? $cfg['secure'] : 'tls';
    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . $cfg['port'];
    $ctx = stream_context_create(['ssl' => [
        'verify_peer'      => $cfg['verify_tls'],
        'verify_peer_name' => $cfg['verify_tls'],
        'peer_name'        => $cfg['host'],
        'SNI_enabled'      => true,
    ]]);

    $errno = 0; $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return ['ok' => false, 'error' => "Não foi possível conectar ao servidor SMTP ({$cfg['host']}:{$cfg['port']}): $errstr"];
    stream_set_timeout($fp, 15);

    // Lê uma resposta (suporta respostas em múltiplas linhas "250-...").
    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $data;
    };
    $send = function (string $command) use ($fp, $read): string {
        fwrite($fp, $command . "\r\n");
        return $read();
    };
    $code = fn(string $response): int => (int)substr($response, 0, 3);

    $hello = preg_replace('/[^A-Za-z0-9.\-]/', '', (string)(gethostname() ?: 'localhost')) ?: 'localhost';

    if ($code($read()) !== 220) return $fail($fp, 'O servidor SMTP não respondeu corretamente ao conectar.');
    if ($code($send("EHLO $hello")) !== 250) return $fail($fp, 'O servidor SMTP recusou o EHLO.');

    if ($secure === 'tls') {
        if ($code($send('STARTTLS')) !== 220) return $fail($fp, 'O servidor SMTP não aceitou STARTTLS (tente SMTP_SECURE=ssl na porta 465).');
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            return $fail($fp, 'Falha ao negociar TLS com o servidor SMTP (certificado inválido? veja SMTP_VERIFY_TLS).');
        }
        if ($code($send("EHLO $hello")) !== 250) return $fail($fp, 'O servidor SMTP recusou o EHLO após TLS.');
    }

    if ($cfg['user'] !== '') {
        if ($code($send('AUTH LOGIN')) !== 334) return $fail($fp, 'O servidor SMTP não oferece autenticação AUTH LOGIN.');
        if ($code($send(base64_encode($cfg['user']))) !== 334) return $fail($fp, 'Usuário SMTP rejeitado.');
        if ($code($send(base64_encode($cfg['pass']))) !== 235) return $fail($fp, 'Autenticação SMTP falhou: verifique SMTP_USER e SMTP_PASS.');
    }

    if ($code($send("MAIL FROM:<$from>")) !== 250) return $fail($fp, 'O servidor SMTP recusou o remetente (MAIL_FROM).');
    $rcpt = $code($send("RCPT TO:<$to>"));
    if ($rcpt !== 250 && $rcpt !== 251) return $fail($fp, 'O servidor SMTP recusou o destinatário.');
    if ($code($send('DATA')) !== 354) return $fail($fp, 'O servidor SMTP não aceitou o envio da mensagem.');

    // Normaliza quebras de linha e aplica "dot-stuffing" (linhas iniciadas por ponto).
    $payload = str_replace(["\r\n", "\r"], "\n", $message);
    $payload = str_replace("\n", "\r\n", $payload);
    $payload = preg_replace('/^\./m', '..', $payload);
    fwrite($fp, $payload . "\r\n.\r\n");
    if ($code($read()) !== 250) return $fail($fp, 'O servidor SMTP rejeitou a mensagem.');

    @fwrite($fp, "QUIT\r\n");
    @fclose($fp);
    return ['ok' => true, 'error' => ''];
}
