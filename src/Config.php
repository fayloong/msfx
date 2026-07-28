<?php

namespace App;

class Config
{
    private static array $config = [];

    public static function load(string $envPath = null): void
    {
        $envPath = $envPath ?? __DIR__ . '/../config/.env';
        if (!file_exists($envPath)) {
            return;
        }
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            self::$config[$key] = $value;
            if (!defined($key)) {
                define($key, $value);
            }
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        return self::$config[$key] ?? $default;
    }
}
