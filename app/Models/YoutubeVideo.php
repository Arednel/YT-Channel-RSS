<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class YoutubeVideo extends Model
{
    /** @use HasFactory<\Database\Factories\YoutubeVideoFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'youtube_video_id',
        'youtube_channel_id',
        'video_title',
        'published_date',
        'updated_date',
        'media_title',
        'media_content_url',
        'media_thumbnail_url',
        'media_description',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_date' => 'datetime',
            'updated_date' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(YoutubeChannel::class, 'youtube_channel_id');
    }
}
