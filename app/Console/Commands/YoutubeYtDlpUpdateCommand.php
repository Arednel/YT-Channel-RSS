<?php

namespace App\Console\Commands;

use App\Jobs\UpdateYtDlpJob;
use Illuminate\Console\Command;

class YoutubeYtDlpUpdateCommand extends Command
{
    protected $signature = 'youtube:yt-dlp:update
        {--force : Ignore update interval / auto-update enable gate}
        {--queued : Dispatch job to queue instead of running now}';

    protected $description = 'Update yt-dlp dependency used by Python fetch scripts.';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $queued = (bool) $this->option('queued');

        if ($queued) {
            UpdateYtDlpJob::dispatch('manual-command', $force);

            $this->info('yt-dlp update job dispatched to queue.');
            $this->line('Use queue worker and check storage/logs/yt-dlp-update.log for progress.');

            return self::SUCCESS;
        }

        try {
            UpdateYtDlpJob::dispatchSync('manual-command', $force);

            $this->info('yt-dlp update finished.');
            $this->line('Check storage/logs/yt-dlp-update.log for details.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('yt-dlp update failed: ' . $exception->getMessage());
            $this->line('See storage/logs/yt-dlp-update.log for details.');

            return self::FAILURE;
        }
    }
}

