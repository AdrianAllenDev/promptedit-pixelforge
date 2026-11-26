<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('admin')->check()) {
            return $next($request);
        }
        abort_if(Auth::guard('web')->check(), 403);
        abort_if($request->expectsJson(), 401);

        return redirect()->route('admin.login');
    }
}
