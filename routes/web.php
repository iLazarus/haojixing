<?php

use App\Http\Controllers\Webhook\TelegramWebhookController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/admin', function () {
    return view('admin');
})->middleware('admin.ui.auth');

Route::post('/webhook', [TelegramWebhookController::class, 'handle'])
    ->withoutMiddleware([PreventRequestForgery::class]);
