<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// The guard's redirect target for unauthenticated, non-JSON requests (e.g. a
// browser hitting /api/...). The SPA owns the real /login screen via nginx's
// index.html fallback, so this only exists to stop `route('login')` throwing
// RouteNotFoundException and turning a 401 into a 500.
Route::get('/login', fn () => redirect('/'))->name('login');
