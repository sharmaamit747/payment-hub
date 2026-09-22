<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    public function handle(
        Request $request,
        Closure $next,
        string $permission
    ): Response {
        if (!auth()->check()) {
            return redirect()->route('admin.login');
        }

        if (!auth()->user()->hasPermission($permission)) {
            abort(
                403,
                'You do not have permission to perform this action.'
            );
        }

        return $next($request);
    }
}