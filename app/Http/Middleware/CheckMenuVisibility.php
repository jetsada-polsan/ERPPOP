<?php

namespace App\Http\Middleware;

use App\Support\MenuVisibility;
use Closure;
use Illuminate\Http\Request;

class CheckMenuVisibility
{
    public function handle(Request $request, Closure $next)
    {
        $route = $request->route()?->getName();
        if ($route && MenuVisibility::blocked($route, $request->query())) {
            abort_unless($request->user()?->hasPermission('settings.manage'), 404);
            $request->attributes->set('closed_menu', true);
        }

        return $next($request);
    }
}
