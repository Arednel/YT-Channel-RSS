<?php

namespace App\Models;

use App\Enums\YoutubeChannelStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'channel_name',
        'status',
        'last_sync_at',
        'last_video_id',
        'last_error',
        'active_video_batch_id',
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

    public function markQueuedForSync(): void
    {
        $this->persistState([
            'status' => YoutubeChannelStatus::Queued,
            'last_error' => null,
        ]);
    }

    public function markDeleting(): void
    {
        $this->persistState([
            'status' => YoutubeChannelStatus::Deleting,
        ]);
    }

    public function markFetchingVideoList(): void
    {
        $this->persistState([
            'status' => YoutubeChannelStatus::FetchingVideoList,
            'last_error' => null,
            'active_video_batch_id' => null,
        ]);
    }

    public function markFetchingVideos(?string $lastVideoId = null): void
    {
        $attributes = [
            'status' => YoutubeChannelStatus::FetchingVideos,
        ];

        if ($lastVideoId !== null) {
            $attributes['last_video_id'] = $lastVideoId;
        }

        $this->persistState($attributes);
    }

    public function markBuildingFeed(bool $clearActiveVideoBatch = false): void
    {
        $attributes = [
            'status' => YoutubeChannelStatus::BuildingFeed,
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
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->persistState([
            'status' => YoutubeChannelStatus::Failed,
            'last_error' => $error,
        ]);
    }

    public function markFeedFailed(string $error): void
    {
        $this->persistState([
            'status' => YoutubeChannelStatus::FeedFailed,
            'last_error' => $error,
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

    public function getStatusBadgeClassAttribute(): string
    {
        return $this->resolvedStatus()?->badgeClass() ?? 'completed';
    }

    public function getStatusLabelAttribute(): string
    {
        $status = $this->resolvedStatus();
        if ($status instanceof YoutubeChannelStatus) {
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
}
