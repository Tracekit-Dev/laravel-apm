<?php

namespace TraceKit\Laravel;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SnapshotClient
{
    private string $apiKey;
    private string $baseURL;
    private string $serviceName;
    private int $pollInterval;
    private int $maxVariableDepth;
    private int $maxStringLength;

    public function __construct(
        string $apiKey,
        string $baseURL,
        string $serviceName,
        int $pollInterval = 30,
        int $maxVariableDepth = 3,
        int $maxStringLength = 1000
    ) {
        $this->apiKey = $apiKey;
        $this->baseURL = $baseURL;
        $this->serviceName = $serviceName;
        $this->pollInterval = $pollInterval;
        $this->maxVariableDepth = $maxVariableDepth;
        $this->maxStringLength = $maxStringLength;
    }

    /**
     * Start background polling for breakpoints
     */
    public function start(): void
    {
        // Initial fetch
        $this->fetchActiveBreakpoints();

        // Schedule breakpoint polling using Laravel scheduler based on configured interval
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        
        $task = $schedule->call(function () {
            $this->pollOnce();
        });

        // Map poll interval to Laravel scheduler methods
        match (true) {
            $this->pollInterval <= 1 => $task->everySecond(),
            $this->pollInterval <= 5 => $task->everyFiveSeconds(),
            $this->pollInterval <= 10 => $task->everyTenSeconds(),
            $this->pollInterval <= 15 => $task->everyFifteenSeconds(),
            $this->pollInterval <= 30 => $task->everyThirtySeconds(),
            $this->pollInterval <= 60 => $task->everyMinute(),
            $this->pollInterval <= 300 => $task->everyFiveMinutes(),
            default => $task->everyTenMinutes(),
        };

        Log::info("📸 TraceKit Snapshot Client started for service: {$this->serviceName} (polling every {$this->pollInterval}s)");
    }

    /**
     * Stop background polling
     */
    public function stop(): void
    {
        // Laravel scheduler doesn't provide a direct way to stop recurring tasks
        // This would need to be handled at the application level
        Log::info('📸 TraceKit Snapshot Client stopped');
    }

    /**
     * Check and capture snapshot with automatic breakpoint detection
     */
    public function checkAndCaptureWithContext(
        ?array $requestContext,
        string $label,
        array $variables = []
    ): void {
        $location = $this->detectCallerLocation();
        if (!$location) {
            Log::warning('⚠️ Could not detect caller location');
            return;
        }

        $locationKey = "{$location['function']}:{$label}";

        // Check if location is registered
        if (!$this->isLocationRegistered($locationKey)) {
            // Auto-register breakpoint
            $breakpoint = $this->autoRegisterBreakpoint($location['file'], $location['line'], $location['function'], $label);
            if ($breakpoint) {
                $this->registerLocation($locationKey, $breakpoint);
            } else {
                return;
            }
        }

        // Check if breakpoint is active
        $breakpoint = $this->getActiveBreakpoint($locationKey);
        if (!$breakpoint || !($breakpoint['enabled'] ?? true)) {
            return;
        }

        // Check expiration
        if (isset($breakpoint['expire_at']) && strtotime($breakpoint['expire_at']) < time()) {
            return;
        }

        // Check max captures
        if (isset($breakpoint['max_captures']) && $breakpoint['max_captures'] > 0 &&
            ($breakpoint['capture_count'] ?? 0) >= $breakpoint['max_captures']) {
            return;
        }

        // Extract request context if not provided
        if ($requestContext === null) {
            $requestContext = $this->extractRequestContext();
        }

        // Create snapshot
        $snapshot = [
            'breakpoint_id' => $breakpoint['id'] ?? null,
            'service_name' => $this->serviceName,
            'file_path' => $location['file'],
            'function_name' => $location['function'],
            'label' => $label,
            'line_number' => $location['line'],
            'variables' => $this->sanitizeVariables($variables),
            'stack_trace' => $this->getStackTrace(),
            'request_context' => $requestContext,
            'captured_at' => now()->toISOString(),
        ];

        // Send snapshot
        $this->captureSnapshot($snapshot);
    }

