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
        Schema::table('youtube_channels', function (Blueprint $table) {
            $table->unsignedInteger('video_fetch_progress_current')->nullable()->after('active_video_batch_id');
            $table->unsignedInteger('video_fetch_progress_total')->nullable()->after('video_fetch_progress_current');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('youtube_channels', function (Blueprint $table) {
            $table->dropColumn([
                'video_fetch_progress_current',
                'video_fetch_progress_total',
            ]);
        });
    }
};
