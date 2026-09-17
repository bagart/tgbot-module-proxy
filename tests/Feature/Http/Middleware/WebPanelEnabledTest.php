<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Http\Laravel\Middleware\WebPanelEnabled;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('aborts 403 when web panel is disabled', function (): void {
    config(['proxy-operations.web_panel.enabled' => false]);

    $middleware = new WebPanelEnabled;
    $request = Request::create('/test', 'GET');

    try {
        $middleware->handle($request, function () {
            return response('ok');
        });
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);

        return;
    }

    $this->fail('Expected HttpException with 403');
});

it('passes through when web panel is enabled', function (): void {
    config(['proxy-operations.web_panel.enabled' => true]);

    $middleware = new WebPanelEnabled;
    $request = Request::create('/test', 'GET');

    $response = $middleware->handle($request, function () {
        return response('ok');
    });

    expect($response->getContent())->toBe('ok');
});
