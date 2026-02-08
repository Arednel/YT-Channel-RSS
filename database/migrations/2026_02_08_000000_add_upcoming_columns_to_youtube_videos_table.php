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
        Schema::table('youtube_videos', function (Blueprint $table) {
            $table->boolean('is_upcoming')->default(false)->after('updated_date')->index();
            $table->timestamp('scheduled_start_at')->nullable()->after('is_upcoming')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('youtube_videos', function (Blueprint $table) {
            $table->dropColumn(['is_upcoming', 'scheduled_start_at']);
        });
    }
};

