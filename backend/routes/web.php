<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Exists purely so `route('login')` resolves. Authenticate computes the
// redirect target at throw time for non-JSON requests
// (vendor/.../Authenticate.php:104 — `expectsJson() ? null : redirectTo()`),
// and the framework default target is route('login')
// (ApplicationBuilder.php:291). Without a route of that name, a no-Accept
// unauthenticated /api request explodes into RouteNotFoundException (a 500)
// before the AuthenticationException can reach the handler, whose
// shouldRenderJsonWhen(api/*) would otherwise render it as 401 JSON. The SPA
// owns the real login screen via nginx's index.html fallback; this endpoint is
// never actually served.
Route::get('/login', fn () => redirect('/'))->name('login');