    /**
     * Check and capture snapshot at specific location (manual)
     */
    public function checkAndCapture(
        string $filePath,
        int $lineNumber,
        array $variables = []
    ): void {
        $lineKey = "{$filePath}:{$lineNumber}";
        $breakpoint = $this->getActiveBreakpoint($lineKey);

        if (!$breakpoint || !($breakpoint['enabled'] ?? true)) {
            return;
        }

        $requestContext = $this->extractRequestContext();

        $snapshot = [
            'breakpoint_id' => $breakpoint['id'] ?? null,
            'service_name' => $this->serviceName,
            'file_path' => $filePath,
            'function_name' => $breakpoint['function_name'] ?? 'unknown',
            'label' => $breakpoint['label'] ?? null,
            'line_number' => $lineNumber,
            'variables' => $this->sanitizeVariables($variables),
            'stack_trace' => $this->getStackTrace(),
            'request_context' => $requestContext,
            'captured_at' => now()->toISOString(),
        ];

        $this->captureSnapshot($snapshot);
    }

    /**
     * Detect caller location using debug_backtrace
     */
    private function detectCallerLocation(): ?array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

        // Skip: detectCallerLocation, checkAndCaptureWithContext, actual caller
        $caller = $trace[2] ?? $trace[1] ?? $trace[0];

        if (!$caller) {
            return null;
        }

