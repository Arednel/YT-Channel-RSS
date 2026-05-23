<?php

use App\Http\Controllers\OptionsController;
use App\Http\Controllers\YoutubeChannelController;
use Illuminate\Support\Facades\Route;

// Channels page
Route::get('/', [YoutubeChannelController::class, 'index'])
    ->name('index');

// Feeds
Route::get('/feeds/{youtubeChannel:youtube_id}.xml', [YoutubeChannelController::class, 'feed'])
    ->name('feeds.show');

// Options
Route::get('/options', [OptionsController::class, 'index'])
    ->name('options');
