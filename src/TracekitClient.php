<?php

namespace TraceKit\Laravel;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class TracekitClient
{
    private Client $httpClient;
    private string $endpoint;
    private string $apiKey;
    private string $serviceName;
    private array $currentSpans = [];
    private ?string $currentTraceId = null;
    private ?string $rootSpanId = null;

    public function __construct()
    {
        $this->endpoint = config('tracekit.endpoint');
        $this->apiKey = config('tracekit.api_key');
        $this->serviceName = config('tracekit.service_name');

        $this->httpClient = new Client([
            'timeout' => 5.0,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-Key' => $this->apiKey,
            ],
        ]);
    }

    public function startTrace(string $operationName, array $attributes = []): string
    {
        $this->currentTraceId = $this->generateId(16);
        $this->rootSpanId = $this->generateId(8);

        $span = [
            'traceId' => $this->currentTraceId,
            'spanId' => $this->rootSpanId,
            'parentSpanId' => null,
            'name' => $operationName,
            'kind' => 'SERVER',
            'startTime' => $this->currentTimeMicros(),
            'endTime' => null,
            'attributes' => array_merge($attributes, [
                'service.name' => $this->serviceName,
            ]),
            'status' => 'UNSET',
            'events' => [],
        ];

        $this->currentSpans[$this->rootSpanId] = $span;

        return $this->rootSpanId;
    }

    public function startSpan(string $operationName, ?string $parentSpanId = null, array $attributes = []): string
    {
        if (!$this->currentTraceId) {
            return $this->startTrace($operationName, $attributes);
        }

        $spanId = $this->generateId(8);
        $parent = $parentSpanId ?? $this->rootSpanId;

        $span = [
            'traceId' => $this->currentTraceId,
            'spanId' => $spanId,
            'parentSpanId' => $parent,
            'name' => $operationName,
            'kind' => 'INTERNAL',
            'startTime' => $this->currentTimeMicros(),
            'endTime' => null,
            'attributes' => array_merge($attributes, [
                'service.name' => $this->serviceName,
            ]),
            'status' => 'UNSET',
            'events' => [],
        ];

        $this->currentSpans[$spanId] = $span;

        return $spanId;
    }

    public function endSpan(string $spanId, array $finalAttributes = [], ?string $status = 'OK'): void
    {
        if (!isset($this->currentSpans[$spanId])) {
            return;
        }

        $this->currentSpans[$spanId]['endTime'] = $this->currentTimeMicros();
        $this->currentSpans[$spanId]['status'] = $status;
        $this->currentSpans[$spanId]['attributes'] = array_merge(
            $this->currentSpans[$spanId]['attributes'],
            $finalAttributes
        );
    }

    public function addEvent(string $spanId, string $name, array $attributes = []): void
    {
        if (!isset($this->currentSpans[$spanId])) {
            return;
        }

        $this->currentSpans[$spanId]['events'][] = [
            'name' => $name,
            'timestamp' => $this->currentTimeMicros(),
            'attributes' => $attributes,
        ];
    }

    public function recordException(string $spanId, \Throwable $exception): void
    {
        if (!isset($this->currentSpans[$spanId])) {
            return;
        }

        $this->currentSpans[$spanId]['status'] = 'ERROR';
        $this->currentSpans[$spanId]['events'][] = [
            'name' => 'exception',
            'timestamp' => $this->currentTimeMicros(),
            'attributes' => [
                'exception.type' => get_class($exception),
                'exception.message' => $exception->getMessage(),
                'exception.stacktrace' => $exception->getTraceAsString(),
            ],
        ];
    }

    public function flush(): void
    {
        if (empty($this->currentSpans)) {
            return;
        }

        try {
            $payload = $this->buildOTLPPayload();

            $this->httpClient->post($this->endpoint, [
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            Log::warning('TraceKit: Failed to send traces', [
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->currentSpans = [];
            $this->currentTraceId = null;
            $this->rootSpanId = null;
        }
    }

    private function buildOTLPPayload(): array
    {
        $spans = [];

        foreach ($this->currentSpans as $span) {
            $spans[] = [
                'traceId' => $span['traceId'],
                'spanId' => $span['spanId'],
                'parentSpanId' => $span['parentSpanId'],
                'name' => $span['name'],
                'kind' => $span['kind'],
                'startTimeUnixNano' => $span['startTime'],
                'endTimeUnixNano' => $span['endTime'] ?? $this->currentTimeMicros(),
                'attributes' => $this->formatAttributes($span['attributes']),
                'status' => [
                    'code' => $span['status'] === 'OK' ? 1 : ($span['status'] === 'ERROR' ? 2 : 0),
                ],
                'events' => array_map(function ($event) {
                    return [
                        'name' => $event['name'],
                        'timeUnixNano' => $event['timestamp'],
                        'attributes' => $this->formatAttributes($event['attributes']),
                    ];
                }, $span['events']),
            ];
        }

        return [
            'resourceSpans' => [
                [
                    'resource' => [
                        'attributes' => $this->formatAttributes([
                            'service.name' => $this->serviceName,
                        ]),
                    ],
                    'scopeSpans' => [
                        [
                            'scope' => [
                                'name' => 'tracekit-laravel',
                                'version' => '1.0.0',
                            ],
                            'spans' => $spans,
                        ],
                    ],
                ],
            ],
        ];
    }

    private function formatAttributes(array $attributes): array
    {
        $formatted = [];

        foreach ($attributes as $key => $value) {
            $formatted[] = [
                'key' => $key,
                'value' => $this->formatValue($value),
            ];
        }

        return $formatted;
    }

    private function formatValue($value): array
    {
        if (is_string($value)) {
            return ['stringValue' => $value];
        } elseif (is_int($value)) {
            return ['intValue' => $value];
        } elseif (is_float($value)) {
            return ['doubleValue' => $value];
        } elseif (is_bool($value)) {
            return ['boolValue' => $value];
        }

        return ['stringValue' => (string) $value];
    }

    private function generateId(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }

    private function currentTimeMicros(): int
    {
        return (int) (microtime(true) * 1_000_000_000);
    }

    public function getCurrentTraceId(): ?string
    {
        return $this->currentTraceId;
    }

    public function getRootSpanId(): ?string
    {
        return $this->rootSpanId;
    }
}
