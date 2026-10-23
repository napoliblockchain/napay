<?php

/**
 * Configurazione da variabili d'ambiente (.env nella root del progetto).
 * Le variabili gia' presenti nell'ambiente (Docker env_file, CI) hanno precedenza.
 */

$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key)) {
            throw new \RuntimeException('Invalid environment variable name in .env');
        }
        if (getenv($key) !== false || array_key_exists($key, $_ENV)) {
            continue;
        }

        $value = trim($value);
        $length = strlen($value);
        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}

if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
        return array_key_exists($key, $_ENV) ? $_ENV[$key] : $default;
    }
}

if (!function_exists('env_bool')) {
    function env_bool(string $key, bool $default = false): bool
    {
        $value = env($key);
        if ($value === null) {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new \RuntimeException("Invalid boolean environment variable: {$key}");
        }
        return $parsed;
    }
}

if (!function_exists('env_required')) {
    /**
     * Fail secure: senza il valore l'applicazione non parte.
     */
    function env_required(string $key): string
    {
        $value = env($key);
        if (!is_string($value) || trim($value) === '') {
            throw new \RuntimeException("Variabile d'ambiente obbligatoria mancante: {$key} (vedi .env.example)");
        }

        return $value;
    }
}

$environment = env('APP_ENV', 'prod');
if (!in_array($environment, ['prod', 'dev', 'test'], true)) {
    throw new \RuntimeException('Invalid APP_ENV: use prod, dev or test');
}
defined('YII_ENV') or define('YII_ENV', $environment);
defined('YII_DEBUG') or define('YII_DEBUG', env_bool('APP_DEBUG', false));
