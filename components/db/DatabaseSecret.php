<?php
namespace app\components\db;

/** Reads a mounted, private single-line Docker secret; never include its value in errors. */
final class DatabaseSecret
{
    public static function read(string $path): string
    {
        $stat = @lstat($path);
        if (!preg_match('#^/run/secrets/[A-Za-z0-9_-]+$#D', $path) || !$stat || ($stat['mode'] & 0170000) !== 0100000
            || ($stat['mode'] & 0077) !== 0 || $stat['size'] < 32 || $stat['size'] > 258) throw new \RuntimeException('invalid_database_secret');
        $raw = @file_get_contents($path);
        if (!is_string($raw)) throw new \RuntimeException('invalid_database_secret');
        $value = preg_replace('/\r?\n\z/', '', $raw);
        if (strlen($value) < 32 || strlen($value) > 256 || strpbrk($value, "\r\n\0") !== false) throw new \RuntimeException('invalid_database_secret');
        return $value;
    }
}
