<?php

namespace app\components\mail;

use Yii;

/**
 * Posta in uscita salvata su file mentre l'SMTP non è disponibile: sola lettura per la pagina admin "Posta in uscita".
 * Fonti fisse lato server, mai dal client: la posta di Napay (mailer su file) e, se montata in sola lettura, quella del
 * wallet (NAPAY_WALLET_MAIL_DIR + NAPAY_WALLET_URL). Mostrati solo i link HTTPS verso il dominio della fonte.
 */
final class Outbox
{
    public const LIMIT = 50;
    private const MAX_BYTES = 524288;
    private const MAX_PARTS = 20;
    private const MAX_DEPTH = 3;

    /** @return array<string, array{dir: string, host: string}> fonti attive: 'napay' e/o 'wallet' */
    public static function sources(): array
    {
        $sources = [];
        $mailer = Yii::$app->mailer;
        $host = self::host((string) (Yii::$app->params['webapp_link'] ?? ''));
        if ($mailer->useFileTransport && $host !== null) {
            $sources['napay'] = ['dir' => Yii::getAlias($mailer->fileTransportPath), 'host' => $host];
        }
        $dir = (string) getenv('NAPAY_WALLET_MAIL_DIR');
        $walletHost = self::host((string) getenv('NAPAY_WALLET_URL'));
        if ($dir !== '' && $dir[0] === '/' && is_dir($dir) && $walletHost !== null) {
            $sources['wallet'] = ['dir' => $dir, 'host' => $walletHost];
        }
        return $sources;
    }

    /**
     * Ultimi messaggi della fonte, dal più recente.
     * @param array{dir: string, host: string} $source
     * @return list<array{time: int, to: string, subject: string, links: list<string>, readable: bool}>
     */
    public static function messages(array $source): array
    {
        $files = [];
        foreach (glob(rtrim($source['dir'], '/') . '/*.eml', GLOB_NOSORT) ?: [] as $file) {
            if (is_file($file) && !is_link($file)) $files[$file] = (int) filemtime($file);
        }
        arsort($files);
        $messages = [];
        foreach (array_slice($files, 0, self::LIMIT, true) as $file => $time) {
            $size = filesize($file);
            $raw = $size !== false && $size <= self::MAX_BYTES ? @file_get_contents($file) : false;
            $messages[] = ['time' => $time] + (is_string($raw)
                ? self::parse($raw, $source['host']) + ['readable' => true]
                : ['to' => '', 'subject' => '', 'links' => [], 'readable' => false]);
        }
        return $messages;
    }

    /** @return array{to: string, subject: string, links: list<string>} */
    public static function parse(string $raw, string $host): array
    {
        [$head, $body] = self::split($raw);
        $headers = self::headers($head);
        $texts = [];
        self::collect($headers, $body, $texts, 0);
        $links = [];
        foreach ($texts as $text) {
            if (!preg_match_all('~https://[^\s"\'<>]+~i', $text, $found)) continue;
            foreach ($found[0] as $url) {
                $url = rtrim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'), '.,;)');
                if (self::allowed($url, $host)) $links[$url] = true;
            }
        }
        return ['to' => self::decode($headers['to'] ?? ''), 'subject' => self::decode($headers['subject'] ?? ''), 'links' => array_keys($links)];
    }

    private static function host(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return null;
        return strtolower($parts['host']);
    }

    private static function allowed(string $url, string $host): bool
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) return false;
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || strtolower($parts['host'] ?? '') !== $host
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) return false;
        // risorse grafiche dei template, non link da girare al destinatario
        return !preg_match('/\.(png|jpe?g|gif|svg|ico|webp|css|js)$/i', $parts['path'] ?? '');
    }

    /** @return array{0: string, 1: string} intestazioni e corpo */
    private static function split(string $raw): array
    {
        $parts = preg_split('/\r?\n\r?\n/', $raw, 2);
        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    /** @return array<string, string> prima occorrenza di ogni intestazione, nome in minuscolo */
    private static function headers(string $head): array
    {
        $headers = [];
        foreach (preg_split('/\r?\n/', (string) preg_replace('/\r?\n[ \t]+/', ' ', $head)) as $line) {
            if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $m) && !isset($headers[strtolower($m[1])])) {
                $headers[strtolower($m[1])] = trim($m[2]);
            }
        }
        return $headers;
    }

    /** Testi (plain e HTML) decodificati, anche dentro multipart annidati entro MAX_DEPTH. */
    private static function collect(array $headers, string $body, array &$texts, int $depth): void
    {
        $type = $headers['content-type'] ?? 'text/plain';
        if (stripos($type, 'multipart/') === 0) {
            if ($depth >= self::MAX_DEPTH || !preg_match('/boundary="?([^";\s]+)"?/i', $type, $m)) return;
            $parts = explode('--' . $m[1], $body);
            foreach (array_slice($parts, 1, self::MAX_PARTS) as $part) {
                if (str_starts_with($part, '--')) break;
                [$head, $content] = self::split(ltrim($part, "\r\n"));
                self::collect(self::headers($head), $content, $texts, $depth + 1);
            }
            return;
        }
        if (stripos($type, 'text/plain') !== 0 && stripos($type, 'text/html') !== 0) return;
        $encoding = strtolower($headers['content-transfer-encoding'] ?? '');
        $texts[] = match ($encoding) {
            'base64' => (string) base64_decode((string) preg_replace('/\s+/', '', $body)),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    private static function decode(string $value): string
    {
        $decoded = mb_decode_mimeheader($value);
        return mb_check_encoding($decoded, 'UTF-8') ? mb_substr($decoded, 0, 300) : '';
    }
}
