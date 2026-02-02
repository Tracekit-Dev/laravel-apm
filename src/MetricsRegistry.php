<?php

namespace TraceKit\Laravel;

use TraceKit\Laravel\MetricsBuffer;

// Load metrics implementation classes
require_once __DIR__ . '/Metrics.php';

/**
 * MetricsRegistry manages all metrics
 */
class MetricsRegistry
{
    private array $counters = [];
    private array $gauges = [];
    private array $histograms = [];
    private MetricsBuffer $buffer;

    public function __construct(string $endpoint, string $apiKey, string $serviceName)
    {
        $this->buffer = new MetricsBuffer($endpoint, $apiKey, $serviceName);
    }

    public function counter(string $name, array $tags = []): Counter
    {
        $key = $this->metricKey($name, $tags);

        if (!isset($this->counters[$key])) {
            $this->counters[$key] = new CounterImpl($name, $tags, $this->buffer);
        }

        return $this->counters[$key];
    }

    public function gauge(string $name, array $tags = []): Gauge
    {
        $key = $this->metricKey($name, $tags);

        if (!isset($this->gauges[$key])) {
            $this->gauges[$key] = new GaugeImpl($name, $tags, $this->buffer);
        }

        return $this->gauges[$key];
    }

    public function histogram(string $name, array $tags = []): Histogram
    {
        $key = $this->metricKey($name, $tags);

        if (!isset($this->histograms[$key])) {
            $this->histograms[$key] = new HistogramImpl($name, $tags, $this->buffer);
        }

        return $this->histograms[$key];
    }

    public function shutdown(): void
    {
        $this->buffer->shutdown();
    }

    private function metricKey(string $name, array $tags): string
    {
        if (empty($tags)) {
            return $name;
        }

        // Simple key format: name{k1=v1,k2=v2}
        ksort($tags);
        $tagPairs = [];
        foreach ($tags as $k => $v) {
            $tagPairs[] = "$k=$v";
        }

        return $name . '{' . implode(',', $tagPairs) . '}';
    }
}
