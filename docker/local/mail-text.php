<?php
// Versione leggibile delle mail su file della sandbox: intestazioni principali e testo semplice decodificato
// (quoted-printable/base64), così i link restano interi. Crea <nome>.txt accanto a ogni .eml, mai sovrascritto.
if (PHP_SAPI !== 'cli' || $argc !== 2 || !is_dir($argv[1])) exit(1);
umask(077);

/** @return array{0: array<string,string>, 1: string} intestazioni (nome minuscolo => valore, righe unite) e corpo */
function mailSplit(string $raw): array
{
    $raw = str_replace("\r\n", "\n", $raw);
    [$head, $body] = array_pad(explode("\n\n", $raw, 2), 2, '');
    $headers = [];
    foreach (explode("\n", preg_replace("/\n[ \t]+/", ' ', $head)) as $line) {
        if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $match)) $headers[strtolower($match[1])] ??= $match[2];
    }
    return [$headers, $body];
}

/** Prima parte del tipo indicato (text/plain o text/html), decodificata; null se assente. */
function mailPart(array $headers, string $body, string $wanted, int $depth = 0): ?string
{
    $type = strtolower($headers['content-type'] ?? 'text/plain');
    if ($depth < 4 && str_starts_with($type, 'multipart/') && preg_match('/boundary="?([^";]+)"?/i', $headers['content-type'], $match)) {
        foreach (explode('--' . $match[1], $body) as $part) {
            [$partHeaders, $partBody] = mailSplit(ltrim($part, "\n"));
            if ($partHeaders === []) continue;
            $text = mailPart($partHeaders, $partBody, $wanted, $depth + 1);
            if ($text !== null) return $text;
        }
        return null;
    }
    if (!str_starts_with($type, $wanted)) return null;
    $encoding = strtolower(trim($headers['content-transfer-encoding'] ?? ''));
    if ($encoding === 'quoted-printable') return quoted_printable_decode($body);
    if ($encoding === 'base64') return (string) base64_decode($body);
    return $body;
}

foreach (glob(rtrim($argv[1], '/') . '/*.eml') as $file) {
    $target = substr($file, 0, -4) . '.txt';
    if (is_link($file) || file_exists($target) || filesize($file) > 1048576) continue;
    [$headers, $body] = mailSplit((string) file_get_contents($file));
    $text = mailPart($headers, $body, 'text/plain');
    $html = mailPart($headers, $body, 'text/html');
    if ($text === null && $html === null) continue;
    $text ??= trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    // link dei pulsanti HTML che la parte di testo non riporta
    if ($html !== null && preg_match_all('~href="(https?://[^"]+)"~i', $html, $links)) {
        $extra = array_values(array_filter(array_unique(array_map(static fn($link) => html_entity_decode($link, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $links[1])),
            static fn($link) => !str_contains($text, $link)));
        if ($extra !== []) $text = rtrim($text, "\n") . "\n\nLink:\n" . implode("\n", $extra) . "\n";
    }
    $out = '';
    foreach (['from' => 'From', 'to' => 'To', 'subject' => 'Subject', 'date' => 'Date'] as $key => $label) {
        $value = $headers[$key] ?? '';
        $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        $out .= $label . ': ' . ($decoded === false ? $value : $decoded) . "\n";
    }
    file_put_contents($target, $out . "\n" . rtrim($text, "\n") . "\n");
}
