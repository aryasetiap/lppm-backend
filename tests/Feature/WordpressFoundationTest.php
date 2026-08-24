<?php

namespace Tests\Feature;

use App\Support\WordpressAdminSession;
use App\Support\WordpressAssetResolver;
use App\Support\WordpressContentAuthorization;
use App\Support\WordpressDocumentResolver;
use App\Support\LegacyHtmlSanitizer;
use App\Support\WordpressTableResolver;
use Illuminate\Http\UploadedFile;
use LogicException;
use Tests\TestCase;

class WordpressFoundationTest extends TestCase
{
    public function test_wordpress_tables_use_the_configured_safe_prefix(): void
    {
        config()->set('services.wordpress.connection', 'wordpress');
        config()->set('services.wordpress.prefix', '2022_');

        $tables = app(WordpressTableResolver::class);

        $this->assertSame('wordpress', $tables->connectionName());
        $this->assertSame('2022_posts', $tables->table('posts'));
        $this->assertSame('2022_capabilities', $tables->capabilitiesMetaKey());
    }

    public function test_wordpress_table_resolver_rejects_an_unsafe_prefix(): void
    {
        config()->set('services.wordpress.prefix', '2022_;drop');

        $this->expectException(LogicException::class);

        app(WordpressTableResolver::class)->table('posts');
    }

    public function test_attachment_urls_are_built_from_relative_metadata_not_guid(): void
    {
        config()->set('services.wordpress.uploads_base_url', 'https://lppm.unila.ac.id/wp-content/uploads');

        $assets = app(WordpressAssetResolver::class);

        $this->assertSame(
            'https://lppm.unila.ac.id/wp-content/uploads/2026/07/Foto%20Kegiatan.jpg',
            $assets->publicUrl('2026/07/Foto Kegiatan.jpg')
        );
        $this->assertNull($assets->publicUrl('../wp-config.php'));
        $this->assertNull($assets->publicUrl('/wp-content/uploads/file.pdf'));
    }

    public function test_document_resolver_only_serves_one_relative_file_inside_an_allowed_root(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lppm-document-resolver-' . uniqid('', true);
        mkdir($root, 0777, true);
        file_put_contents($root . DIRECTORY_SEPARATOR . 'panduan.pdf', 'dokumen-uji');

        try {
            config()->set('services.wordpress.document_roots', [$root]);
            config()->set('services.wordpress.uploads_root', '');

            $resolver = app(WordpressDocumentResolver::class);
            $resolved = $resolver->resolve([
                '__wpdm_files' => [serialize(['panduan.pdf'])],
            ]);

            $this->assertTrue($resolved['available']);
            $this->assertSame('resolved', $resolved['status']);
            $this->assertSame('panduan.pdf', $resolved['file_name']);
            $this->assertSame('dokumen-uji', file_get_contents((string) $resolved['resolved_path']));
            $this->assertTrue($resolver->isPublic([
                '__wpdm_access' => [serialize(['guest'])],
            ]));
            $this->assertFalse($resolver->resolve([
                '__wpdm_files' => [serialize(['/home/legacy/rahasia.pdf'])],
            ])['available']);
        } finally {
            @unlink($root . DIRECTORY_SEPARATOR . 'panduan.pdf');
            @rmdir($root);
        }
    }

    public function test_document_resolver_treats_missing_or_non_guest_access_as_restricted(): void
    {
        $resolver = app(WordpressDocumentResolver::class);

        $this->assertFalse($resolver->isPublic([]));
        $this->assertFalse($resolver->isPublic([
            '__wpdm_access' => [serialize(['administrator'])],
        ]));
    }

    public function test_admin_session_accepts_only_an_issued_token(): void
    {
        config()->set('services.wordpress.admin_token_ttl_minutes', 120);

        $sessions = app(WordpressAdminSession::class);
        $issued = $sessions->issue([
            'id' => 123,
            'username' => 'admin_lppm',
            'display_name' => 'Admin LPPM',
            'email' => 'admin@example.test',
            'roles' => ['administrator'],
        ]);

        $this->assertSame(64, strlen($issued['token']));
        $this->assertSame(123, $sessions->resolve($issued['token'])['id']);
        $this->assertNull($sessions->resolve(str_repeat('a', 64)));

        $sessions->forget($issued['token']);

        $this->assertNull($sessions->resolve($issued['token']));
    }

    public function test_a_forged_bearer_token_is_rejected_by_admin_middleware(): void
    {
        $this->getJson('/api/admin/content/homepage.json', [
            'Authorization' => 'Bearer ' . str_repeat('a', 64),
        ])->assertUnauthorized()
            ->assertJsonPath('meta.code', 401);
    }

