<?php

namespace App\Http\Middleware;

use App\Services\ActingForemanService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends acting foreman covers whose time is up before the request reads crew
 * leadership (Phase 7 — UC-06).
 *
 * The scheduler does the same thing, but a dev machine or a server whose
 * cron has stopped would otherwise leave a cover running past its expiry —
 * the regular foreman locked out of their own crew, and the acting foreman's
 * late taps accepted. Checking here makes the expiry hold regardless.
 */
class EndExpiredActingCovers
{
    public function __construct(private ActingForemanService $acting) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->acting->endExpired();

        return $next($request);
    }
}
