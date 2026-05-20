<?php

namespace Admnio\Sunset\Tests\Unit\Dashboard\Http\Middleware;

use Admnio\Sunset\Dashboard\Http\Middleware\Authorize;
use Admnio\Sunset\Manager;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthorizeTest extends TestCase
{
    public function test_passes_when_manager_check_returns_true(): void
    {
        $manager = Mockery::mock(Manager::class);
        $manager->shouldReceive('check')->andReturn(true);

        $middleware = new Authorize($manager);
        $response = $middleware->handle(Request::create('/sunset'), fn () => new Response('ok'));

        $this->assertSame('ok', $response->getContent());
    }

    public function test_aborts_403_when_manager_check_returns_false(): void
    {
        $manager = Mockery::mock(Manager::class);
        $manager->shouldReceive('check')->andReturn(false);

        $middleware = new Authorize($manager);

        $this->expectException(HttpException::class);
        $middleware->handle(Request::create('/sunset'), fn () => new Response('ok'));
    }

    protected function tearDown(): void { Mockery::close(); parent::tearDown(); }
}
