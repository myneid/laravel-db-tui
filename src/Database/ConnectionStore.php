<?php

namespace Myneid\LaravelDbTui\Database;

/**
 * Persists named connection URLs in ~/.laravel-db-tui.json.
 * Lives in the home directory so it is never inside a project repo.
 */
class ConnectionStore
{
    private static function path(): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: getenv('USERPROFILE') ?: '/tmp';
        return rtrim($home, '/') . '/.laravel-db-tui.json';
    }

    public static function save(string $name, string $url): void
    {
        $store        = self::all();
        $store[$name] = $url;
        file_put_contents(self::path(), json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    public static function get(string $name): ?string
    {
        return self::all()[$name] ?? null;
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        $path = self::path();
        if (!file_exists($path)) {
            return [];
        }
        return json_decode(file_get_contents($path), true) ?? [];
    }

    public static function delete(string $name): bool
    {
        $store = self::all();
        if (!array_key_exists($name, $store)) {
            return false;
        }
        unset($store[$name]);
        file_put_contents(self::path(), json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return true;
    }

    public static function storePath(): string
    {
        return self::path();
    }
}
