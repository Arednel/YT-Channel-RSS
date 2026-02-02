<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('youtube_videos', function (Blueprint $table) {
            $table->id();
            $table->string('youtube_video_id')->unique();
            $table->foreignId('youtube_channel_id')
                ->constrained('youtube_channels')
                ->cascadeOnDelete();
            $table->string('video_title');
            $table->timestamp('published_date');
            $table->timestamp('updated_date');
            $table->string('media_title');
            $table->string('media_content_url');
            $table->string('media_thumbnail_url');
            $table->text('media_description');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('youtube_videos');
    }
};
