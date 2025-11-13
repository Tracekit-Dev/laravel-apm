<?php

if (!function_exists('tracekit_snapshot')) {
    /**
     * Capture a snapshot at the current code location
     *
     * @param string $label A descriptive label for this capture point
     * @param array $variables Additional variables to capture
     */
    function tracekit_snapshot(string $label, array $variables = []): void
    {
        if (!config('tracekit.enabled') || !config('tracekit.code_monitoring.enabled')) {
            return;
        }

        try {
            $snapshotClient = app(\TraceKit\Laravel\SnapshotClient::class);
            $snapshotClient->checkAndCaptureWithContext(null, $label, $variables);
        } catch (\Exception $e) {
            // Log error but don't break the application
            if (function_exists('logger')) {
                logger()->warning('TraceKit: Failed to capture snapshot', [
                    'error' => $e->getMessage(),
                    'label' => $label
                ]);
            }
        }
    }
}

if (!function_exists('tracekit_debug')) {
    /**
     * Debug helper that captures a snapshot and also logs to Laravel log
     *
     * @param string $label A descriptive label for this debug point
     * @param array $variables Additional variables to capture and log
     */
    function tracekit_debug(string $label, array $variables = []): void
    {
        // Log to Laravel logger
        logger()->info("TraceKit Debug: {$label}", $variables);

        // Capture snapshot
        tracekit_snapshot($label, $variables);
    }
}

if (!function_exists('tracekit_error_snapshot')) {
    /**
     * Capture a snapshot when an error occurs
     *
     * @param \Throwable $exception The exception that occurred
     * @param array $additionalContext Additional context to capture
     */
    function tracekit_error_snapshot(\Throwable $exception, array $additionalContext = []): void
    {
        if (!config('tracekit.enabled') || !config('tracekit.code_monitoring.enabled')) {
            return;
        }

        $context = array_merge($additionalContext, [
            'exception_type' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);

        tracekit_snapshot('exception_' . class_basename($exception), $context);
    }
}
