<?php

namespace Database\Seeders;

use App\Models\ContentDataset;
use Illuminate\Database\Seeder;
use RuntimeException;

class WebsiteUpdateV2Seeder extends Seeder
{
    /**
     * Terapkan UPDATE WEBSITE versi 2 secara eksplisit dan idempoten.
     *
     * Seeder ini sengaja tidak didaftarkan ke DatabaseSeeder agar deployment
     * aplikasi tidak mengubah konten produksi tanpa perintah operator.
     */
    public function run(): void
    {
        $update = require database_path('data/website-update-v2.php');

        $subBagian = $this->datasetData('sub-bagian', 'sub-bagian-lppm.json');
        $subBagian['metadata'] = $update['metadata'];
        $subBagian['sub_bagian'] ??= [];

        unset($subBagian['sub_bagian']['pui'], $subBagian['sub_bagian']['puslit']);
        $subBagian['sub_bagian']['pusat-lppm'] = $update['centers'];

        ContentDataset::query()->updateOrCreate(
            ['dataset_key' => 'sub-bagian'],
            ['data' => $subBagian]
        );

        $profile = $this->datasetData('profile', 'profile-lppm.json');
        $profile['metadata']['last_updated'] = $update['metadata']['last_updated'];
        $profile['struktur_organisasi']['gambar_struktur'] = '/images/struktur/struktur-organisasi-2026.png';

        ContentDataset::query()->updateOrCreate(
            ['dataset_key' => 'profile'],
            ['data' => $profile]
        );

        $this->command?->info('UPDATE WEBSITE versi 2 diterapkan ke dataset profile dan sub-bagian.');
    }

    private function datasetData(string $key, string $fallbackFilename): array
    {
        $stored = ContentDataset::query()->where('dataset_key', $key)->value('data');

        if (is_array($stored)) {
            return $stored;
        }

        if (is_string($stored)) {
            $decoded = json_decode($stored, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $path = public_path('data/'.$fallbackFilename);
        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded)) {
            throw new RuntimeException("Fallback {$fallbackFilename} tidak valid.");
        }

        return $decoded;
    }
}
