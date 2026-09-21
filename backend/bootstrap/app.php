<?php

use App\Http\Middleware\EndExpiredActingCovers;
use App\Http\Middleware\EnsureRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureRole::class,
            'acting.expire' => EndExpiredActingCovers::class,
        ]);

        // nginx and cloudflared sit on the Compose bridge; real LAN/Tailscale
        // clients never do. Trusting only that range makes the XFF value nginx
        // appends authoritative, so a spoofed client-sent X-Forwarded-For is
        // ignored and audit logs record real client IPs. Deliberately limited
        // to the two headers nginx mirrors (Host/Port stay untrusted). The
        // CIDR is hardcoded because env() is unreliable under php-fpm's
        // clear_env=yes and the bridge is topology, not configuration.
        $middleware->trustProxies(
            at: ['172.16.0.0/12'],
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
