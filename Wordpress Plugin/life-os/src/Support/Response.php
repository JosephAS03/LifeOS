<?php

declare(strict_types=1);

namespace LifeOS\Support;

use WP_REST_Response;

final class Response
{
    public static function success(array $data = [], int $status = 200): WP_REST_Response
    {
        return new WP_REST_Response(
            [
                'success' => true,
                'data' => $data,
            ],
            $status
        );
    }

    public static function error(string $code, string $message, int $status = 400, array $details = []): WP_REST_Response
    {
        return new WP_REST_Response(
            [
                'success' => false,
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'details' => $details,
                    'request_id' => wp_generate_uuid4(),
                ],
            ],
            $status
        );
    }
}

