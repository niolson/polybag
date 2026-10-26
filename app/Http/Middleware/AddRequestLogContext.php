<?php

namespace App\Http\Middleware;

use App\Support\LivewireRequestWork;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the request on every log line it writes, so a fatal error that kills a
 * web request says which one. PHP reports a fatal with no request around it,
 * and every Livewire round trip posts to the same URL.
 *
 * The path and the Livewire work are names only: no query string, parameter or
 * value, which carry recipient data and tokens. (Global middleware runs before
 * routing, so the route's name is not known yet.)
 */
class AddRequestLogContext
{
    public function handle(Request $request, Closure $next): Response
    {
        Context::add('request', array_filter([
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'livewire' => $request->hasHeader('X-Livewire') ? LivewireRequestWork::of($request) : null,
        ], fn (mixed $value): bool => $value !== null));

        return $next($request);
    }
}
