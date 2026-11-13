<?php

declare(strict_types=1);

use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Middleware\IdentifyStudent;
use Illuminate\Support\Facades\Route;

Route::middleware(IdentifyStudent::class)->group(function (): void {
    Route::post('/sessions/{session}/bookings', [BookingController::class, 'store'])->whereNumber('session');
    Route::delete('/bookings/{booking}', [BookingController::class, 'destroy'])->whereNumber('booking');
    Route::get('/wallets/me', [WalletController::class, 'show']);
});
