<?php

namespace NativePHP\ErrorSync\Tests;

use NativePHP\ErrorSync\Commands\InstallCommand;
use Orchestra\Testbench\TestCase;

class InstallCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            \NativePHP\ErrorSync\ErrorSyncServiceProvider::class,
        ];
    }

    public function test_it_writes_the_relay_url_to_the_active_environment_file(): void
    {
        $tempDir = $this->app->basePath('tmp-env');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        $envFile = $tempDir . DIRECTORY_SEPARATOR . '.env.testing';
        file_put_contents($envFile, "APP_NAME=Test\n");

        $this->app->useEnvironmentPath($tempDir);
        $this->app->loadEnvironmentFrom('.env.testing');

        $command = new InstallCommand();
        $command->setLaravel($this->app);

        $method = new \ReflectionMethod($command, 'updateEnvFile');
        $method->setAccessible(true);
        $method->invoke($command, 'ERROR_SYNC_RELAY_URL', 'http://192.168.1.42:9999');

        $this->assertStringContainsString(
            'ERROR_SYNC_RELAY_URL=http://192.168.1.42:9999',
            file_get_contents($envFile)
        );
    }
}