    public function test_issued_admin_session_can_be_read_and_revoked_without_touching_wordpress(): void
    {
        $sessions = app(WordpressAdminSession::class);
        $issued = $sessions->issue([
            'id' => 99,
            'username' => 'admin_lppm',
            'display_name' => 'Admin LPPM',
            'email' => 'admin@example.test',
            'roles' => ['administrator'],
        ]);
        $headers = ['Authorization' => 'Bearer ' . $issued['token']];

        $this->getJson('/api/admin/me', $headers)
            ->assertOk()
            ->assertJsonPath('data.id', 99);

        $this->deleteJson('/api/admin/logout', [], $headers)
            ->assertOk();

        $this->getJson('/api/admin/me', $headers)
            ->assertUnauthorized();
    }

    public function test_admin_content_filters_are_validated_before_the_legacy_database_is_queried(): void
    {
        $sessions = app(WordpressAdminSession::class);
        $issued = $sessions->issue([
            'id' => 99,
            'username' => 'admin_lppm',
            'display_name' => 'Admin LPPM',
            'email' => 'admin@example.test',
            'roles' => ['administrator'],
        ]);
        $headers = ['Authorization' => 'Bearer ' . $issued['token']];

        $this->getJson('/api/admin/posts?author_id=0', $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('author_id');

        $this->getJson('/api/admin/posts?date_from=not-a-date', $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_from');

        $this->getJson('/api/admin/authors?type=attachment', $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_new_or_changed_html_is_allow_list_sanitized(): void
    {
        $clean = app(LegacyHtmlSanitizer::class)->sanitize(
            '<p onclick="alert(1)">Aman <strong>sekali</strong></p><script>alert(2)</script><a href="javascript:alert(3)" target="_blank">Tautan</a>'
        );

        $this->assertStringContainsString('<strong>sekali</strong>', $clean);
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringContainsString('noopener', $clean);
    }

    public function test_draft_mutation_rejects_invalid_or_unauthorized_requests_before_writing(): void
    {
        $sessions = app(WordpressAdminSession::class);
        $withoutCapability = $sessions->issue([
            'id' => 99,
            'username' => 'read_only',
            'display_name' => 'Read Only',
            'email' => 'read@example.test',
            'roles' => ['administrator'],
            'capabilities' => [],
        ]);
        $headers = ['Authorization' => 'Bearer ' . $withoutCapability['token']];

        $this->postJson('/api/admin/cms/posts', [
            'type' => 'post',
            'title' => 'Draft tanpa capability',
        ], $headers)->assertForbidden();

        $withCapability = $sessions->issue([
            'id' => 99,
            'username' => 'editor_test',
            'display_name' => 'Editor Test',
            'email' => 'editor@example.test',
            'roles' => ['administrator'],
            'capabilities' => ['edit_posts'],
        ]);
        $writerHeaders = ['Authorization' => 'Bearer ' . $withCapability['token']];

        $this->postJson('/api/admin/cms/posts', [
            'type' => 'post',
            'title' => '   ',
        ], $writerHeaders)->assertUnprocessable()
            ->assertJsonValidationErrors('title');

        $this->patchJson('/api/admin/cms/posts/1', [
            'expected_modified_at' => 'bukan-tanggal',
            'title' => 'Tidak boleh diuji tulis',
        ], $writerHeaders)->assertUnprocessable()
            ->assertJsonValidationErrors('expected_modified_at');
    }

    public function test_image_upload_requires_a_valid_image_and_wordpress_upload_capability(): void
    {
        $sessions = app(WordpressAdminSession::class);
        $withoutCapability = $sessions->issue([
            'id' => 99,
            'username' => 'read_only',
            'display_name' => 'Read Only',
            'email' => 'read@example.test',
            'roles' => ['administrator'],
            'capabilities' => [],
        ]);

        $this->post('/api/admin/media/images', [
            'image' => UploadedFile::fake()->image('kegiatan.jpg', 640, 480),
        ], ['Authorization' => 'Bearer ' . $withoutCapability['token'], 'Accept' => 'application/json'])
            ->assertForbidden();

        $withCapability = $sessions->issue([
            'id' => 99,
            'username' => 'uploader_test',
            'display_name' => 'Uploader Test',
            'email' => 'uploader@example.test',
            'roles' => ['administrator'],
            'capabilities' => ['upload_files'],
        ]);

        $this->post('/api/admin/media/images', [
            'image' => UploadedFile::fake()->create('bukan-gambar.pdf', 10, 'application/pdf'),
        ], ['Authorization' => 'Bearer ' . $withCapability['token'], 'Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }

    public function test_news_publication_requires_wordpress_publish_capability(): void
    {
        $authorization = app(WordpressContentAuthorization::class);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $authorization->ensureCanPublishPost([
            'id' => 99,
            'capabilities' => ['edit_posts'],
        ], (object) [
            'post_type' => 'post',
            'post_author' => 99,
        ]);
    }

    public function test_editing_published_news_requires_the_wordpress_edit_published_capability(): void
    {
        $authorization = app(WordpressContentAuthorization::class);
        $post = (object) [
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_author' => 99,
        ];

        try {
            $authorization->ensureCanEditPublishedPost([
                'id' => 99,
                'capabilities' => ['edit_posts'],
            ], $post);
            $this->fail('Capability edit_published_posts wajib untuk menyunting berita terbit.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $authorization->ensureCanEditPublishedPost([
            'id' => 99,
            'capabilities' => ['edit_posts', 'edit_published_posts'],
        ], $post);
        $this->addToAssertionCount(1);
    }

    public function test_news_publication_request_validates_before_touching_the_legacy_database(): void
    {
        config()->set('services.wordpress.scheduling_enabled', true);
        $sessions = app(WordpressAdminSession::class);
        $issued = $sessions->issue([
            'id' => 99,
            'username' => 'publisher_test',
            'display_name' => 'Publisher Test',
            'email' => 'publisher@example.test',
            'roles' => ['administrator'],
            'capabilities' => ['edit_posts', 'publish_posts'],
        ]);
        $headers = ['Authorization' => 'Bearer ' . $issued['token']];

        $this->postJson('/api/admin/cms/posts/1/publish', [
            'expected_modified_at' => 'bukan-tanggal',
        ], $headers)->assertUnprocessable()
            ->assertJsonValidationErrors('expected_modified_at');

        $this->postJson('/api/admin/cms/posts/1/schedule', [
            'expected_modified_at' => '2026-08-01 10:00:00',
            'scheduled_at' => 'bukan-jadwal',
        ], $headers)->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_at');
    }

    public function test_scheduling_can_be_disabled_without_processing_future_posts(): void
    {
        config()->set('services.wordpress.scheduling_enabled', false);
        $sessions = app(WordpressAdminSession::class);
        $issued = $sessions->issue([
            'id' => 99,
            'username' => 'publisher_test',
            'display_name' => 'Publisher Test',
            'email' => 'publisher@example.test',
            'roles' => ['administrator'],
            'capabilities' => ['edit_posts', 'publish_posts'],
        ]);
        $headers = ['Authorization' => 'Bearer ' . $issued['token']];

        $this->postJson('/api/admin/cms/posts/1/schedule', [
            'expected_modified_at' => '2026-08-01 10:00:00',
            'scheduled_at' => '2026-08-02T10:00',
        ], $headers)->assertForbidden()
            ->assertJsonPath('meta.code', 403);

        $this->artisan('wp:publish-scheduled-posts')
            ->expectsOutput('Penjadwalan CMS dinonaktifkan; tidak ada berita future yang diproses.')
            ->assertExitCode(0);
    }

    public function test_trash_requires_wordpress_delete_capability_and_validates_before_querying(): void
    {
        $authorization = app(WordpressContentAuthorization::class);
        try {
            $authorization->ensureCanTrashOrRestore([
                'id' => 99,
                'capabilities' => ['edit_posts'],
            ], (object) [
                'post_type' => 'post',
                'post_author' => 99,
                'post_status' => 'draft',
            ]);
            $this->fail('Capability delete_posts wajib untuk memindahkan konten ke Sampah.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $sessions = app(WordpressAdminSession::class);
        $issued = $sessions->issue([
            'id' => 99,
            'username' => 'deleter_test',
            'display_name' => 'Deleter Test',
            'email' => 'deleter@example.test',
            'roles' => ['administrator'],
            'capabilities' => ['delete_posts'],
        ]);

        $this->postJson('/api/admin/cms/posts/1/trash', [
            'expected_modified_at' => 'bukan-tanggal',
        ], ['Authorization' => 'Bearer ' . $issued['token']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expected_modified_at');
    }

    public function test_revision_restore_validates_before_querying_the_legacy_database(): void
    {
        $sessions = app(WordpressAdminSession::class);
        $issued = $sessions->issue([
            'id' => 99,
            'username' => 'editor_test',
            'display_name' => 'Editor Test',
            'email' => 'editor@example.test',
            'roles' => ['administrator'],
            'capabilities' => ['edit_posts'],
        ]);

        $this->postJson('/api/admin/cms/posts/1/revisions/1/restore', [
            'expected_modified_at' => 'bukan-tanggal',
        ], ['Authorization' => 'Bearer ' . $issued['token']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expected_modified_at');
    }

    public function test_taxonomy_mutation_requires_manage_categories_capability(): void
    {
        $sessions = app(WordpressAdminSession::class);
        $withoutCapability = $sessions->issue([
            'id' => 99,
            'username' => 'editor_test',
            'display_name' => 'Editor Test',
            'email' => 'editor@example.test',
            'roles' => ['administrator'],
            'capabilities' => ['edit_posts'],
        ]);

        $this->postJson('/api/admin/taxonomies/categories', [
            'name' => 'Kategori tanpa capability',
        ], ['Authorization' => 'Bearer ' . $withoutCapability['token']])
            ->assertForbidden();

        $this->postJson('/api/admin/taxonomies/document-categories', [
            'name' => 'Kategori dokumen tanpa capability',
        ], ['Authorization' => 'Bearer ' . $withoutCapability['token']])
            ->assertForbidden();

        $withCapability = $sessions->issue([
            'id' => 99,
            'username' => 'taxonomy_test',
            'display_name' => 'Taxonomy Test',
            'email' => 'taxonomy@example.test',
            'roles' => ['administrator'],
            'capabilities' => ['manage_categories'],
        ]);
        $this->postJson('/api/admin/taxonomies/categories', [
            'name' => '   ',
        ], ['Authorization' => 'Bearer ' . $withCapability['token']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->postJson('/api/admin/taxonomies/document-categories', [
            'name' => '   ',
        ], ['Authorization' => 'Bearer ' . $withCapability['token']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_document_list_filters_are_validated_before_querying_legacy_database(): void
    {
        $sessions = app(WordpressAdminSession::class);
        $issued = $sessions->issue([
            'id' => 99,
            'username' => 'document_reader',
            'display_name' => 'Document Reader',
            'email' => 'reader@example.test',
            'roles' => ['administrator'],
            'capabilities' => ['read'],
        ]);

        $this->getJson('/api/admin/cms/documents?status=invalid', [
            'Authorization' => 'Bearer ' . $issued['token'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_document_draft_mutations_require_capability_and_validate_before_writing(): void
    {
        $sessions = app(WordpressAdminSession::class);
        $withoutCapability = $sessions->issue([
            'id' => 99,
            'username' => 'reader_only',
            'display_name' => 'Reader Only',
            'email' => 'reader@example.test',
            'roles' => ['administrator'],
            'capabilities' => ['read'],
        ]);

        $this->postJson('/api/admin/cms/documents', [
            'title' => 'Dokumen tanpa capability',
        ], ['Authorization' => 'Bearer ' . $withoutCapability['token']])
            ->assertForbidden();

        $this->postJson('/api/admin/cms/documents/1/publish', [
            'expected_modified_at' => '2026-08-02 10:00:00',
            'access' => 'guest',
        ], ['Authorization' => 'Bearer ' . $withoutCapability['token']])
            ->assertForbidden();

        $this->postJson('/api/admin/cms/documents/1/trash', [
            'expected_modified_at' => '2026-08-02 10:00:00',
        ], ['Authorization' => 'Bearer ' . $withoutCapability['token']])
            ->assertForbidden();

        $withCapability = $sessions->issue([
            'id' => 99,
            'username' => 'document_admin',
            'display_name' => 'Document Admin',
            'email' => 'document-admin@example.test',
            'roles' => ['administrator'],
            'capabilities' => ['edit_posts', 'manage_categories', 'upload_files'],
        ]);
        $headers = ['Authorization' => 'Bearer ' . $withCapability['token']];

        $this->postJson('/api/admin/cms/documents', [
            'title' => '   ',
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');

        $this->postJson('/api/admin/cms/documents/1/file', [], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->postJson('/api/admin/cms/documents/1/publish', [
            'expected_modified_at' => 'bukan-tanggal',
            'access' => 'tidak-valid',
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['expected_modified_at', 'access']);
    }

    public function test_restricted_document_download_requires_wordpress_read_capability(): void
    {
        $authorization = app(WordpressContentAuthorization::class);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        $authorization->ensureCanReadDocuments([
            'id' => 99,
            'capabilities' => [],
        ]);
    }
}
