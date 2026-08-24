<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Single source of truth for the active WordPress connection and table names.
 *
 * WordPress table names cannot be bound as SQL parameters. Therefore the
 * configured prefix is validated once here and table suffixes are allowlisted.
 */
final class WordpressTableResolver
{
    /** @var list<string> */
    private const TABLES = [
        'options',
        'posts',
        'postmeta',
        'terms',
        'term_taxonomy',
        'term_relationships',
        'users',
        'usermeta',
    ];

    public function connection(): Connection
    {
        return DB::connection($this->connectionName());
    }

    public function connectionName(): string
    {
        $connection = trim((string) config('services.wordpress.connection', 'wordpress'));

        if ($connection === '') {
            throw new LogicException('Koneksi WordPress belum dikonfigurasi.');
        }

        return $connection;
    }

    public function table(string $suffix): string
    {
        if (!in_array($suffix, self::TABLES, true)) {
            throw new InvalidArgumentException("Tabel WordPress tidak diizinkan: {$suffix}");
        }

        return $this->prefix() . $suffix;
    }

    public function prefix(): string
    {
        $prefix = trim((string) config('services.wordpress.prefix', ''));

        if ($prefix === '' || !preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
            throw new LogicException('DB_WP_PREFIX harus berisi huruf, angka, atau underscore dan tidak boleh kosong.');
        }

        return $prefix;
    }

    public function capabilitiesMetaKey(): string
    {
        return $this->prefix() . 'capabilities';
    }
}
