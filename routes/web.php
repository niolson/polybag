<?php

use App\Http\Controllers\QzSignController;
use Illuminate\Support\Facades\Route;

Route::post('/qz/sign', [QzSignController::class, 'sign'])->name('qz.sign')->middleware(['auth', 'throttle:60,1']);
