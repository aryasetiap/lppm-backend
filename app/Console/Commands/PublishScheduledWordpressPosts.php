<?php

namespace App\Console\Commands;

use App\Support\WordpressPostPublication;
use Illuminate\Console\Command;

/** Publishes due WordPress-standard news without requiring wp-cron. */
final class PublishScheduledWordpressPosts extends Command
{
    protected $signature = 'wp:publish-scheduled-posts';

    protected $description = 'Terbitkan berita WordPress yang jadwalnya telah tiba';

    public function handle(WordpressPostPublication $publication): int
    {
        if (!config('services.wordpress.scheduling_enabled', false)) {
            $this->comment('Penjadwalan CMS dinonaktifkan; tidak ada berita future yang diproses.');

            return self::SUCCESS;
        }

        $published = $publication->publishDue();
        $this->info("Berita terjadwal yang diterbitkan: {$published}");

        return self::SUCCESS;
    }
}
