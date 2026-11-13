<?php
require_once __DIR__ . '/crm.php';
/**
 * Уведомления о новой заявке: письмо (SMTP Яндекса) и/или Telegram.
 * Канал работает, если в config.php заполнены его поля (см. config.example.php).
 * Ошибки отправки пишутся в журнал ошибок PHP и никогда не показываются посетителю:
 * заявка к этому моменту уже сохранена в базе.
 */

/**
 * $row: num, name, phone, email, org, comment, items (app_items), items_total, items_weight_kg,
 *       spec_filename, attachment_url, admin_url,
 *       client: [kind_label, seq, count, total, url] или null.
 * Возвращает, что получилось по каналам: ['почта' => true|false, 'Telegram' => ...] (только включённые).
 */
function notify_application(array $cfg, array $row): array {
    $res = [];
    if (!empty($cfg['notify_email_to']) && !empty($cfg['smtp_user']) && !empty($cfg['smtp_pass'])) {
        try {
            notify_email($cfg, $row);
            $res['почта'] = true;
        } catch (Throwable $e) {
            error_log('notify email: ' . $e->getMessage());
            $res['почта'] = false;
        }
    }
    if (!empty($cfg['telegram_bot_token']) && !empty($cfg['telegram_chat_id'])) {
        try {
            notify_telegram($cfg, $row);
            $res['мессенджер'] = true;
        } catch (Throwable $e) {
            error_log('notify telegram: ' . $e->getMessage());
            $res['мессенджер'] = false;
        }
    }
    return $res;
}

/** Убирает переводы строк и управляющие символы из однострочных полей. */
function notify_clean(string $s, int $max = 500): string {
    $s = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $s) ?? '';
    return mb_substr(trim($s), 0, $max);
}

/** «Заявка № 48 · постоянный клиент (6-я)» */
function notify_title(array $row): string {
    $t = (int)$row['num'] > 0 ? 'Заявка № ' . (int)$row['num'] : 'Заявка с сайта';
    if (!empty($row['client'])) {
        $c = $row['client'];
        $t .= ' · ' . ($c['seq'] > 1 ? $c['kind_label'] . ' клиент (' . $c['seq'] . '-я)' : 'новый клиент');
    }
    return $t;
}

function notify_who(array $row): string {
    $who = notify_clean($row['name'], 200);
    if (($row['org'] ?? '') !== '') $who .= ', ' . notify_clean($row['org'], 200);
    return $who;
}

function notify_summary(array $row): string {
    $n = count($row['items']);
    return 'Позиции (' . $n . ') · ' . fmt_weight($row['items_weight_kg']) . ' · ' . fmt_rub($row['items_total']);
}

/** Текст без разметки — для письма (часть text/plain). */
function notify_plain(array $row): string {
    $l = [notify_title($row), '', 'Клиент: ' . notify_who($row), 'Телефон: ' . notify_clean($row['phone'], 100)];
    if ($row['email'] !== '') $l[] = 'Почта: ' . notify_clean($row['email'], 200);
    if ($row['items']) {
        $l[] = '';
        $l[] = notify_summary($row);
        foreach ($row['items'] as $k => $i) {
            $l[] = ($k + 1) . '. ' . notify_clean($i['label'], 300) . ' — ' . $i['qty_text'] . ' · ' . fmt_weight($i['weight_kg']) . ' · ' . fmt_rub($i['price']);
            if ($i['cut'] !== '') $l[] = '    ↳ резка: ' . notify_clean($i['cut']);
        }
    }
    $comment = mb_substr(trim((string)$row['comment']), 0, 3000);
    if ($comment !== '') { $l[] = ''; $l[] = 'Комментарий: ' . $comment; }
    if ($row['attachment_url'] !== '') {
        $l[] = '';
        $l[] = 'Файл: ' . notify_clean($row['spec_filename'] ?: 'файл', 200) . ' — ' . $row['attachment_url'];
    }
    $l[] = '';
    $l[] = 'Открыть заявку: ' . $row['admin_url'];
    if (!empty($row['client']) && $row['client']['count'] > 1) {
        $l[] = 'Клиент: ' . $row['client']['count'] . ' заявок на ' . fmt_rub($row['client']['total']) . ' — ' . $row['client']['url'];
    }
    return implode("\n", $l);
}

