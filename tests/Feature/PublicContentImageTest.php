<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

class PublicContentImageTest extends TestCase
{
    public function test_uploaded_content_image_is_served_through_api_route(): void
    {
        $folder = 'test-'.Str::lower(Str::random(8));
        $directory = public_path('images/uploads/'.$folder);
        $filename = 'sample.png';
        $path = $directory.'/'.$filename;

        mkdir($directory, 0755, true);
        file_put_contents($path, 'public-content-image');

        try {
            $response = $this->get('/api/content-images/'.$folder.'/'.$filename);

            $response->assertOk()
                ->assertHeader('X-Content-Type-Options', 'nosniff');
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function test_missing_content_image_returns_not_found(): void
    {
        $this->get('/api/content-images/structure/does-not-exist.png')
            ->assertNotFound();
    }
}
