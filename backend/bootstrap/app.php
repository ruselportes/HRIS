<?php

use App\Http\Middleware\EndExpiredActingCovers;
use App\Http\Middleware\EnsurePortalScope;
use App\Http\Middleware\EnsureRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

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
            'portal.scope' => EnsurePortalScope::class,
        ]);

        // The portal gate must beat model binding (a worker guessing an id gets
        // an exact 403, never a fake-id 404) and must run before acting.expire,
        // which writes to the database on the way in. Both hold because the 403
        // fires before SubstituteBindings while the group lists portal.scope
        // between auth and acting.expire.
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsurePortalScope::class);

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