/** Текст сообщения Telegram (HTML-разметка Telegram). */
function notify_telegram_text(array $row): string {
    $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $icon = ['reg' => '⭐', 'rep' => '🔁'][$row['client']['kind'] ?? ''] ?? '🆕';
    if (!empty($row['client']) && $row['client']['seq'] <= 1) $icon = '🆕';

    $t = [];
    $t[] = $icon . ' <b>' . $e(notify_title($row)) . '</b>';
    $t[] = '';
    $t[] = '👤 <b>' . $e(notify_clean($row['name'], 200)) . '</b>' . (($row['org'] ?? '') !== '' ? ', ' . $e(notify_clean($row['org'], 200)) : '');
    $t[] = '📞 <b>' . $e(notify_clean($row['phone'], 100)) . '</b>';
    if ($row['email'] !== '') $t[] = '✉️ ' . $e(notify_clean($row['email'], 200));

    // Сообщение Telegram — до 4096 символов: длинный список обрезаем по позициям, а не посреди тега
    $tail = [];
    $comment = mb_substr(trim((string)$row['comment']), 0, 1500);
    if ($comment !== '') { $tail[] = ''; $tail[] = '💬 ' . $e($comment); }
    if ($row['attachment_url'] !== '') {
        $tail[] = '📎 <a href="' . $e($row['attachment_url']) . '">' . $e(notify_clean($row['spec_filename'] ?: 'файл', 200)) . '</a>';
    }
    $tail[] = '';
    $links = '<a href="' . $e($row['admin_url']) . '">Открыть заявку в админке</a>';
    if (!empty($row['client']) && $row['client']['count'] > 1) {
        $links .= ' · <a href="' . $e($row['client']['url']) . '">Клиент: ' . $row['client']['count'] . ' заявок на ' . $e(fmt_rub($row['client']['total'])) . '</a>';
    }
    $tail[] = $links;

    if ($row['items']) {
        $t[] = '';
        $t[] = '<b>' . $e(notify_summary($row)) . '</b>';
        $budget = 3900 - mb_strlen(implode("\n", array_merge($t, $tail)));
        foreach ($row['items'] as $k => $i) {
            $line = ($k + 1) . '. ' . $e(notify_clean($i['label'], 300)) . ' — ' . $e($i['qty_short']) . ' · ' . $e(fmt_rub($i['price']));
            if ($i['cut'] !== '') $line .= "\n    ↳ резка: " . $e(notify_clean($i['cut'], 300));
            $budget -= mb_strlen($line) + 1;
            if ($budget < 60) {
                $t[] = '… ещё ' . (count($row['items']) - $k) . ' поз. — в админке';
                break;
            }
            $t[] = $line;
        }
    }
    return implode("\n", array_merge($t, $tail));
}

function notify_telegram(array $cfg, array $row): void {
    $msg = notify_telegram_text($row);
    $ch = curl_init('https://api.telegram.org/bot' . $cfg['telegram_bot_token'] . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_POSTFIELDS     => http_build_query([
            'chat_id'    => $cfg['telegram_chat_id'],
            'text'       => $msg,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => 'true',
        ]),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        throw new RuntimeException("HTTP $code " . substr((string)$resp, 0, 200));
    }
}

