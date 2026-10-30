<?php

use App\Http\Controllers\EventIndexController;
use App\Http\Controllers\PostbackController;
use Illuminate\Support\Facades\Route;

Route::get('/postback', PostbackController::class);
Route::get('/api/events', EventIndexController::class);
