<?php

namespace App\Models;

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
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_sync_at' => 'datetime',
        ];
    }

    public function videos(): HasMany
    {
        return $this->hasMany(YoutubeVideo::class);
    }
}