/** Письмо в HTML: таблица позиций, кнопка «Открыть заявку». Стили — только inline (почтовые клиенты). */
function notify_html(array $row): string {
    $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $td = 'padding:8px 10px;border-bottom:1px solid #eee;font-size:14px;color:#222;vertical-align:top;';
    $th = 'padding:8px 10px;border-bottom:1px solid #e5e5e5;background:#fafafa;font-size:11px;color:#777;text-transform:uppercase;letter-spacing:.04em;text-align:left;';
    $kv = fn(string $k, string $v) => '<tr><td style="padding:3px 16px 3px 0;color:#777;font-size:14px;">' . $k . '</td><td style="padding:3px 0;font-size:14px;color:#222;">' . $v . '</td></tr>';

    $badge = '';
    if (!empty($row['client'])) {
        $c = $row['client'];
        $txt = $c['seq'] > 1 ? $c['kind_label'] . ' клиент · ' . $c['seq'] . '-я заявка' : 'новый клиент';
        $badge = '<div style="margin-top:6px;"><span style="display:inline-block;padding:2px 10px;border-radius:20px;background:#3a2a52;color:#d9c2ff;font-size:12px;font-weight:bold;">' . $e($txt) . '</span></div>';
    }

    $h = '<!doctype html><html><body style="margin:0;padding:0;background:#f2f2f2;">'
       . '<div style="max-width:680px;margin:0 auto;padding:16px;font-family:Arial,Helvetica,sans-serif;">'
       . '<div style="background:#15171C;color:#fff;padding:16px 20px;border-radius:8px 8px 0 0;">'
       . '<div style="font-size:12px;color:#A7ACB8;">unit-prom.ru · ' . $e(date('d.m.Y H:i')) . '</div>'
       . '<div style="font-size:22px;font-weight:bold;margin-top:4px;">' . $e(notify_title(['num' => $row['num']])) . '</div>' . $badge . '</div>'
       . '<div style="background:#fff;padding:18px 20px;border-radius:0 0 8px 8px;">'
       . '<table cellpadding="0" cellspacing="0" style="margin-bottom:16px;">'
       . $kv('Клиент', '<b>' . $e(notify_who($row)) . '</b>')
       . $kv('Телефон', '<b><a href="tel:' . $e(preg_replace('/[^\d+]/', '', $row['phone'])) . '" style="color:#222;text-decoration:none;">' . $e(notify_clean($row['phone'], 100)) . '</a></b>')
       . ($row['email'] !== '' ? $kv('Почта', '<a href="mailto:' . $e($row['email']) . '" style="color:#F2611B;">' . $e(notify_clean($row['email'], 200)) . '</a>') : '')
       . '</table>';

    if ($row['items']) {
        $h .= '<table cellpadding="0" cellspacing="0" width="100%" style="border:1px solid #e5e5e5;border-collapse:collapse;">'
            . '<tr><th style="' . $th . '">Товар</th><th style="' . $th . '">Кол-во</th><th style="' . $th . 'text-align:right;">Вес</th><th style="' . $th . 'text-align:right;">Сумма</th></tr>';
        foreach ($row['items'] as $i) {
            $h .= '<tr><td style="' . $td . '">' . $e($i['label'])
                . ($i['cut'] !== '' ? '<div style="color:#777;font-size:12px;margin-top:2px;">↳ резка: ' . $e($i['cut']) . '</div>' : '')
                . '</td><td style="' . $td . 'white-space:nowrap;">' . $e($i['qty_text']) . '</td>'
                . '<td style="' . $td . 'text-align:right;white-space:nowrap;">' . $e(fmt_weight($i['weight_kg'])) . '</td>'
                . '<td style="' . $td . 'text-align:right;white-space:nowrap;">' . $e(fmt_rub($i['price'])) . '</td></tr>';
        }
        $h .= '<tr><td colspan="2" style="' . $td . 'border-bottom:none;"><b>Итого, предварительно</b></td>'
            . '<td style="' . $td . 'border-bottom:none;text-align:right;white-space:nowrap;"><b>' . $e(fmt_weight($row['items_weight_kg'])) . '</b></td>'
            . '<td style="' . $td . 'border-bottom:none;text-align:right;white-space:nowrap;"><b>' . $e(fmt_rub($row['items_total'])) . '</b></td></tr></table>';
    }
    $comment = mb_substr(trim((string)$row['comment']), 0, 3000);
    if ($comment !== '') {
        $h .= '<p style="font-size:14px;color:#222;margin:16px 0 0;"><b>Комментарий:</b> ' . nl2br($e($comment)) . '</p>';
    }
    if ($row['attachment_url'] !== '') {
        $h .= '<p style="font-size:14px;margin:12px 0 0;">📎 <a href="' . $e($row['attachment_url']) . '" style="color:#F2611B;">' . $e(notify_clean($row['spec_filename'] ?: 'файл', 200)) . '</a> <span style="color:#999;font-size:12px;">(после входа в админку)</span></p>';
    }
    $h .= '<p style="margin:20px 0 0;"><a href="' . $e($row['admin_url']) . '" style="display:inline-block;background:#F2611B;color:#fff;padding:10px 18px;border-radius:6px;font-weight:bold;text-decoration:none;font-size:14px;">Открыть заявку</a>';
    if (!empty($row['client']) && $row['client']['count'] > 1) {
        $h .= ' &nbsp; <a href="' . $e($row['client']['url']) . '" style="color:#F2611B;font-size:13px;">Клиент: ' . $row['client']['count'] . ' заявок на ' . $e(fmt_rub($row['client']['total'])) . '</a>';
    }
    $h .= '</p>';
    if ($row['email'] !== '') {
        $h .= '<p style="color:#999;font-size:12px;margin:14px 0 0;">«Ответить» в почте пишет клиенту.</p>';
    }
    $h .= '</div></div></body></html>';
    return $h;
}

