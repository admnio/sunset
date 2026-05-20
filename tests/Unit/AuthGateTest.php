<?php

namespace Admnio\Sunset\Tests\Unit;

use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\Manager;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Http\Request;

class AuthGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Manager::flushAuth();
    }

    public function test_default_allows_localhost_in_non_local_env(): void
    {
        config()->set('app.env', 'production');
        $request = Request::create('/sunset', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']);

        $this->assertTrue($this->app->make(Manager::class)->check($request));
    }

    public function test_default_denies_remote_in_non_local_env(): void
    {
        config()->set('app.env', 'production');
        $request = Request::create('/sunset', 'GET', server: ['REMOTE_ADDR' => '203.0.113.1']);

        $this->assertFalse($this->app->make(Manager::class)->check($request));
    }

    public function test_default_allows_anything_in_local_env(): void
    {
        config()->set('app.env', 'local');
        $request = Request::create('/sunset', 'GET', server: ['REMOTE_ADDR' => '203.0.113.1']);

        $this->assertTrue($this->app->make(Manager::class)->check($request));
    }

    public function test_custom_callback_overrides_default(): void
    {
        config()->set('app.env', 'production');
        Sunset::auth(fn ($req) => $req->ip() === '203.0.113.1');

        $request = Request::create('/sunset', 'GET', server: ['REMOTE_ADDR' => '203.0.113.1']);
        $this->assertTrue($this->app->make(Manager::class)->check($request));

        $request = Request::create('/sunset', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $this->assertFalse($this->app->make(Manager::class)->check($request));
    }
}
