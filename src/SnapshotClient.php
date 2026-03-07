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
    private bool $piiScrubbing;
    private array $piiPatterns;

    // Opt-in capture limits (all disabled by default)
    private ?int $captureDepth = null;   // null = use maxVariableDepth for sanitization (backward compat)
    private ?int $maxPayload = null;     // null = unlimited payload bytes
    private ?float $captureTimeout = null; // null = no timeout (seconds)

    // Kill switch: server-initiated monitoring disable (uses Laravel Cache for persistence)
    private bool $killSwitchActive = false;

    // SSE (Server-Sent Events) real-time updates
    // NOTE: SSE is best-effort, only active in long-running console processes (Artisan commands, queue workers).
    // In standard HTTP request cycles, polling via Laravel Cache is the primary mechanism.
    // SSE blocks the current process, so it should only be used in persistent processes.
    private ?string $sseEndpoint = null;
    private bool $sseActive = false;

    // Circuit breaker state (uses Laravel Cache for cross-request persistence)
    private int $cbMaxFailures = 3;
    private int $cbWindowSeconds = 60;
    private int $cbCooldownSeconds = 300;

    /**
     * Standard 13 PII patterns with typed [REDACTED:type] markers
     */
    private static function defaultPiiPatterns(): array
    {
        return [
            ['pattern' => '/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/', 'marker' => '[REDACTED:email]'],
            ['pattern' => '/\b\d{3}-\d{2}-\d{4}\b/', 'marker' => '[REDACTED:ssn]'],
            ['pattern' => '/\b\d{4}[- ]?\d{4}[- ]?\d{4}[- ]?\d{4}\b/', 'marker' => '[REDACTED:credit_card]'],
            ['pattern' => '/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/', 'marker' => '[REDACTED:phone]'],
            ['pattern' => '/AKIA[0-9A-Z]{16}/', 'marker' => '[REDACTED:aws_key]'],
            ['pattern' => '/aws.{0,20}secret.{0,20}[A-Za-z0-9\/+=]{40}/i', 'marker' => '[REDACTED:aws_secret]'],
            ['pattern' => '/(?:bearer\s+)[A-Za-z0-9._~+\/=\-]{20,}/i', 'marker' => '[REDACTED:oauth_token]'],
            ['pattern' => '/sk_live_[0-9a-zA-Z]{10,}/', 'marker' => '[REDACTED:stripe_key]'],
            ['pattern' => '/(?:password|passwd|pwd)\s*[=:]\s*["\']?[^\s"\']{6,}/i', 'marker' => '[REDACTED:password]'],
            ['pattern' => '/eyJ[A-Za-z0-9_\-]+\.eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/', 'marker' => '[REDACTED:jwt]'],
            ['pattern' => '/-----BEGIN (?:RSA |EC )?PRIVATE KEY-----/', 'marker' => '[REDACTED:private_key]'],
            ['pattern' => '/(?:api[_\-]?key|apikey)\s*[:=]\s*["\']?[A-Za-z0-9_\-]{20,}/i', 'marker' => '[REDACTED:api_key]'],
        ];
    }

    public function __construct(
        string $apiKey,
        string $baseURL,
        string $serviceName,
        int $pollInterval = 30,
        int $maxVariableDepth = 3,
        int $maxStringLength = 1000,
        bool $piiScrubbing = true,
        array $customPiiPatterns = []
    ) {
        $this->apiKey = $apiKey;
        $this->baseURL = $baseURL;
        $this->serviceName = $serviceName;
        $this->pollInterval = $pollInterval;
        $this->maxVariableDepth = $maxVariableDepth;
        $this->maxStringLength = $maxStringLength;
        $this->piiScrubbing = $piiScrubbing;
        $this->piiPatterns = array_merge(self::defaultPiiPatterns(), $customPiiPatterns);
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

    /** Set opt-in capture depth limit. null = unlimited. */
    public function setCaptureDepth(?int $depth): void { $this->captureDepth = $depth; }

    /** Set opt-in max payload size in bytes. null = unlimited. */
    public function setMaxPayload(?int $bytes): void { $this->maxPayload = $bytes; }

    /** Set opt-in capture timeout in seconds. null = no timeout. */
    public function setCaptureTimeout(?float $seconds): void { $this->captureTimeout = $seconds; }

    /**
     * Check and capture snapshot with automatic breakpoint detection.
     * Crash isolation: catches all Throwable so TraceKit never crashes the host application.
     */
    public function checkAndCaptureWithContext(
        ?array $requestContext,
        string $label,
        array $variables = []
    ): void {
        // Kill switch: skip all capture when server has disabled monitoring
        if ($this->killSwitchActive) {
            return;
        }

        try {
            $location = $this->detectCallerLocation();
            if (!$location) {
                return;
            }

            $locationKey = "{$location['function']}:{$label}";

            // Check if location is registered
            if (!$this->isLocationRegistered($locationKey)) {
                // Auto-register breakpoint
                $breakpoint = $this->autoRegisterBreakpoint($location['file'], $location['line'], $location['function'], $label);
                if ($breakpoint) {
                    $this->registerLocation($locationKey, $breakpoint);
                    // Add to breakpoints cache so getActiveBreakpoint can find it
                    $cacheKey = "tracekit_breakpoints_{$this->serviceName}";
                    $cached = Cache::get($cacheKey, []);
                    $cached[] = $breakpoint;
                    Cache::put($cacheKey, $cached, now()->addMinutes(60));
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

            // Scan variables for security issues
            $securityScan = $this->scanForSecurityIssues($variables);

            // Create snapshot
            $snapshot = [
                'breakpoint_id' => $breakpoint['id'] ?? null,
                'service_name' => $this->serviceName,
                'file_path' => $location['file'],
                'function_name' => $location['function'],
                'label' => $label,
                'line_number' => $location['line'],
                'variables' => $securityScan['variables'],
                'security_flags' => $securityScan['flags'],
                'stack_trace' => $this->getStackTrace(),
                'request_context' => $requestContext,
                'captured_at' => now()->toISOString(),
            ];

            // Apply opt-in max payload limit
            $payload = json_encode($snapshot);
            if ($this->maxPayload !== null && strlen($payload) > $this->maxPayload) {
                $snapshot['variables'] = [
                    '_truncated' => true,
                    '_payload_size' => strlen($payload),
                    '_max_payload' => $this->maxPayload,
                ];
                $snapshot['security_flags'] = [];
            }

            // Send snapshot
            $this->captureSnapshot($snapshot);
        } catch (\Throwable $t) {
            // Crash isolation: never let TraceKit errors propagate to host application
            Log::error('TraceKit: error in capture: ' . $t->getMessage());
        }
    }

    /**
     * Check and capture snapshot at specific location (manual).
     * Crash isolation: catches all Throwable.
     */
    public function checkAndCapture(
        string $filePath,
        int $lineNumber,
        array $variables = []
    ): void {
        // Kill switch: skip all capture when server has disabled monitoring
        if ($this->killSwitchActive) {
            return;
        }

        try {
            $lineKey = "{$filePath}:{$lineNumber}";
            $breakpoint = $this->getActiveBreakpoint($lineKey);

            if (!$breakpoint || !($breakpoint['enabled'] ?? true)) {
                return;
            }

            $requestContext = $this->extractRequestContext();

            // Scan variables for security issues
            $securityScan = $this->scanForSecurityIssues($variables);

            $snapshot = [
                'breakpoint_id' => $breakpoint['id'] ?? null,
                'service_name' => $this->serviceName,
                'file_path' => $filePath,
                'function_name' => $breakpoint['function_name'] ?? 'unknown',
                'label' => $breakpoint['label'] ?? null,
                'line_number' => $lineNumber,
                'variables' => $securityScan['variables'],
                'security_flags' => $securityScan['flags'],
                'stack_trace' => $this->getStackTrace(),
                'request_context' => $requestContext,
                'captured_at' => now()->toISOString(),
            ];

            $this->captureSnapshot($snapshot);
        } catch (\Throwable $t) {
            Log::error('TraceKit: error in checkAndCapture: ' . $t->getMessage());
        }
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

            // SSE auto-discovery: if polling response includes sse_endpoint, start SSE in console mode
            if (isset($data['sse_endpoint']) && !$this->sseActive && $this->isLongRunning()) {
                $this->sseEndpoint = $data['sse_endpoint'];
                $this->connectSSE($this->sseEndpoint);
            }

            // Handle kill switch state (missing field = false for backward compat)
            $newKillState = ($data['kill_switch'] ?? false) === true;
            if ($newKillState && !$this->killSwitchActive) {
                Log::warning('TraceKit: Code monitoring disabled by server kill switch. Polling at reduced frequency.');
            } elseif (!$newKillState && $this->killSwitchActive) {
                Log::info('TraceKit: Code monitoring re-enabled by server.');
            }
            $this->killSwitchActive = $newKillState;

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

            // Server response may only contain {id}, so augment with known data
            $breakpoint['file_path'] = $breakpoint['file_path'] ?? $filePath;
            $breakpoint['line_number'] = $breakpoint['line_number'] ?? $lineNumber;
            $breakpoint['function_name'] = $breakpoint['function_name'] ?? $functionName;
            $breakpoint['label'] = $breakpoint['label'] ?? $label;
            $breakpoint['enabled'] = $breakpoint['enabled'] ?? true;

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
        // Circuit breaker check (uses Laravel Cache for cross-request persistence)
        if (!$this->circuitBreakerShouldAllow()) {
            return;
        }

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
                'http_errors' => false,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 500) {
                // Server error -- count as circuit breaker failure
                Log::error("⚠️ Failed to capture snapshot: HTTP {$statusCode}");
                if ($this->circuitBreakerRecordFailure()) {
                    $this->queueCircuitBreakerEvent();
                }
            } elseif ($statusCode >= 200 && $statusCode < 300) {
                $label = $snapshot['label'] ?? $snapshot['file_path'];
                Log::info("📸 Snapshot captured: {$label}");
            } else {
                Log::error("⚠️ Failed to capture snapshot: HTTP {$statusCode}");
            }

        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            // Network error -- count as circuit breaker failure
            Log::error('⚠️ Failed to capture snapshot: ' . $e->getMessage());
            if ($this->circuitBreakerRecordFailure()) {
                $this->queueCircuitBreakerEvent();
            }
        } catch (\Exception $e) {
            Log::error('⚠️ Failed to capture snapshot: ' . $e->getMessage());
        }
    }

    /** Configure circuit breaker thresholds (0 = use default). */
    public function configureCircuitBreaker(int $maxFailures = 0, int $windowSeconds = 0, int $cooldownSeconds = 0): void
    {
        if ($maxFailures > 0) $this->cbMaxFailures = $maxFailures;
        if ($windowSeconds > 0) $this->cbWindowSeconds = $windowSeconds;
        if ($cooldownSeconds > 0) $this->cbCooldownSeconds = $cooldownSeconds;
    }

    private function circuitBreakerShouldAllow(): bool
    {
        $cacheKey = "tracekit_cb_{$this->serviceName}";
        $state = Cache::get($cacheKey, ['state' => 'closed', 'opened_at' => null]);

        if ($state['state'] === 'closed') return true;

        // Check cooldown
        if ($state['opened_at'] && (microtime(true) - $state['opened_at']) >= $this->cbCooldownSeconds) {
            Cache::put($cacheKey, ['state' => 'closed', 'opened_at' => null], now()->addHours(24));
            Cache::forget("tracekit_cb_failures_{$this->serviceName}");
            Log::info('TraceKit: Code monitoring resumed');
            return true;
        }

        return false;
    }

    private function circuitBreakerRecordFailure(): bool
    {
        $failureKey = "tracekit_cb_failures_{$this->serviceName}";
        $cacheKey = "tracekit_cb_{$this->serviceName}";

        $now = microtime(true);
        $failures = Cache::get($failureKey, []);
        $failures[] = $now;

        // Prune old timestamps
        $cutoff = $now - $this->cbWindowSeconds;
        $failures = array_values(array_filter($failures, fn($ts) => $ts > $cutoff));
        Cache::put($failureKey, $failures, now()->addMinutes(10));

        if (count($failures) >= $this->cbMaxFailures) {
            $state = Cache::get($cacheKey, ['state' => 'closed', 'opened_at' => null]);
            if ($state['state'] === 'closed') {
                Cache::put($cacheKey, ['state' => 'open', 'opened_at' => $now], now()->addHours(24));
                Log::warning("TraceKit: Code monitoring paused ({$this->cbMaxFailures} capture failures in {$this->cbWindowSeconds}s). Auto-resumes in " . ($this->cbCooldownSeconds / 60) . " min.");
                return true;
            }
        }

        return false;
    }

    private function queueCircuitBreakerEvent(): void
    {
        $eventsKey = "tracekit_cb_events_{$this->serviceName}";
        $events = Cache::get($eventsKey, []);
        $events[] = [
            'type' => 'circuit_breaker_tripped',
            'service_name' => $this->serviceName,
            'failure_count' => $this->cbMaxFailures,
            'window_seconds' => $this->cbWindowSeconds,
            'cooldown_seconds' => $this->cbCooldownSeconds,
            'timestamp' => now()->toISOString(),
        ];
        Cache::put($eventsKey, $events, now()->addMinutes(30));
    }

    /**
     * Check if running in a long-running process (Artisan command, queue worker).
     * SSE is only activated in console mode since it blocks the process.
     * In HTTP request mode, polling via Laravel Cache is the primary mechanism.
     */
    private function isLongRunning(): bool
    {
        // Prefer Laravel's runningInConsole() when available, fall back to SAPI check
        try {
            return app()->runningInConsole();
        } catch (\Throwable $t) {
            return php_sapi_name() === 'cli';
        }
    }

    /**
     * Connect to the SSE endpoint for real-time breakpoint updates.
     * This method blocks while reading the SSE stream, so it should only be called
     * in long-running console processes (Artisan commands, queue workers).
     * Falls back to polling if SSE connection fails or disconnects.
     * Crash isolation: wraps all operations in try/catch(\Throwable).
     */
    private function connectSSE(string $endpoint): void
    {
        try {
            $fullUrl = rtrim($this->baseURL, '/') . $endpoint;

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => "X-API-Key: {$this->apiKey}\r\n" .
                               "Accept: text/event-stream\r\n" .
                               "Cache-Control: no-cache\r\n",
                    'timeout' => 0, // No timeout for SSE (long-lived connection)
                ]
            ]);

            $stream = @fopen($fullUrl, 'r', false, $context);

            if ($stream === false) {
                Log::warning('TraceKit: SSE connection failed, falling back to polling');
                $this->sseActive = false;
                return;
            }

            // Disable blocking timeout for the stream
            stream_set_timeout($stream, 0);

            $this->sseActive = true;
            Log::info("TraceKit: SSE connected to {$endpoint}");

            $eventType = null;
            $eventData = '';

            while (!feof($stream)) {
                $line = fgets($stream);

                if ($line === false) {
                    break;
                }

                $line = trim($line);

                if (strpos($line, 'event:') === 0) {
                    $eventType = trim(substr($line, 6));
                } elseif (strpos($line, 'data:') === 0) {
                    $eventData .= trim(substr($line, 5));
                } elseif ($line === '' && $eventType !== null) {
                    // Empty line signals end of event -- process it
                    $this->handleSSEEvent($eventType, $eventData);
                    $eventType = null;
                    $eventData = '';
                }
            }

            fclose($stream);

            // Connection closed
            $this->sseActive = false;
            Log::info('TraceKit: SSE connection closed, falling back to polling');

        } catch (\Throwable $t) {
            // Crash isolation: never let SSE errors propagate
            Log::error('TraceKit: SSE error: ' . $t->getMessage());
            $this->sseActive = false;
        }
    }

    /**
     * Handle a parsed SSE event
     */
    private function handleSSEEvent(string $eventType, string $dataStr): void
    {
        try {
            $payload = json_decode($dataStr, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning("TraceKit: SSE JSON parse error for '{$eventType}'");
                return;
            }

            switch ($eventType) {
                case 'init':
                    // Replace entire breakpoint cache from init event
                    $this->updateBreakpointCache($payload['breakpoints'] ?? []);

                    // Update kill switch from init event
                    if (isset($payload['kill_switch']) && $payload['kill_switch'] === true) {
                        $this->killSwitchActive = true;
                        Log::warning('TraceKit: Code monitoring disabled by server kill switch via SSE.');
                        $this->sseActive = false;
                    }
                    break;

                case 'breakpoint_created':
                case 'breakpoint_updated':
                    // Upsert breakpoint in Laravel Cache
                    $this->upsertBreakpointInCache($payload);
                    break;

                case 'breakpoint_deleted':
                    // Remove breakpoint from Laravel Cache by ID
                    $this->removeBreakpointFromCache($payload['id'] ?? null);
                    break;

                case 'kill_switch':
                    $this->killSwitchActive = ($payload['enabled'] ?? false) === true;
                    if ($this->killSwitchActive) {
                        Log::warning('TraceKit: Code monitoring disabled by server kill switch via SSE.');
                        $this->sseActive = false;
                    }
                    break;

                case 'heartbeat':
                    // No action needed -- keeps connection alive
                    break;

                default:
                    // Unknown event type, ignore
                    break;
            }
        } catch (\Throwable $t) {
            Log::error("TraceKit: SSE event handling error: " . $t->getMessage());
        }
    }

    /**
     * Upsert a single breakpoint into the Laravel Cache breakpoint store
     */
    private function upsertBreakpointInCache(array $breakpoint): void
    {
        $bpId = $breakpoint['id'] ?? null;
        if ($bpId === null) {
            return;
        }

        $cacheKey = "tracekit_breakpoints_{$this->serviceName}";
        $breakpoints = Cache::get($cacheKey, []);

        // Update existing or add new
        $found = false;
        foreach ($breakpoints as $i => $existing) {
            if (($existing['id'] ?? null) === $bpId) {
                $breakpoints[$i] = $breakpoint;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $breakpoints[] = $breakpoint;
        }

        Cache::put($cacheKey, $breakpoints, now()->addMinutes(60));
    }

    /**
     * Remove a breakpoint from the Laravel Cache by ID
     */
    private function removeBreakpointFromCache(?string $breakpointId): void
    {
        if ($breakpointId === null) {
            return;
        }

        $cacheKey = "tracekit_breakpoints_{$this->serviceName}";
        $breakpoints = Cache::get($cacheKey, []);

        $breakpoints = array_values(array_filter(
            $breakpoints,
            fn($bp) => ($bp['id'] ?? null) !== $breakpointId
        ));

        Cache::put($cacheKey, $breakpoints, now()->addMinutes(60));
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
     * Scan variables for security issues using typed [REDACTED:type] markers.
     * Scans serialized JSON to catch nested PII. Skips when piiScrubbing is false.
     */
    private function scanForSecurityIssues(array $variables): array
    {
        // If PII scrubbing is disabled, return as-is
        if (!$this->piiScrubbing) {
            return [
                'variables' => $variables,
                'flags' => [],
            ];
        }

        // Letter-boundary pattern for sensitive variable names.
        // \b treats _ as word char, so api_key/user_token won't match. Use letter boundaries instead.
        $sensitiveNamePattern = '/(?:^|[^a-zA-Z])(?:password|passwd|pwd|secret|token|key|credential|api_key|apikey)(?:[^a-zA-Z]|$)/i';

        $securityFlags = [];
        $sanitized = $this->sanitizeVariables($variables);

        foreach ($variables as $name => $value) {
            // Check variable name for sensitive keywords
            if (preg_match($sensitiveNamePattern, $name)) {
                $securityFlags[] = [
                    'type' => 'sensitive_variable_name',
                    'severity' => 'medium',
                    'variable' => $name,
                ];
                $sanitized[$name] = '[REDACTED:sensitive_name]';
                continue;
            }

            // Serialize value to JSON for deep scanning of nested structures
            $serialized = json_encode($value);
            $flagged = false;
            foreach ($this->piiPatterns as $pp) {
                if (preg_match($pp['pattern'], $serialized)) {
                    $securityFlags[] = [
                        'type' => "sensitive_data",
                        'severity' => 'high',
                        'variable' => $name,
                    ];
                    $sanitized[$name] = $pp['marker'];
                    $flagged = true;
                    break;
                }
            }
        }

        return [
            'variables' => $sanitized,
            'flags' => $securityFlags,
        ];
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
