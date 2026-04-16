<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminUiAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedUser = (string) env('ADMIN_UI_USER', 'admin');
        $expectedPass = (string) env('ADMIN_UI_PASSWORD', 'change_me');

        $user = (string) ($request->getUser() ?? '');
        $pass = (string) ($request->getPassword() ?? '');

        if (hash_equals($expectedUser, $user) && hash_equals($expectedPass, $pass)) {
            return $next($request);
        }

        return response('Unauthorized', 401, [
            'WWW-Authenticate' => 'Basic realm="haojixing-admin"',
        ]);
    }
}
