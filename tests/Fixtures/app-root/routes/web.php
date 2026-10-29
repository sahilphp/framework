<?php

declare(strict_types=1);

use Sahil\Contracts\View\ViewContract;
use Sahil\Http\Response;
use Sahil\Http\Route;

/**
 * Minimal HTTP surface for the framework application fixture.
 *
 * Tests that need their own routes register them with withRoutes(); these
 * routes cover the application-shaped examples that expect a real home page
 * and health endpoint.
 *
 * @return list<Route>
 */
return [
    Route::get('/health', static fn (): Response => Response::json([
        'status' => 'ok',
        'booted' => true,
    ])),

    Route::get('/', static function (): Response {
        $html = app()->container()->make(ViewContract::class)->render('pages.home', [
            'title' => 'Home',
            'heading' => 'SahilPHP templates',
            'posts' => [],
            'attribution' => '<script>alert(1)</script>',
            'rendered_at' => 'now',
        ]);

        return Response::html($html);
    }),

    Route::get('/ui-kit', static function (): Response {
        $html = app()->container()->make(ViewContract::class)->render('pages.ui-kit');

        return Response::html($html);
    }),
];
