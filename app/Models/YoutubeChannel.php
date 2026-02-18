<?php

namespace App\Models;

use App\Enums\YoutubeChannelStatus;
use App\Support\Youtube\YoutubeChannelReference;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class YoutubeChannel extends Model
{
    /** @use HasFactory<\Database\Factories\YoutubeChannelFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'youtube_id',
        'youtube_channel_id',
        'channel_name',
        'status',
        'last_sync_at',
        'last_video_id',
        'last_error',
        'active_video_batch_id',
        'video_fetch_progress_current',
        'video_fetch_progress_total',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => YoutubeChannelStatus::class,
            'last_sync_at' => 'datetime',
            'video_fetch_progress_current' => 'integer',
            'video_fetch_progress_total' => 'integer',
        ];
    }

    public function resolvedStatus(): ?YoutubeChannelStatus
    {
        return YoutubeChannelStatus::resolve($this->status);
    }

    public function hasStatus(YoutubeChannelStatus $status): bool
    {
        return $this->resolvedStatus() === $status;
    }

    public function isBusy(): bool
    {
        return $this->resolvedStatus()?->isBusy() ?? false;
    }

    public function isIdle(): bool
    {
        return $this->hasStatus(YoutubeChannelStatus::Idle);
    }

    public function isDeleting(): bool
    {
        return $this->hasStatus(YoutubeChannelStatus::Deleting);
    }

    public function canCopyRssLink(): bool
    {
        return $this->resolvedStatus()?->canCopyRssLink() ?? false;
    }

    public function setActiveVideoBatchId(?string $batchId): void
    {
        self::query()->whereKey($this->id)->update([
            'active_video_batch_id' => $batchId,
        ]);

        $this->active_video_batch_id = $batchId;
    }

    public function clearActiveVideoBatchId(): void
    {
        $this->setActiveVideoBatchId(null);
    }

    public function markQueuedForSync(): void
    {
        $this->persistState([
            'status' => YoutubeChannelStatus::Queued,
            'last_error' => null,
            'video_fetch_progress_current' => null,
            'video_fetch_progress_total' => null,
        ]);
    }

    public function markDeleting(): void
    {
        $this->persistState([
            'status' => YoutubeChannelStatus::Deleting,
            'video_fetch_progress_current' => null,
            'video_fetch_progress_total' => null,
        ]);
    }

    public function markFetchingVideoList(): void
    {
        $this->persistState([
            'status' => YoutubeChannelStatus::FetchingVideoList,
            'last_error' => null,
            'active_video_batch_id' => null,
            'video_fetch_progress_current' => null,
            'video_fetch_progress_total' => null,
        ]);
    }

    public function markFetchingVideos(?string $lastVideoId = null, ?int $total = null): void
    {
        $attributes = [
            'status' => YoutubeChannelStatus::FetchingVideos,
            'video_fetch_progress_current' => null,
            'video_fetch_progress_total' => null,
        ];

        if ($lastVideoId !== null) {
            $attributes['last_video_id'] = $lastVideoId;
        }

        if ($total !== null && $total > 0) {
            $attributes['video_fetch_progress_current'] = 0;
            $attributes['video_fetch_progress_total'] = $total;
        }

        $this->persistState($attributes);
    }

    public function incrementVideoFetchProgress(int $incrementBy): void
    {
        if ($incrementBy <= 0) {
            return;
        }

        DB::transaction(function () use ($incrementBy): void {
            $fresh = self::query()
                ->whereKey($this->id)
                ->lockForUpdate()
                ->first();

            if (! $fresh instanceof self) {
                return;
            }

            $total = $fresh->video_fetch_progress_total;
            if (! is_int($total) || $total <= 0) {
                return;
            }

            $current = $fresh->video_fetch_progress_current;
            if (! is_int($current) || $current < 0) {
                $current = 0;
            }

            $fresh->update([
                'video_fetch_progress_current' => min($total, $current + $incrementBy),
            ]);
        });
    }

    public function markBuildingFeed(bool $clearActiveVideoBatch = false): void
    {
        $attributes = [
            'status' => YoutubeChannelStatus::BuildingFeed,
            'video_fetch_progress_current' => null,
            'video_fetch_progress_total' => null,
        ];

        if ($clearActiveVideoBatch) {
            $attributes['active_video_batch_id'] = null;
        }

        $this->persistState($attributes);
    }

    public function markIdle(): void
    {
        $this->persistState([
            'status' => YoutubeChannelStatus::Idle,
            'active_video_batch_id' => null,
            'last_sync_at' => now(),
            'last_error' => null,
            'video_fetch_progress_current' => null,
            'video_fetch_progress_total' => null,
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->persistState([
            'status' => YoutubeChannelStatus::Failed,
            'last_error' => $error,
            'video_fetch_progress_current' => null,
            'video_fetch_progress_total' => null,
        ]);
    }

    public function markFeedFailed(string $error): void
    {
        $this->persistState([
            'status' => YoutubeChannelStatus::FeedFailed,
            'last_error' => $error,
            'video_fetch_progress_current' => null,
            'video_fetch_progress_total' => null,
        ]);
    }

    #[Scope]
    protected function busy(Builder $query): void
    {
        $query->whereIn('status', YoutubeChannelStatus::busyValues());
    }

    #[Scope]
    protected function notBusy(Builder $query): void
    {
        $query->whereNotIn('status', YoutubeChannelStatus::busyValues());
    }

    #[Scope]
    protected function eligibleForMaintenance(Builder $query): void
    {
        $query->notBusy();
    }

    public function getRssUrlAttribute(): string
    {
        return rtrim(config('app.url'), '/') . '/feeds/' . $this->youtube_id;
    }

    public function getYoutubeUrlAttribute(): string
    {
        $youtubeId = is_string($this->youtube_id) ? $this->youtube_id : '';
        $youtubeChannelId = is_string($this->youtube_channel_id) ? $this->youtube_channel_id : null;

        return YoutubeChannelReference::canonicalUrl($youtubeId, $youtubeChannelId);
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return $this->resolvedStatus()?->badgeClass() ?? 'completed';
    }

    public function getStatusLabelAttribute(): string
    {
        $status = $this->resolvedStatus();
        if ($status instanceof YoutubeChannelStatus) {
            $progressLabel = $this->videoFetchProgressLabel();
            if (
                $progressLabel !== null
                && $status === YoutubeChannelStatus::FetchingVideos
            ) {
                return $status->value . ' (' . $progressLabel . ')';
            }

            return $status->value;
        }

        $rawStatus = $this->status;

        return is_string($rawStatus) && $rawStatus !== '' ? $rawStatus : 'unknown';
    }

    public function videos(): HasMany
    {
        return $this->hasMany(YoutubeVideo::class);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function persistState(array $attributes): void
    {
        $this->fill($attributes);
        $this->save();
    }

    private function videoFetchProgressLabel(): ?string
    {
        $total = $this->video_fetch_progress_total;
        if (! is_int($total) || $total <= 0) {
            return null;
        }

        $current = $this->video_fetch_progress_current;
        if (! is_int($current) || $current < 0) {
            $current = 0;
        }

        $current = min($current, $total);

        return $current . ' out of ' . $total;
    }
}
