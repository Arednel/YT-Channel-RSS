<?php

use Illuminate\Support\Facades\Route;

//Main Page
Route::get('/', function () {
    return view('Index');
})->name('index');
