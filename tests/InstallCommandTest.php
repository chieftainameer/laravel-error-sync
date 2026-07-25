<?php

namespace NativePHP\ErrorSync\Tests;

use Illuminate\Console\OutputStyle;
use NativePHP\ErrorSync\Commands\InstallCommand;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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

    public function test_it_injects_dev_tools_when_given_an_absolute_layout_path(): void
    {
        $viewsDir = $this->app->resourcePath('views/layouts');
        if (!is_dir($viewsDir)) {
            mkdir($viewsDir, 0777, true);
        }

        $layoutPath = $viewsDir . DIRECTORY_SEPARATOR . 'app.blade.php';
        file_put_contents($layoutPath, "<html>\n<body>\n</body>\n</html>\n");

        $command = new InstallCommand();
        $command->setLaravel($this->app);

        $output = new OutputStyle(new ArrayInput([]), new BufferedOutput());
        $command->setOutput($output);

        $method = new \ReflectionMethod($command, 'injectDevTools');
        $method->setAccessible(true);
        $method->invoke($command, $layoutPath);

        $this->assertStringContainsString('<x-error-sync::dev-tools />', file_get_contents($layoutPath));
    }
}
