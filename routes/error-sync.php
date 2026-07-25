<?php
// routes/error-sync.php

use Illuminate\Support\Facades\Route;
use NativePHP\ErrorSync\Http\Controllers\ErrorCaptureController;

Route::prefix('_error-sync')->middleware('error-sync')->group(function () {
    Route::post('/js-error', [ErrorCaptureController::class, 'captureJsError']);
    Route::post('/network', [ErrorCaptureController::class, 'logNetwork']);
    Route::post('/action', [ErrorCaptureController::class, 'logAction']);
    Route::post('/console', [ErrorCaptureController::class, 'logConsole']);
    Route::post('/capture', [ErrorCaptureController::class, 'triggerCapture']);
    Route::get('/stats', [ErrorCaptureController::class, 'stats']);
});