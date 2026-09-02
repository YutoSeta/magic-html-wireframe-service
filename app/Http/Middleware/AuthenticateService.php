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
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $provided = (string) $request->bearerToken();
        $expectedTokens = array_filter([
            (string) config('wireframe.service_token', ''),
            $scope === 'layout' ? (string) config('wireframe.layout_service_token', '') : '',
        ], static fn (string $token): bool => $token !== '');
        $authenticated = $provided !== '' && array_any(
            $expectedTokens,
            static fn (string $expected): bool => hash_equals($expected, $provided),
        );
        if (! $authenticated) {
            return Problem::response($request, 401, 'unauthorized', 'A valid bearer token is required.');
        }

        return $next($request);
    }
}
