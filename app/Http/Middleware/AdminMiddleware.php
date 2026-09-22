<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        if (!auth()->check()) {
            return redirect()
                ->route('admin.login')
                ->with('error', 'Please login first.');
        }

        if (!auth()->user()->isAdmin()) {
            abort(403, 'Administrator access required.');
        }

        return $next($request);
    }
}