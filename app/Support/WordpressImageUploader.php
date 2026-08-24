<?php

namespace App\Support;

use DateTimeZone;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Creates standard WordPress image attachments without loading WordPress PHP.
 *
 * Files are written only below the configured uploads root. The attachment
 * record, its relative `_wp_attached_file`, metadata, and optional alt text
 * follow the legacy WordPress schema so the CMS can run without wp-admin.
 */
final class WordpressImageUploader
{
    /** @var array<string,string> */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly WordpressTableResolver $tables,
        private readonly WordpressAssetResolver $assets,
        private readonly WordpressContentAuthorization $authorization,
        private readonly CmsAuditLogger $audit
    ) {
    }

    /**
     * @param array{id:int,username:string,capabilities?:list<string>} $actor
     * @return array{id:int,title:string,mime:string,alt_text:string,file:array{reference:string,url:string,exists:true}}
     */
    public function upload(array $actor, UploadedFile $file, ?string $title = null, ?string $altText = null): array
    {
        $this->authorization->ensureCanUploadMedia($actor);

        $source = $file->getRealPath();
        $imageInfo = $source === false ? false : @getimagesize($source);
        $mime = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : '';
        if (!isset(self::MIME_EXTENSIONS[$mime])) {
            throw ValidationException::withMessages([
                'image' => 'File harus berupa gambar JPEG, PNG, atau WebP yang valid.',
            ]);
        }

        $width = (int) ($imageInfo[0] ?? 0);
        $height = (int) ($imageInfo[1] ?? 0);
        if ($width < 1 || $height < 1 || $width > 8000 || $height > 8000) {
            throw ValidationException::withMessages([
                'image' => 'Dimensi gambar harus berada antara 1 dan 8.000 piksel.',
            ]);
        }

        $root = $this->uploadsRoot();
        $dates = $this->wordpressDates();
        $relativeDirectory = $this->relativeDirectory($dates['local']);
        $absoluteDirectory = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0755, true) && !is_dir($absoluteDirectory)) {
            throw new RuntimeException('Direktori uploads tidak dapat dibuat.');
        }

        $extension = self::MIME_EXTENSIONS[$mime];
        $baseName = $this->safeBaseName((string) $file->getClientOriginalName());
        $filename = $this->uniqueFilename($absoluteDirectory, $baseName, $extension);
        $absoluteOriginal = $absoluteDirectory . DIRECTORY_SEPARATOR . $filename;
        $createdFiles = [];

        try {
            $file->move($absoluteDirectory, $filename);
            $createdFiles[] = $absoluteOriginal;

            $generated = $this->generateSizes(
                $absoluteOriginal,
                $absoluteDirectory,
                pathinfo($filename, PATHINFO_FILENAME),
                $extension,
                $mime,
                $width,
                $height
            );
            $createdFiles = [...$createdFiles, ...$generated['created_files']];
            $relativePath = $relativeDirectory === '' ? $filename : $relativeDirectory . '/' . $filename;
            $attachmentTitle = $this->attachmentTitle($title, $baseName);
            $attachmentId = $this->tables->connection()->transaction(function () use ($actor, $dates, $relativePath, $mime, $attachmentTitle, $altText, $width, $height, $generated) {
                $postsTable = $this->tables->table('posts');
                $postId = $this->tables->connection()->table($postsTable)->insertGetId([
                    'post_author' => $actor['id'],
                    'post_date' => $dates['local'],
                    'post_date_gmt' => $dates['gmt'],
                    'post_content' => '',
                    'post_title' => $attachmentTitle,
                    'post_excerpt' => '',
                    'post_status' => 'inherit',
                    'comment_status' => 'closed',
                    'ping_status' => 'closed',
                    'post_password' => '',
                    'post_name' => $this->uniqueAttachmentSlug($attachmentTitle),
                    'to_ping' => '',
                    'pinged' => '',
                    'post_modified' => $dates['local'],
                    'post_modified_gmt' => $dates['gmt'],
                    'post_content_filtered' => '',
                    'post_parent' => 0,
                    'guid' => $this->assets->publicUrl($relativePath) ?? '',
                    'menu_order' => 0,
                    'post_type' => 'attachment',
                    'post_mime_type' => $mime,
                    'comment_count' => 0,
                ]);

                $metadata = [
                    'width' => $width,
                    'height' => $height,
                    'file' => $relativePath,
                    'sizes' => $generated['sizes'],
                    'image_meta' => [
                        'aperture' => '0',
                        'credit' => '',
                        'camera' => '',
                        'caption' => '',
                        'created_timestamp' => '0',
                        'copyright' => '',
                        'focal_length' => '0',
                        'iso' => '0',
                        'shutter_speed' => '0',
                        'title' => '',
                        'orientation' => '0',
                        'keywords' => [],
                    ],
                ];

                $metaTable = $this->tables->table('postmeta');
                $metaRows = [
                    ['post_id' => $postId, 'meta_key' => '_wp_attached_file', 'meta_value' => $relativePath],
                    ['post_id' => $postId, 'meta_key' => '_wp_attachment_metadata', 'meta_value' => serialize($metadata)],
                    ['post_id' => $postId, 'meta_key' => '_edit_last', 'meta_value' => (string) $actor['id']],
                ];
                $cleanAltText = trim((string) $altText);
                if ($cleanAltText !== '') {
                    $metaRows[] = ['post_id' => $postId, 'meta_key' => '_wp_attachment_image_alt', 'meta_value' => Str::limit($cleanAltText, 1000, '')];
                }
                $this->tables->connection()->table($metaTable)->insert($metaRows);

                return (int) $postId;
            });
        } catch (Throwable $exception) {
            $this->removeCreatedFiles($createdFiles);
            throw $exception;
        }

        $result = [
            'id' => $attachmentId,
            'title' => $attachmentTitle,
            'mime' => $mime,
            'alt_text' => trim((string) $altText),
            'file' => [
                'reference' => $relativePath,
                'url' => $this->assets->publicUrl($relativePath) ?? '',
                'exists' => true,
            ],
        ];
        $this->audit->contentMutation('cms.media.image_uploaded', $actor, [
            'media_id' => $attachmentId,
            'mime' => $mime,
        ]);

        return $result;
    }

    private function uploadsRoot(): string
    {
        $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) config('services.wordpress.uploads_root', '')), DIRECTORY_SEPARATOR);
        if ($root === '' || !is_dir($root) || !is_writable($root)) {
            throw new RuntimeException('Root uploads WordPress belum tersedia atau tidak dapat ditulis oleh Laravel.');
        }

        return $root;
    }

    /** @return array{local:string,gmt:string} */
    private function wordpressDates(): array
    {
        $utc = now('UTC');
        $timezone = trim((string) $this->option('timezone_string', ''));

        try {
            $local = $timezone === '' ? null : now(new DateTimeZone($timezone));
        } catch (Throwable) {
            $local = null;
        }

        if ($local === null) {
            $offsetMinutes = (int) round((float) $this->option('gmt_offset', '0') * 60);
            $local = $utc->copy()->addMinutes($offsetMinutes);
        }

        return ['local' => $local->format('Y-m-d H:i:s'), 'gmt' => $utc->format('Y-m-d H:i:s')];
    }

    private function relativeDirectory(string $localDate): string
    {
        if ($this->option('uploads_use_yearmonth_folders', '1') !== '1') {
            return '';
        }

        return substr($localDate, 0, 4) . '/' . substr($localDate, 5, 2);
    }

    private function safeBaseName(string $originalName): string
    {
        $base = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));

        return Str::limit($base === '' ? 'gambar-lppm' : $base, 120, '');
    }

    private function attachmentTitle(?string $title, string $fallback): string
    {
        $value = trim(strip_tags((string) $title));

        return Str::limit($value === '' ? str_replace('-', ' ', $fallback) : $value, 500, '');
    }

    private function uniqueFilename(string $directory, string $baseName, string $extension): string
    {
        $candidate = $baseName . '.' . $extension;
        $index = 2;
        while (is_file($directory . DIRECTORY_SEPARATOR . $candidate)) {
            $suffix = '-' . $index;
            $candidate = Str::limit($baseName, 180 - strlen($suffix), '') . $suffix . '.' . $extension;
            $index++;
        }

        return $candidate;
    }

    private function uniqueAttachmentSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title), 180, '');
        $base = $base === '' ? 'gambar-lppm' : $base;
        $candidate = $base;
        $index = 2;
        while ($this->tables->connection()->table($this->tables->table('posts'))
            ->where('post_type', 'attachment')
            ->where('post_name', $candidate)
            ->exists()) {
            $suffix = '-' . $index;
            $candidate = Str::limit($base, 200 - strlen($suffix), '') . $suffix;
            $index++;
        }

        return $candidate;
    }

    /**
     * @return array{sizes:array<string,array{file:string,width:int,height:int,mime-type:string}>,created_files:list<string>}
     */
    private function generateSizes(string $sourcePath, string $directory, string $baseName, string $extension, string $mime, int $sourceWidth, int $sourceHeight): array
    {
        $source = $this->openImage($sourcePath, $mime);
        if ($source === false) {
            throw ValidationException::withMessages(['image' => 'Gambar tidak dapat diproses.']);
        }

        $sizes = [];
        $createdFiles = [];
        try {
            foreach ($this->registeredSizes() as $name => $settings) {
                $dimensions = $this->targetDimensions($sourceWidth, $sourceHeight, $settings['width'], $settings['height'], $settings['crop']);
                if ($dimensions === null) {
                    continue;
                }

                $suffix = '-' . $dimensions['width'] . 'x' . $dimensions['height'];
                $filename = $baseName . $suffix . '.' . $extension;
                $destinationPath = $directory . DIRECTORY_SEPARATOR . $filename;
                $destination = $this->canvas($dimensions['width'], $dimensions['height'], $mime);
                imagecopyresampled(
                    $destination,
                    $source,
                    0,
                    0,
                    $dimensions['source_x'],
                    $dimensions['source_y'],
                    $dimensions['width'],
                    $dimensions['height'],
                    $dimensions['source_width'],
                    $dimensions['source_height']
                );
                $written = $this->saveImage($destination, $destinationPath, $mime);
                imagedestroy($destination);
                if (!$written) {
                    throw new RuntimeException('Ukuran turunan gambar tidak dapat disimpan.');
                }

                $createdFiles[] = $destinationPath;
                $sizes[$name] = [
                    'file' => $filename,
                    'width' => $dimensions['width'],
                    'height' => $dimensions['height'],
                    'mime-type' => $mime,
                ];
            }
        } catch (Throwable $exception) {
            $this->removeCreatedFiles($createdFiles);
            throw $exception;
        } finally {
            imagedestroy($source);
        }

        return ['sizes' => $sizes, 'created_files' => $createdFiles];
    }

    /**
     * @return array<string,array{width:int,height:int,crop:bool}>
     */
    private function registeredSizes(): array
    {
        return [
            'thumbnail' => [
                'width' => $this->optionInt('thumbnail_size_w', 150),
                'height' => $this->optionInt('thumbnail_size_h', 150),
                'crop' => $this->option('thumbnail_crop', '1') === '1',
            ],
            'medium' => [
                'width' => $this->optionInt('medium_size_w', 300),
                'height' => $this->optionInt('medium_size_h', 300),
                'crop' => false,
            ],
            'medium_large' => [
                'width' => $this->optionInt('medium_large_size_w', 768),
                'height' => $this->optionInt('medium_large_size_h', 0),
                'crop' => false,
            ],
            'large' => [
                'width' => $this->optionInt('large_size_w', 1024),
                'height' => $this->optionInt('large_size_h', 1024),
                'crop' => false,
            ],
        ];
    }

    /**
     * @param array{width:int,height:int,crop:bool} $settings
     * @return array{width:int,height:int,source_x:int,source_y:int,source_width:int,source_height:int}|null
     */
    private function targetDimensions(int $sourceWidth, int $sourceHeight, int $targetWidth, int $targetHeight, bool $crop): ?array
    {
        if ($targetWidth < 1 && $targetHeight < 1) {
            return null;
        }

        if ($crop && $targetWidth > 0 && $targetHeight > 0) {
            if ($sourceWidth <= $targetWidth && $sourceHeight <= $targetHeight) {
                return null;
            }
            $scale = max($targetWidth / $sourceWidth, $targetHeight / $sourceHeight);
            $cropWidth = max(1, (int) round($targetWidth / $scale));
            $cropHeight = max(1, (int) round($targetHeight / $scale));
            return [
                'width' => $targetWidth,
                'height' => $targetHeight,
                'source_x' => max(0, (int) floor(($sourceWidth - $cropWidth) / 2)),
                'source_y' => max(0, (int) floor(($sourceHeight - $cropHeight) / 2)),
                'source_width' => min($sourceWidth, $cropWidth),
                'source_height' => min($sourceHeight, $cropHeight),
            ];
        }

        $widthRatio = $targetWidth > 0 ? $targetWidth / $sourceWidth : INF;
        $heightRatio = $targetHeight > 0 ? $targetHeight / $sourceHeight : INF;
        $scale = min($widthRatio, $heightRatio, 1);
        if ($scale >= 1) {
            return null;
        }

        return [
            'width' => max(1, (int) round($sourceWidth * $scale)),
            'height' => max(1, (int) round($sourceHeight * $scale)),
            'source_x' => 0,
            'source_y' => 0,
            'source_width' => $sourceWidth,
            'source_height' => $sourceHeight,
        ];
    }

    /** @return \GdImage|false */
    private function openImage(string $path, string $mime)
    {
        return match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png' => imagecreatefrompng($path),
            'image/webp' => imagecreatefromwebp($path),
            default => false,
        };
    }

    /** @return \GdImage */
    private function canvas(int $width, int $height, string $mime)
    {
        $canvas = imagecreatetruecolor($width, $height);
        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, $width, $height, $transparent);
        }

        return $canvas;
    }

    private function saveImage(\GdImage $image, string $path, string $mime): bool
    {
        return match ($mime) {
            'image/jpeg' => imagejpeg($image, $path, 88),
            'image/png' => imagepng($image, $path, 6),
            'image/webp' => imagewebp($image, $path, 84),
            default => false,
        };
    }

    /** @param list<string> $files */
    private function removeCreatedFiles(array $files): void
    {
        foreach (array_reverse($files) as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function optionInt(string $name, int $fallback): int
    {
        $value = (int) $this->option($name, (string) $fallback);

        return max(0, min($value, 4000));
    }

    private function option(string $name, string $fallback): string
    {
        $value = $this->tables->connection()->table($this->tables->table('options'))
            ->where('option_name', $name)
            ->value('option_value');

        return is_string($value) ? $value : $fallback;
    }
}