        return [
            'file' => $caller['file'] ?? '',
            'line' => $caller['line'] ?? 0,
            'function' => $caller['function'] ?? 'anonymous',
        ];
    }

    /**
     * Get formatted stack trace
     */
    private function getStackTrace(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $formatted = [];

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? 'unknown';
            $line = $frame['line'] ?? 0;
            $function = $frame['function'] ?? 'anonymous';
            $class = $frame['class'] ?? '';

            if ($class) {
                $formatted[] = "{$class}::{$function}({$file}:{$line})";
            } else {
                $formatted[] = "{$function}({$file}:{$line})";
            }
        }

        return implode("\n", $formatted);
    }

    /**
     * Fetch active breakpoints from backend
     */
    private function fetchActiveBreakpoints(): void
    {
        try {
            $url = "{$this->baseURL}/sdk/snapshots/active/{$this->serviceName}";

            $client = new \GuzzleHttp\Client();
            $response = $client->get($url, [
                'headers' => [
                    'X-API-Key' => $this->apiKey,
                ],
                'timeout' => 5,
            ]);

            $data = json_decode($response->getBody(), true);
            $this->updateBreakpointCache($data['breakpoints'] ?? []);

        } catch (\Exception $e) {
            Log::warning('⚠️ Failed to fetch breakpoints: ' . $e->getMessage());
        }
    }

    /**
     * Update breakpoint cache
     */
    private function updateBreakpointCache(array $breakpoints): void
    {
        $cacheKey = "tracekit_breakpoints_{$this->serviceName}";
        Cache::put($cacheKey, $breakpoints, now()->addMinutes(60));

        if (!empty($breakpoints)) {
            Log::info("📸 Updated breakpoint cache: " . count($breakpoints) . " active breakpoints");
        }
    }

    /**
     * Get active breakpoint for location
     */
    private function getActiveBreakpoint(string $locationKey): ?array
    {
        $cacheKey = "tracekit_breakpoints_{$this->serviceName}";
        $breakpoints = Cache::get($cacheKey, []);

        // Primary key: function + label
        if (strpos($locationKey, ':') !== false) {
            list($function, $label) = explode(':', $locationKey, 2);
            foreach ($breakpoints as $bp) {
                if (($bp['function_name'] ?? '') === $function && ($bp['label'] ?? '') === $label) {
                    return $bp;
                }
            }
        }

        // Secondary key: file + line
        foreach ($breakpoints as $bp) {
            $lineKey = ($bp['file_path'] ?? '') . ':' . ($bp['line_number'] ?? 0);
            if ($lineKey === $locationKey) {
                return $bp;
            }
        }

        return null;
    }

    /**
     * Check if location is registered
     */
    private function isLocationRegistered(string $locationKey): bool
    {
        $cacheKey = "tracekit_registered_locations_{$this->serviceName}";
        $registered = Cache::get($cacheKey, []);
        return in_array($locationKey, $registered);
    }

    /**
     * Register location
     */
    private function registerLocation(string $locationKey, array $breakpoint): void
    {
        $cacheKey = "tracekit_registered_locations_{$this->serviceName}";
        $registered = Cache::get($cacheKey, []);
        $registered[] = $locationKey;
        Cache::put($cacheKey, array_unique($registered), now()->addHours(24));
    }

    /**
     * Auto-register breakpoint
     */
    private function autoRegisterBreakpoint(string $filePath, int $lineNumber, string $functionName, string $label): ?array
    {
        try {
            $url = "{$this->baseURL}/sdk/snapshots/auto-register";

            $client = new \GuzzleHttp\Client();
            $response = $client->post($url, [
                'headers' => [
                    'X-API-Key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'service_name' => $this->serviceName,
                    'file_path' => $filePath,
                    'line_number' => $lineNumber,
                    'function_name' => $functionName,
                    'label' => $label,
                ],
                'timeout' => 5,
            ]);

            $breakpoint = json_decode($response->getBody(), true);
            Log::info("📸 Auto-registered breakpoint: {$label}");

            return $breakpoint;

        } catch (\Exception $e) {
            Log::warning('⚠️ Failed to auto-register breakpoint: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Capture and send snapshot
     */
    private function captureSnapshot(array $snapshot): void
    {
        try {
            $url = "{$this->baseURL}/sdk/snapshots/capture";

            $client = new \GuzzleHttp\Client();
            $response = $client->post($url, [
                'headers' => [
                    'X-API-Key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $snapshot,
                'timeout' => 5,
            ]);

            $label = $snapshot['label'] ?? $snapshot['file_path'];
            Log::info("📸 Snapshot captured: {$label}");

        } catch (\Exception $e) {
            Log::error('⚠️ Failed to capture snapshot: ' . $e->getMessage());
        }
    }

    /**
     * Extract request context from Laravel
     */
    private function extractRequestContext(): array
    {
        try {
            $request = request();

            return [
                'method' => $request->method(),
                'path' => $request->path(),
                'url' => $request->fullUrl(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'query' => $request->query(),
                'headers' => $this->filterHeaders($request->headers->all()),
            ];
        } catch (\Exception $e) {
            // No request context available (console command, etc.)
            return [
                'method' => 'CLI',
                'path' => '',
                'url' => '',
                'ip' => '',
                'user_agent' => '',
                'query' => [],
                'headers' => [],
            ];
        }
    }

    /**
     * Filter sensitive headers
     */
    private function filterHeaders(array $headers): array
    {
        $allowed = ['content-type', 'content-length', 'host', 'user-agent', 'referer', 'accept'];
        $filtered = [];

        foreach ($headers as $key => $value) {
            if (in_array(strtolower($key), $allowed)) {
                $filtered[$key] = is_array($value) ? implode(', ', $value) : $value;
            }
        }

        return $filtered;
    }

    /**
     * Sanitize variables for JSON serialization
     */
    private function sanitizeVariables(array $variables, int $depth = 0): array
    {
        if ($depth >= $this->maxVariableDepth) {
            return []; // Stop recursion at max depth, don't replace with error
        }

        $sanitized = [];

        foreach ($variables as $key => $value) {
            try {
                if (is_string($value) && strlen($value) > $this->maxStringLength) {
                    $sanitized[$key] = substr($value, 0, $this->maxStringLength) . '...';
                } elseif (is_object($value)) {
                    $sanitized[$key] = $this->sanitizeObject($value, $depth + 1);
                } elseif (is_array($value)) {
                    $sanitized[$key] = $this->sanitizeVariables($value, $depth + 1);
                } elseif (is_resource($value)) {
                    $sanitized[$key] = '[resource]';
                } else {
                    // Test if serializable
                    json_encode($value);
                    $sanitized[$key] = $value;
                }
            } catch (\Exception $e) {
                $sanitized[$key] = '[' . gettype($value) . ']';
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize objects
     */
    private function sanitizeObject($object, int $depth): array
    {
        if ($depth >= $this->maxVariableDepth) {
            return ['class' => get_class($object)]; // Stop recursion, just return class name
        }

        try {
            if (method_exists($object, 'toArray')) {
                return $this->sanitizeVariables($object->toArray(), $depth);
            } elseif ($object instanceof \Illuminate\Database\Eloquent\Model) {
                return [
                    'class' => get_class($object),
                    'id' => $object->getKey(),
                    'attributes' => $this->sanitizeVariables($object->getAttributes(), $depth + 1),
                ];
            } else {
                return [
                    'class' => get_class($object),
                    'properties' => $this->sanitizeVariables(get_object_vars($object), $depth + 1),
                ];
            }
        } catch (\Exception $e) {
            return ['class' => get_class($object), 'error' => 'Could not serialize'];
        }
    }
}
