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

function mailPlain(array $headers, string $body, int $depth = 0): ?string
{
    $type = strtolower($headers['content-type'] ?? 'text/plain');
    if ($depth < 4 && str_starts_with($type, 'multipart/') && preg_match('/boundary="?([^";]+)"?/i', $headers['content-type'], $match)) {
        foreach (explode('--' . $match[1], $body) as $part) {
            [$partHeaders, $partBody] = mailSplit(ltrim($part, "\n"));
            if ($partHeaders === []) continue;
            $text = mailPlain($partHeaders, $partBody, $depth + 1);
            if ($text !== null) return $text;
        }
        return null;
    }
    if (!str_starts_with($type, 'text/plain')) return null;
    $encoding = strtolower(trim($headers['content-transfer-encoding'] ?? ''));
    if ($encoding === 'quoted-printable') return quoted_printable_decode($body);
    if ($encoding === 'base64') return (string) base64_decode($body);
    return $body;
}

foreach (glob(rtrim($argv[1], '/') . '/*.eml') as $file) {
    $target = substr($file, 0, -4) . '.txt';
    if (is_link($file) || file_exists($target) || filesize($file) > 1048576) continue;
    [$headers, $body] = mailSplit((string) file_get_contents($file));
    $text = mailPlain($headers, $body);
    if ($text === null) continue;
    $out = '';
    foreach (['from' => 'From', 'to' => 'To', 'subject' => 'Subject', 'date' => 'Date'] as $key => $label) {
        $value = $headers[$key] ?? '';
        $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        $out .= $label . ': ' . ($decoded === false ? $value : $decoded) . "\n";
    }
    file_put_contents($target, $out . "\n" . rtrim($text, "\n") . "\n");
}
