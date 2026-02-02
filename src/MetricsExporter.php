<?php

namespace TraceKit\Laravel;

use Illuminate\Support\Facades\Http;

/**
 * MetricsExporter sends metrics to backend in OTLP format
 */
class MetricsExporter
{
    private string $endpoint;
    private string $apiKey;
    private string $serviceName;

    public function __construct(string $endpoint, string $apiKey, string $serviceName)
    {
        $this->endpoint = $endpoint;
        $this->apiKey = $apiKey;
        $this->serviceName = $serviceName;
    }

    public function export(array $dataPoints): void
    {
        if (empty($dataPoints)) {
            return;
        }

        $payload = $this->toOTLP($dataPoints);

        $response = Http::timeout(10)
            ->withHeaders([
                'X-API-Key' => $this->apiKey
            ])
            ->post($this->endpoint, $payload);

        if (!$response->successful()) {
            throw new \Exception("HTTP {$response->status()}");
        }
    }

    private function toOTLP(array $dataPoints): array
    {
        // Group by name and type
        $grouped = [];
        foreach ($dataPoints as $dp) {
            $key = $dp['name'] . ':' . $dp['type'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }
            $grouped[$key][] = $dp;
        }

        // Build metrics array
        $metrics = [];

        foreach ($grouped as $key => $dps) {
            list($name, $type) = explode(':', $key, 2);

            // Convert data points
            $otlpDataPoints = [];
            foreach ($dps as $dp) {
                $attributes = [];
                foreach ($dp['tags'] as $k => $v) {
                    $attributes[] = [
                        'key' => $k,
                        'value' => ['stringValue' => $v]
                    ];
                }

                $otlpDataPoints[] = [
                    'attributes' => $attributes,
                    'timeUnixNano' => (string)((int)($dp['timestamp'] * 1_000_000_000)),
                    'asDouble' => $dp['value']
                ];
            }

            // Create metric based on type
            if ($type === 'counter') {
                $metric = [
                    'name' => $name,
                    'sum' => [
                        'dataPoints' => $otlpDataPoints,
                        'aggregationTemporality' => 2, // DELTA
                        'isMonotonic' => true
                    ]
                ];
            } else { // gauge or histogram
                $metric = [
                    'name' => $name,
                    'gauge' => [
                        'dataPoints' => $otlpDataPoints
                    ]
                ];
            }

            $metrics[] = $metric;
        }

        return [
            'resourceMetrics' => [
                [
                    'resource' => [
                        'attributes' => [
                            [
                                'key' => 'service.name',
                                'value' => ['stringValue' => $this->serviceName]
                            ]
                        ]
                    ],
                    'scopeMetrics' => [
                        [
                            'scope' => [
                                'name' => 'tracekit'
                            ],
                            'metrics' => $metrics
                        ]
                    ]
                ]
            ]
        ];
    }
}
