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
            $table->string('youtube_channel_id')->nullable()->after('youtube_id');
            $table->unique('youtube_channel_id', 'youtube_channels_youtube_channel_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('youtube_channels', function (Blueprint $table) {
            $table->dropUnique('youtube_channels_youtube_channel_id_unique');
            $table->dropColumn('youtube_channel_id');
        });
    }
};
