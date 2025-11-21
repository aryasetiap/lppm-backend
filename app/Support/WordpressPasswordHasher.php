<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Minimal WordPress password hasher/validator.
 *
 * WordPress uses the portable PHPass algorithm ($P$ / $H$ prefixes) to hash
 * user passwords. Framework helper classes (bcrypt / argon) tidak kompatibel,
 * jadi kita reimplementasi algoritma yang sama agar verifikasi password admin
 * berjalan mulus tanpa perlu meng-install WordPress penuh.
 */
class WordpressPasswordHasher
{
    /**
     * Karakter table yang digunakan PHPass.
     */
    private string $itoa64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * Verifikasi password plain-text terhadap hash WordPress.
     */
    public function check(string $password, string $hash): bool
    {
        if ($hash === '' || $password === '') {
            return false;
        }

        if (hash_equals($hash, $password)) {
            return true;
        }

        if (str_starts_with($hash, '$wp$')) {
            // WordPress 5.5+ menggunakan bcrypt dengan prefix khusus ($wp$).
            $bcryptHash = '$' . substr($hash, 4);

            return password_verify($password, $bcryptHash);
        }

        // Hash WordPress lama (MD5)
        if (strlen($hash) <= 32) {
            return hash_equals($hash, md5($password));
        }

        $computed = $this->cryptPortable($password, $hash);

        if ($computed === '*') {
            // Fallback ke crypt() standar bila diperlukan.
            $computed = crypt($password, $hash);
        }

        return hash_equals($hash, $computed);
    }

    /**
     * Implementasi portabel PHPass dari WordPress (disederhanakan).
     */
    private function cryptPortable(string $password, string $setting): string
    {
        if (strlen($setting) < 12 || ($setting[0] !== '$') || ($setting[1] !== 'P' && $setting[1] !== 'H')) {
            return '*';
        }

        $countLog2 = strpos($this->itoa64, $setting[3]);

        if ($countLog2 < 7 || $countLog2 > 30) {
            return '*';
        }

        $count = 1 << $countLog2;
        $salt = substr($setting, 4, 8);

        if (strlen($salt) !== 8) {
            return '*';
        }

        $hash = md5($salt . $password, true);

        do {
            $hash = md5($hash . $password, true);
        } while (--$count);

        return substr($setting, 0, 12) . $this->encode64($hash, 16);
    }

    /**
     * Helper encoder base64 kustom milik PHPass.
     */
    private function encode64(string $input, int $count): string
    {
        $output = '';
        $i = 0;

        do {
            $value = ord($input[$i++]);
            $output .= $this->itoa64[$value & 0x3f];

            if ($i < $count) {
                $value |= ord($input[$i]) << 8;
            }

            $output .= $this->itoa64[($value >> 6) & 0x3f];

            if ($i++ >= $count) {
                break;
            }

            if ($i < $count) {
                $value |= ord($input[$i]) << 16;
            }

            $output .= $this->itoa64[($value >> 12) & 0x3f];

            if ($i++ >= $count) {
                break;
            }

            $output .= $this->itoa64[($value >> 18) & 0x3f];
        } while ($i < $count);

        return $output;
    }
}

