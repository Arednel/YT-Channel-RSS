<?php

namespace App\Actions\Youtube;

use App\Jobs\BuildYoutubeFeedJob;
use App\Models\YoutubeChannel;
use App\Support\YoutubeBatchManager;
use App\Support\Youtube\ChannelFetchRunner;
use App\Support\Youtube\YoutubeChannelReference;
use App\Support\Youtube\VideoChunkPlanner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RunYoutubeChannelSyncAction
{
    public function __construct(
        private ChannelFetchRunner $channelFetchRunner,
        private VideoChunkPlanner $videoChunkPlanner,
        private DispatchYoutubeVideoChunkBatchAction $dispatchVideoChunkBatch,
        private FinalizeYoutubeChannelSyncWithoutBatchAction $finalizeWithoutBatch,
        private YoutubeBatchManager $youtubeBatchManager,
    ) {
    }

    public function fetchChannelInfoAndVideoList(int $channelId): bool
    {
        $channel = YoutubeChannel::query()->find($channelId);
        if ($channel === null) {
            return false;
        }

        if ($channel->isDeleting()) {
            return false;
        }

        if ($this->youtubeBatchManager->hasActiveVideoBatch($channel)) {
            Log::channel('youtube')->info('Sync skipped: an active video chunk batch already exists for this channel.', [
                'local_database_channel_id' => $channel->id,
                'youtube_id' => $channel->youtube_id,
            ]);

            return false;
        }

        Log::channel('youtube')->info('YouTube channel info/list fetch started.', [
            'local_database_channel_id' => $channel->id,
            'youtube_id' => $channel->youtube_id,
        ]);

        $channel->markFetchingVideoList();

        $fetchRun = $this->channelFetchRunner->run($channel);
        $channelJsonPath = $fetchRun->channelJsonPath;
        $outputDirectory = $fetchRun->outputDirectory;

        if (File::exists($channelJsonPath)) {
            $channelData = json_decode(File::get($channelJsonPath), true);
            $channelName = $this->resolveChannelName($channelData);

            if ($channelName !== null) {
                $channel->update([
                    'channel_name' => $channelName,
                ]);
            }

            $shouldContinue = $this->syncIdentifiersFromChannelMetadata(
                channel: $channel,
                channelData: $channelData,
                allowHandleUpdate: false,
            );

            if (! $shouldContinue) {
                File::deleteDirectory($outputDirectory);

                return false;
            }
        }

        $videosJsonPath = $outputDirectory . '/videos.jsonl';

        if (! File::exists($videosJsonPath)) {
            Log::channel('youtube')->error('Channel fetch did not produce videos.jsonl.', [
                'channel_id' => $channel->id,
                'youtube_id' => $channel->fresh()?->youtube_id ?? $channel->youtube_id,
                'expected_path' => $videosJsonPath,
            ]);

            throw new \RuntimeException('Channel fetch output missing videos.jsonl.');
        }

        $channel->refresh();

        Log::channel('youtube')->info('YouTube channel info/list fetch finished.', [
            'local_database_channel_id' => $channel->id,
            'youtube_id' => $channel->youtube_id,
        ]);

        return true;
    }

    public function dispatchVideoPhase(int $channelId): void
    {
        $channel = YoutubeChannel::query()->find($channelId);
        if ($channel === null) {
            return;
        }

        if ($channel->isDeleting()) {
            return;
        }

        if ($this->youtubeBatchManager->hasActiveVideoBatch($channel)) {
            Log::channel('youtube')->info('Video phase skipped: an active video chunk batch already exists for this channel.', [
                'local_database_channel_id' => $channel->id,
                'youtube_id' => $channel->youtube_id,
            ]);

            return;
        }

        $outputDirectory = base_path('python/yt-dlp_jsons/' . $channel->youtube_id);
        $videosJsonPath = $outputDirectory . '/videos.jsonl';
        if (! File::exists($videosJsonPath)) {
            Log::channel('youtube')->error('Video phase did not find videos.jsonl.', [
                'channel_id' => $channel->id,
                'youtube_id' => $channel->youtube_id,
                'expected_path' => $videosJsonPath,
            ]);

            throw new \RuntimeException('Video phase input missing videos.jsonl.');
        }

        $plan = $this->videoChunkPlanner->plan(
            channel: $channel,
            videosJsonPath: $videosJsonPath,
            outputDirectory: $outputDirectory,
        );

        if ($plan->hasJobs()) {
            $batch = $this->dispatchVideoChunkBatch->handle($channel, $plan);

            Log::channel('youtube')->info('Video chunk jobs dispatched.', [
                'queued_video_count' => $plan->queuedVideoCount,
                'skipped_existing_count' => $plan->skippedExistingCount,
                'rechecked_upcoming_count' => $plan->recheckedUpcomingCount,
                'chunk_count' => $plan->chunkCount,
                'chunk_size' => $plan->chunkSize,
                'batch_id' => $batch->id,
                'encoding_skipped_count' => $plan->encodingSkippedCount,
            ]);

            return;
        }

        if ($plan->lastVideoId !== null) {
            $channel->update(['last_video_id' => $plan->lastVideoId]);
        }

        Log::channel('youtube')->info('No videos required detail refresh.', [
            'queued_video_count' => $plan->queuedVideoCount,
            'skipped_existing_count' => $plan->skippedExistingCount,
            'rechecked_upcoming_count' => $plan->recheckedUpcomingCount,
            'encoding_skipped_count' => $plan->encodingSkippedCount,
        ]);

        $this->syncIdentifiersFromPersistedMetadata($channel->id, true);
        $channel = YoutubeChannel::query()->find($channelId);
        if ($channel === null || $channel->isDeleting()) {
            return;
        }

        $shouldBuildFeed = ! Storage::disk('public')->exists('feeds/' . $channel->youtube_id . '.xml');
        $this->finalizeWithoutBatch->handle($channel->id, $shouldBuildFeed);

        if ($shouldBuildFeed) {
            BuildYoutubeFeedJob::dispatch($channel->id);
        }

        Log::channel('youtube')->info('YouTube channel video phase finished.', [
            'local_database_channel_id' => $channel->id,
            'youtube_id' => $channel->youtube_id,
        ]);
    }

    private function resolveChannelName(mixed $channelData): ?string
    {
        if (! is_array($channelData)) {
            return null;
        }

        $name = $this->firstNonEmptyString([
            $channelData['channel'] ?? null,
            $channelData['uploader'] ?? null,
        ]);

        $title = $channelData['title'] ?? null;
        if ($name === null && is_string($title) && $title !== '' && ! str_starts_with($title, '@')) {
            $name = $title;
        }

        if ($name !== null) {
            return $name;
        }

        $entries = $channelData['entries'] ?? null;
        if (! is_array($entries)) {
            return null;
        }

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $name = $this->firstNonEmptyString([
                $entry['channel'] ?? null,
                $entry['uploader'] ?? null,
            ]);

            if ($name !== null) {
                return $name;
            }

            $nestedEntries = $entry['entries'] ?? null;
            if (! is_array($nestedEntries)) {
                continue;
            }

            foreach ($nestedEntries as $nestedEntry) {
                if (! is_array($nestedEntry)) {
                    continue;
                }

                $name = $this->firstNonEmptyString([
                    $nestedEntry['channel'] ?? null,
                    $nestedEntry['uploader'] ?? null,
                ]);

                if ($name !== null) {
                    return $name;
                }
            }
        }

        return null;
    }

    private function firstNonEmptyString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    public function syncIdentifiersFromPersistedMetadata(int $channelId, bool $allowHandleUpdate = true): void
    {
        $channel = YoutubeChannel::query()->find($channelId);
        if ($channel === null || $channel->isDeleting()) {
            return;
        }

        $channelJsonPath = base_path('python/yt-dlp_jsons/' . $channel->youtube_id . '/channel.json');
        if (! File::exists($channelJsonPath)) {
            return;
        }

        $channelData = json_decode(File::get($channelJsonPath), true);
        $channelName = $this->resolveChannelName($channelData);

        if ($channelName !== null) {
            $channel->update([
                'channel_name' => $channelName,
            ]);
        }

        $this->syncIdentifiersFromChannelMetadata(
            channel: $channel,
            channelData: $channelData,
            allowHandleUpdate: $allowHandleUpdate,
        );
    }

    private function syncIdentifiersFromChannelMetadata(
        YoutubeChannel $channel,
        mixed $channelData,
        bool $allowHandleUpdate
    ): bool {
        if (! is_array($channelData)) {
            return true;
        }

        $preferredHandle = $this->resolvePreferredHandleFromMetadata($channelData);
        $resolvedChannelId = $this->resolveChannelIdFromMetadata($channelData);

        if (! $this->ensureCanonicalChannelRecord($channel, $resolvedChannelId, $preferredHandle)) {
            return false;
        }

        $updates = [];

        if (
            $resolvedChannelId !== null
            && $resolvedChannelId !== $channel->youtube_channel_id
            && ! $this->identifierAlreadyUsedByAnotherChannel($channel->id, $resolvedChannelId)
        ) {
            $updates['youtube_channel_id'] = $resolvedChannelId;
        }

        if (
            $allowHandleUpdate
            && $preferredHandle !== null
            && $preferredHandle !== $channel->youtube_id
        ) {
            $handleAlreadyUsed = YoutubeChannel::query()
                ->where('id', '!=', $channel->id)
                ->where('youtube_id', $preferredHandle)
                ->exists();

            if (! $handleAlreadyUsed) {
                $updates['youtube_id'] = $preferredHandle;
            } else {
                Log::channel('youtube')->warning('Resolved YouTube handle skipped due to uniqueness conflict.', [
                    'channel_id' => $channel->id,
                    'current_youtube_id' => $channel->youtube_id,
                    'resolved_handle' => $preferredHandle,
                ]);
            }
        }

        if ($updates === []) {
            return true;
        }

        $previousYoutubeId = $channel->youtube_id;
        $channel->update($updates);
        $channel->refresh();

        if (array_key_exists('youtube_id', $updates)) {
            Log::channel('youtube')->info('Resolved channel reference updated to preferred handle.', [
                'channel_id' => $channel->id,
                'previous_youtube_id' => $previousYoutubeId,
                'resolved_youtube_id' => $channel->youtube_id,
                'resolved_channel_id' => $channel->youtube_channel_id,
            ]);
        }

        if (array_key_exists('youtube_channel_id', $updates)) {
            Log::channel('youtube')->info('Resolved channel UC id stored.', [
                'channel_id' => $channel->id,
                'youtube_id' => $channel->youtube_id,
                'resolved_channel_id' => $channel->youtube_channel_id,
            ]);
        }

        return true;
    }

    private function identifierAlreadyUsedByAnotherChannel(int $channelId, string $identifier): bool
    {
        return YoutubeChannel::query()
            ->where('id', '!=', $channelId)
            ->where(function (Builder $query) use ($identifier): void {
                $query->where('youtube_id', $identifier)
                    ->orWhere('youtube_channel_id', $identifier);
            })
            ->exists();
    }

    private function ensureCanonicalChannelRecord(
        YoutubeChannel $channel,
        ?string $resolvedChannelId,
        ?string $preferredHandle
    ): bool
    {
        if ($resolvedChannelId === null && $preferredHandle === null) {
            return true;
        }

        $conflicts = YoutubeChannel::query()
            ->where('id', '!=', $channel->id)
            ->where(function (Builder $query) use ($resolvedChannelId, $preferredHandle): void {
                if ($resolvedChannelId !== null) {
                    $query->where('youtube_channel_id', $resolvedChannelId)
                        ->orWhere('youtube_id', $resolvedChannelId);
                }

                if ($preferredHandle !== null) {
                    if ($resolvedChannelId !== null) {
                        $query->orWhere('youtube_id', $preferredHandle);
                    } else {
                        $query->where('youtube_id', $preferredHandle);
                    }
                }
            })
            ->orderBy('id')
            ->get(['id']);

        if ($conflicts->isEmpty()) {
            return true;
        }

        $olderConflictExists = $conflicts
            ->contains(static fn (YoutubeChannel $conflict): bool => $conflict->id < $channel->id);

        if ($olderConflictExists) {
            Log::channel('youtube')->info('Duplicate channel resolved in favor of older record.', [
                'duplicate_channel_id' => $channel->id,
                'duplicate_youtube_id' => $channel->youtube_id,
                'resolved_channel_id' => $resolvedChannelId,
                'resolved_handle' => $preferredHandle,
            ]);

            $channel->delete();

            return false;
        }

        $conflictIds = $conflicts->pluck('id')->all();
        if ($conflictIds !== []) {
            YoutubeChannel::query()
                ->whereIn('id', $conflictIds)
                ->delete();

            Log::channel('youtube')->info('Duplicate channels removed in favor of canonical record.', [
                'canonical_channel_id' => $channel->id,
                'removed_channel_ids' => $conflictIds,
                'resolved_channel_id' => $resolvedChannelId,
                'resolved_handle' => $preferredHandle,
            ]);
        }

        return true;
    }

    private function resolvePreferredHandleFromMetadata(array $channelData): ?string
    {
        return YoutubeChannelReference::normalizeHandle($channelData['uploader_id'] ?? null);
    }

    private function resolveChannelIdFromMetadata(array $channelData): ?string
    {
        return YoutubeChannelReference::normalizeChannelId($channelData['channel_id'] ?? null)
            ?? YoutubeChannelReference::normalizeChannelId($channelData['id'] ?? null);
    }
}
