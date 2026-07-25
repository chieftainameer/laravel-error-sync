<?php

namespace NativePHP\ErrorSync\Tests;

use NativePHP\ErrorSync\Services\ErrorCaptureService;
use NativePHP\ErrorSync\Services\PayloadBuilder;
use NativePHP\ErrorSync\Services\RelayClient;
use Orchestra\Testbench\TestCase;

class ErrorCaptureServiceTest extends TestCase
{
    public function test_capture_service_preserves_screenshot_when_custom_builder_omits_it(): void
    {
        config()->set('error-sync.collect.screenshot', true);
        $relay = new class extends RelayClient {
            public array $payload = [];
            public function __construct() { parent::__construct('http://localhost'); }
            public function send(array $payload): bool { $this->payload = $payload; return true; }
        };
        $builder = new class extends PayloadBuilder {
            public function build(
                string $trigger,
                array $phpErrors,
                array $jsErrors,
                array $networkLog,
                array $userActions,
                array $consoleLogs,
                ?string $screenshot = null
            ): array {
                return ['trigger' => $trigger];
            }
        };
        $service = new ErrorCaptureService($relay, $builder);
        $image = 'data:image/jpeg;base64,' . base64_encode('test-image');
        $service->setScreenshot($image);
        $service->setScreenshotDiagnostic('annotated; annotations: 2');

        $result = $service->captureAndSend('manual_button');

        $this->assertTrue($result['success']);
        $this->assertSame($image, $relay->payload['screenshot']);
        $this->assertSame('annotated; annotations: 2', $relay->payload['screenshotDiagnostic']);
    }
}
