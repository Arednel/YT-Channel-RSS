<?php

namespace App\Console\Commands;

use App\Enums\YoutubeChannelStatus;
use App\Jobs\SyncYoutubeChannelJob;
use App\Models\YoutubeChannel;
use App\Support\YoutubeBatchManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class YoutubeMaintenanceCommand extends Command
{
    protected $signature = 'youtube:maintenance
        {--channel-id= : Run maintenance for a specific local youtube_channels.id}
        {--scheduled : Internal flag for scheduler-driven runs}
        {--force : Ignore interval gate for scheduled runs}';

    protected $description = 'Dispatch periodic sync jobs for channels that are not currently busy.';

    public function handle(): int
    {
        if ($this->shouldSkipScheduledRunByInterval()) {
            return self::SUCCESS;
        }

        $channels = YoutubeChannel::query()
            ->when($this->option('channel-id') !== null, function ($query) {
                $query->whereKey((int) $this->option('channel-id'));
            })
            ->orderBy('id')
            ->get();

        if ($channels->isEmpty()) {
            $this->info('No channels found for maintenance.');
            return self::SUCCESS;
        }

        $dispatched = 0;
        $skippedBusy = 0;

        foreach ($channels as $channel) {
            if (YoutubeBatchManager::hasActiveVideoBatch($channel)) {
                $skippedBusy++;
                $this->line("skip {$channel->youtube_id}: active video batch");
                continue;
            }

            if ($this->hasBusyStatus($channel)) {
                $skippedBusy++;
                $this->line("skip {$channel->youtube_id}: status={$channel->status_label}");
                continue;
            }

            $channel->update([
                'status' => YoutubeChannelStatus::Queued,
                'last_error' => null,
            ]);

            SyncYoutubeChannelJob::dispatch($channel->id);
            $dispatched++;

            $this->info("dispatch {$channel->youtube_id}");
        }

        $this->newLine();
        $this->info("Maintenance finished. dispatched={$dispatched} skipped_busy={$skippedBusy}");

        return self::SUCCESS;
    }

    private function shouldSkipScheduledRunByInterval(): bool
    {
        if (! (bool) $this->option('scheduled')) {
            return false;
        }

        if ((bool) $this->option('force')) {
            return false;
        }

        $intervalMinutes = max(1, (int) config('youtube.maintenance_interval_minutes', 30));
        $gateKey = 'youtube:maintenance:scheduled_gate';

        return ! Cache::add($gateKey, now()->timestamp, now()->addMinutes($intervalMinutes));
    }

    private function hasBusyStatus(YoutubeChannel $channel): bool
    {
        $status = $channel->status;
        if (! $status instanceof YoutubeChannelStatus) {
            $status = is_string($status) ? YoutubeChannelStatus::tryFrom($status) : null;
        }

        if (! $status instanceof YoutubeChannelStatus) {
            return false;
        }

        return in_array($status, [
            YoutubeChannelStatus::Queued,
            YoutubeChannelStatus::Syncing,
            YoutubeChannelStatus::FetchingVideoList,
            YoutubeChannelStatus::FetchingVideos,
            YoutubeChannelStatus::BuildingFeed,
            YoutubeChannelStatus::Deleting,
        ], true);
    }
}
