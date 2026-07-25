<?php
// src/Facades/ErrorSync.php

namespace NativePHP\ErrorSync\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array captureAndSend(string $trigger = 'manual')
 * @method static void captureJsError(array $jsError)
 * @method static void logNetwork(array $data)
 * @method static void logUserAction(string $action, array $context = [])
 * @method static void logConsole(string $level, string $message)
 * @method static array getBufferStats()
 *
 * @see \NativePHP\ErrorSync\Services\ErrorCaptureService
 */
class ErrorSync extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'error-sync';
    }
}