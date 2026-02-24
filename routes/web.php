<?php

use App\Http\Controllers\YoutubeChannelController;
use Illuminate\Support\Facades\Route;

//Main Page
Route::get('/', [YoutubeChannelController::class, 'index'])
    ->name('index');

Route::get('/feeds/{youtubeChannel:youtube_id}.xml', [YoutubeChannelController::class, 'feed'])
    ->name('feeds.show');
