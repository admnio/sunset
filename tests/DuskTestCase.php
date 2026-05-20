<?php

namespace Admnio\Sunset\Tests;

use Admnio\Sunset\SunsetServiceProvider;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Orchestra\Testbench\Dusk\TestCase as BaseDuskTestCase;

abstract class DuskTestCase extends BaseDuskTestCase
{
    public static function prepare(): void
    {
        // Don't auto-start ChromeDriver — CI/local provides it.
    }

    /**
     * Don't let testbench-dusk try to launch its bundled ChromeDriver binary.
     *
     * testbench-dusk's `setUpBeforeClass()` calls `defineChromeDriver()` which
     * in turn calls Laravel Dusk's `startChromeDriver()`, which insists on a
     * specific platform-named binary in vendor/laravel/dusk/bin/. We rely on
     * an externally-running ChromeDriver on port 9515 (CI starts it; local
     * developers run it manually), so we no-op here. The per-test setUp guard
     * still skips cleanly when ChromeDriver isn't reachable.
     */
    protected static function defineChromeDriver(): void
    {
        // Intentionally empty.
    }

    /**
     * Skip browser tests up-front if ChromeDriver isn't reachable on port 9515.
     *
     * Testbench-dusk boots the package providers into a temporary Laravel app
     * and serves it, but Dusk still needs a running ChromeDriver to talk to.
     * Detecting reachability first keeps local runs (no ChromeDriver) from
     * crashing during setUp.
     */
    protected function setUp(): void
    {
        if (! $this->chromeDriverReachable()) {
            $this->markTestSkipped(
                'ChromeDriver not running on http://localhost:9515. '
                .'Start it with: chromedriver --port=9515'
            );
        }

        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            SunsetServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Mirror tests/TestCase.php so the dashboard boots into the same
        // queue/redis/sunset configuration the rest of the suite uses.
        $app['config']->set('queue.default', 'sqs');
        $app['config']->set('queue.connections.sqs', [
            'driver' => 'sqs',
            'key' => 'test',
            'secret' => 'test',
            'prefix' => 'http://localhost:4566/000000000000',
            'queue' => 'default',
            'suffix' => '',
            'region' => 'us-east-1',
        ]);
        $app['config']->set('queue.connections.redis', [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'default',
            'retry_after' => 60,
            'block_for' => null,
        ]);
        $app['config']->set('database.redis.default', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'database' => 1,
        ]);
        $app['config']->set('sunset.redis_connection', 'default');

        // Sunset's localhost-only auth gate honours `app.env === 'local'`,
        // which Testbench's `testing` default would otherwise block.
        $app['config']->set('app.env', 'local');
    }

    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions())->addArguments([
            '--disable-gpu',
            '--headless=new',
            '--no-sandbox',
            '--window-size=1400,900',
        ]);

        return RemoteWebDriver::create(
            'http://localhost:9515',
            DesiredCapabilities::chrome()->setCapability(ChromeOptions::CAPABILITY, $options)
        );
    }

    private function chromeDriverReachable(): bool
    {
        $fp = @fsockopen('127.0.0.1', 9515, $errno, $errstr, 0.5);
        if ($fp === false) {
            return false;
        }
        fclose($fp);

        return true;
    }
}
