<?php

namespace NativePHP\ErrorSync\Tests;

use NativePHP\ErrorSync\ErrorSyncServiceProvider;
use Orchestra\Testbench\TestCase;

class AnnotationEditorAssetsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ErrorSyncServiceProvider::class];
    }

    public function test_annotation_editor_is_enabled_and_packaged_by_default(): void
    {
        $this->assertTrue(config('error-sync.screenshot_editor.enabled'));
        $this->assertSame(0.72, config('error-sync.screenshot_editor.jpeg_quality'));
        $this->assertFileExists(__DIR__ . '/../resources/js/annotation-editor.js');
    }

    public function test_dev_tools_component_embeds_the_editor_and_exposes_its_configuration(): void
    {
        $view = file_get_contents(__DIR__ . '/../resources/views/components/dev-tools.blade.php');

        $this->assertStringContainsString('annotationEditorSource', $view);
        $this->assertStringContainsString('screenshotEditor:', $view);
        $this->assertStringContainsString('screenshotEditorQuality:', $view);
    }

    public function test_installer_publishes_the_annotation_editor_asset(): void
    {
        $installer = file_get_contents(__DIR__ . '/../src/Commands/InstallCommand.php');

        $this->assertStringContainsString("resources/js/annotation-editor.js", $installer);
        $this->assertStringContainsString("annotation-editor.js'", $installer);
    }
}
