<?php

namespace App\Http\Middleware;

use App\Support\Problem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateService
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('wireframe.service_token', '');
        $provided = (string) $request->bearerToken();
        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return Problem::response($request, 401, 'unauthorized', 'A valid bearer token is required.');
        }

        return $next($request);
    }
}
