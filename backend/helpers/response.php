<?php
declare(strict_types=1);

/** Sends a JSON response and ends the current request. */
function json_response(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, max-age=0');

    try {
        echo json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    } catch (JsonException $exception) {
        error_log('JSON response encoding failed: ' . $exception->getMessage());
        http_response_code(500);
        echo '{"status":"error","success":false,"data":null,"message":"An internal server error occurred."}';
    }

    exit;
}

/** Sends a successful JSON response. */
function json_success(mixed $data = null, string $message = 'Request completed successfully.', int $statusCode = 200): never
{
    json_response([
        'status' => 'success',
        'success' => true,
        'message' => $message,
        'data' => $data,
    ], $statusCode);
}

/** Sends a failed JSON response with an optional structured error payload. */
function json_error(string $message, int $statusCode = 400, mixed $errors = null): never
{
    $payload = [
        'status' => 'error',
        'success' => false,
        'data' => $errors,
        'message' => $message,
    ];
    if ($errors !== null) {
        $payload['errors'] = $errors;
    }

    json_response($payload, $statusCode);
}