/** Заголовок письма в UTF-8 (RFC 2047). */
function notify_mime_header(string $s): string {
    return mb_encode_mimeheader($s, 'UTF-8', 'B', "\r\n");
}

function notify_email(array $cfg, array $row): void {
    $from = trim($cfg['smtp_user']);
    $to = array_values(array_filter(array_map('trim', explode(',', (string)$cfg['notify_email_to'])),
        fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL)));
    if (!$to || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('bad notify_email_to / smtp_user');
    }

    // Тема: «Заявка № 48 · Сергей Иванов, ООО «СтройМонтаж» · 196 400 ₽»
    $subject = notify_title(['num' => $row['num']]) . ' · ' . mb_substr(notify_who($row), 0, 120);
    if ($row['items_total'] !== null) $subject .= ' · ' . fmt_rub($row['items_total']);
    $boundary = 'b' . bin2hex(random_bytes(12));
    $headers = [
        'Date: ' . date('r'),
        'From: ' . notify_mime_header('Сайт unit-prom.ru') . " <$from>",
        'To: ' . implode(', ', $to),
        'Subject: ' . notify_mime_header($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@unit-prom.ru>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    // «Ответить» в почте сразу пишет клиенту, если он указал почту
    if ($row['email'] !== '' && filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $row['email'];
    }
    $part = fn(string $type, string $body) => "--$boundary\r\n"
        . "Content-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . rtrim(chunk_split(base64_encode($body), 76, "\r\n")) . "\r\n";
    $data = implode("\r\n", $headers) . "\r\n\r\n"
        . $part('text/plain', notify_plain($row))
        . $part('text/html', notify_html($row))
        . "--$boundary--";

    smtp_send($cfg, $from, $to, $data);
}

/** Минимальный SMTP-клиент: SSL на 465 или STARTTLS на 587, AUTH LOGIN. */
function smtp_send(array $cfg, string $from, array $to, string $data): void {
    $host = $cfg['smtp_host'] ?? 'smtp.yandex.ru';
    $port = (int)($cfg['smtp_port'] ?? 465);
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $remote = ($port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException("connect $remote: $errstr ($errno)");
    stream_set_timeout($fp, 10);

    $read = function () use ($fp): string {
        $resp = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $resp .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $resp;
    };
    $cmd = function (?string $c, array $ok) use ($fp, $read): string {
        if ($c !== null) fwrite($fp, $c . "\r\n");
        $resp = $read();
        if (!in_array((int)substr($resp, 0, 3), $ok, true)) {
            $shown = ($c !== null && str_starts_with($c, 'AUTH')) ? 'AUTH' : (string)$c;
            throw new RuntimeException("SMTP '$shown': " . trim($resp));
        }
        return $resp;
    };

    try {
        $cmd(null, [220]);
        $cmd('EHLO unit-prom.ru', [250]);
        if ($port !== 465) {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS failed');
            }
            $cmd('EHLO unit-prom.ru', [250]);
        }
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($cfg['smtp_user']), [334]);
        $cmd(base64_encode($cfg['smtp_pass']), [235]);
        $cmd("MAIL FROM:<$from>", [250]);
        foreach ($to as $addr) $cmd("RCPT TO:<$addr>", [250, 251]);
        $cmd('DATA', [354]);
        $cmd($data . "\r\n.", [250]);
        $cmd('QUIT', [221]);
    } finally {
        fclose($fp);
    }
}
