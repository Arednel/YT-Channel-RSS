<?php

use App\Http\Controllers\YoutubeChannelController;
use Illuminate\Support\Facades\Route;

//Main Page
Route::get('/', [YoutubeChannelController::class, 'index'])
    ->name('index');
