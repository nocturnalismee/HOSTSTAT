<?php
declare(strict_types=1);

/**
 * Server-Sent Events (SSE) helper functions for servmon.
 *
 * Usage:
 *   sse_headers();
 *   sse_send('status', $data);
 *   sse_keepalive();
 */

/**
 * Send all required SSE response headers and disable output buffering.
 */
function sse_headers(): void
{
    // Prevent any PHP/web-server buffering
    @ini_set('output_buffering', 'Off');
    @ini_set('zlib.output_compression', '0');
    @ini_set('implicit_flush', '1');

    // Unlimited execution time for the SSE loop
    set_time_limit(0);

    // Close connection cleanly when client disconnects
    ignore_user_abort(false);

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no'); // nginx proxy buffering off

    // Flush any existing output buffers
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
}

/**
 * Send an SSE event to the client.
 *
 * @param string $event  Event name (e.g. 'status', 'alerts')
 * @param mixed  $data   Data payload — will be JSON-encoded if not a string
 * @param string|null $id  Optional event ID for Last-Event-ID tracking
 */
function sse_send(string $event, mixed $data, ?string $id = null): void
{
    if ($id !== null) {
        echo "id: {$id}\n";
    }
    echo "event: {$event}\n";

    $encoded = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // SSE data field: each line must be prefixed with "data: "
    foreach (explode("\n", (string) $encoded) as $line) {
        echo "data: {$line}\n";
    }

    echo "\n"; // End of message (blank line)

    // Force flush to send data immediately
    if (function_exists('fastcgi_finish_request')) {
        // Don't call fastcgi_finish_request here — it would end the connection
    }
    @flush();
}

/**
 * Send an SSE comment as keepalive to prevent proxy/browser timeout.
 */
function sse_keepalive(): void
{
    echo ": keepalive " . time() . "\n\n";
    @flush();
}

/**
 * Check if the client is still connected.
 */
function sse_client_connected(): bool
{
    // Write empty comment to detect disconnection
    echo ": \n";
    @flush();
    return !connection_aborted();
}
