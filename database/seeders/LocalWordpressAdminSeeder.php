<?php

namespace Database\Seeders;

use App\Support\WordpressTableResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Creates or refreshes one local-only administrator in the WordPress tables.
 *
 * This seeder is intentionally blocked outside APP_ENV=local. It is a test
 * account for the Laravel admin login, not a production user-management tool.
 */
class LocalWordpressAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment('local')) {
            throw new RuntimeException('LocalWordpressAdminSeeder hanya boleh dijalankan saat APP_ENV=local.');
        }

        $username = trim((string) env('LOCAL_ADMIN_USERNAME', ''));
        $email = trim((string) env('LOCAL_ADMIN_EMAIL', ''));
        $password = (string) env('LOCAL_ADMIN_PASSWORD', '');

        if (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $username)) {
            throw new InvalidArgumentException('LOCAL_ADMIN_USERNAME harus 3-60 karakter: huruf, angka, titik, garis bawah, atau strip.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('LOCAL_ADMIN_EMAIL tidak valid.');
        }

        if (strlen($password) < 12) {
            throw new InvalidArgumentException('LOCAL_ADMIN_PASSWORD minimal 12 karakter.');
        }

        $tables = app(WordpressTableResolver::class);
        $connection = $tables->connection();
        $usersTable = $tables->table('users');
        $usermetaTable = $tables->table('usermeta');

        $connection->transaction(function () use ($connection, $tables, $usersTable, $usermetaTable, $username, $email, $password): void {
            $existingByUsername = $connection->table($usersTable)
                ->where('user_login', $username)
                ->first();
            $existingByEmail = $connection->table($usersTable)
                ->where('user_email', $email)
                ->first();

            if ($existingByEmail !== null && ($existingByUsername === null || $existingByEmail->ID !== $existingByUsername->ID)) {
                throw new RuntimeException('LOCAL_ADMIN_EMAIL sudah digunakan akun WordPress lain; seeder dibatalkan agar tidak mengambil alih akun.');
            }

            $now = now()->format('Y-m-d H:i:s');
            // WordPress modern wraps bcrypt as "$wp$2y$...". The existing
            // password verifier supports this format without WordPress PHP.
            $wordpressHash = '$wp$' . substr(password_hash($password, PASSWORD_BCRYPT), 1);
            $userData = [
                'user_pass' => $wordpressHash,
                'user_nicename' => Str::slug($username),
                'user_email' => $email,
                'user_url' => '',
                'user_registered' => $now,
                'user_activation_key' => '',
                'user_status' => 0,
                'display_name' => 'Administrator Lokal LPPM',
            ];

            if ($existingByUsername !== null) {
                $connection->table($usersTable)
                    ->where('ID', $existingByUsername->ID)
                    ->update($userData);
                $userId = (int) $existingByUsername->ID;
            } else {
                $userId = (int) $connection->table($usersTable)->insertGetId([
                    'user_login' => $username,
                    ...$userData,
                ]);
            }

            $connection->table($usermetaTable)->updateOrInsert(
                ['user_id' => $userId, 'meta_key' => $tables->capabilitiesMetaKey()],
                ['meta_value' => serialize(['administrator' => true])]
            );
            $connection->table($usermetaTable)->updateOrInsert(
                ['user_id' => $userId, 'meta_key' => $tables->prefix() . 'user_level'],
                ['meta_value' => '10']
            );
        });

        $this->command?->info("Admin WordPress lokal siap untuk {$username}. Password tidak ditampilkan.");
    }
}
