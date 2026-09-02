<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class Problem
{
    /** @param array<string,mixed> $errors */
    public static function response(Request $request, int $status, string $type, string $detail, array $errors = []): JsonResponse
    {
        $body = [
            'contract_version' => (string) $request->input('contract_version', '1.0'),
            'type' => $type,
            'title' => Response::$statusTexts[$status],
            'status' => $status,
            'detail' => $detail,
            'request_id' => (string) ($request->header('X-Request-Id') ?: str()->uuid()),
        ];
        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status);
    }
}
