<?php

namespace App\Models;

use App\Enums\YoutubeChannelStatus;
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

    public function getRssUrlAttribute(): string
    {
        return rtrim(config('app.url'), '/') . '/feeds/' . $this->youtube_id;
    }

    public function getStatusBadgeClassAttribute(): string
    {
        $status = $this->status;
        if ($status instanceof YoutubeChannelStatus) {
            return $status->badgeClass();
        }

        $resolved = is_string($status) ? YoutubeChannelStatus::tryFrom($status) : null;

        return $resolved?->badgeClass() ?? 'completed';
    }

    public function getStatusLabelAttribute(): string
    {
        $status = $this->status;
        if ($status instanceof YoutubeChannelStatus) {
            return $status->value;
        }

        return is_string($status) && $status !== '' ? $status : 'unknown';
    }

    public function videos(): HasMany
    {
        return $this->hasMany(YoutubeVideo::class);
    }
}
