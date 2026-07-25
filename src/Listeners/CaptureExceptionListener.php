<?php
// src/Listeners/CaptureExceptionListener.php

namespace NativePHP\ErrorSync\Listeners;

use Illuminate\Log\Events\MessageLogged;
use NativePHP\ErrorSync\Facades\ErrorSync;

class CaptureExceptionListener
{
    /**
     * Handle Laravel log events.
     * This catches ALL errors that Laravel processes.
     */
    public function handle(MessageLogged $event): void
    {
        // Only capture errors and critical messages
        $errorLevels = ['error', 'critical', 'alert', 'emergency'];
        
        if (in_array($event->level, $errorLevels)) {
            ErrorSync::captureAndSend('laravel_log_' . $event->level);
        }
    }
}