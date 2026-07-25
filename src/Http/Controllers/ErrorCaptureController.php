<?php
// src/Http/Controllers/ErrorCaptureController.php

namespace NativePHP\ErrorSync\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use NativePHP\ErrorSync\Services\ErrorCaptureService;

class ErrorCaptureController extends Controller
{
    public function captureJsError(Request $request, ErrorCaptureService $service)
    {
        $service->captureJsError($request->all());
        return response()->json(['status' => 'logged']);
    }

    public function logNetwork(Request $request, ErrorCaptureService $service)
    {
        $service->logNetwork($request->all());
        return response()->json(['status' => 'logged']);
    }

    public function logAction(Request $request, ErrorCaptureService $service)
    {
        $service->logUserAction(
            $request->input('action', 'unknown'),
            $request->input('context', [])
        );
        return response()->json(['status' => 'logged']);
    }

    public function logConsole(Request $request, ErrorCaptureService $service)
    {
        $service->logConsole(
            $request->input('level', 'log'),
            $request->input('message', '')
        );
        return response()->json(['status' => 'logged']);
    }

    public function triggerCapture(Request $request, ErrorCaptureService $service)
    {
        $trigger = $request->input('trigger', 'manual_api');
        
        // Pass screenshot data to the service
        $screenshot = $request->input('screenshot');
        if ($screenshot) {
            $service->setScreenshot($screenshot);
        }

        $service->setScreenshotDiagnostic(
            $request->input('screenshotDiagnostic', 'No client diagnostic provided')
        );
        $service->logConsole(
            'info',
            '[ErrorSync Screenshot] ' . $request->input('screenshotDiagnostic', 'No client diagnostic provided')
        );
        
        $result = $service->captureAndSend($trigger);
        return response()->json($result);
    }

    public function stats(ErrorCaptureService $service)
    {
        return response()->json($service->getBufferStats());
    }
}
