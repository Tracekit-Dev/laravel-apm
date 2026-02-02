<?php

namespace TraceKit\Laravel;

use TraceKit\Laravel\MetricsBuffer;

/**
 * Counter tracks monotonically increasing values
 */
interface Counter
{
    public function inc(): void;
    public function add(float $value): void;
}

/**
 * Gauge tracks point-in-time values
 */
interface Gauge
{
    public function set(float $value): void;
    public function inc(): void;
    public function dec(): void;
}

/**
 * Histogram tracks value distributions
 */
interface Histogram
{
    public function record(float $value): void;
}

/**
 * Internal Counter implementation
 */
class CounterImpl implements Counter
{
    private string $name;
    private array $tags;
    private MetricsBuffer $buffer;

    public function __construct(string $name, array $tags, MetricsBuffer $buffer)
    {
        $this->name = $name;
        $this->tags = $tags;
        $this->buffer = $buffer;
    }

    public function inc(): void
    {
        $this->add(1.0);
    }

    public function add(float $value): void
    {
        if ($value < 0) {
            return; // Counters must be monotonic
        }

        $this->buffer->add([
            'name' => $this->name,
            'tags' => $this->tags,
            'value' => $value,
            'timestamp' => microtime(true),
            'type' => 'counter'
        ]);
    }
}

/**
 * Internal Gauge implementation
 */
class GaugeImpl implements Gauge
{
    private string $name;
    private array $tags;
    private MetricsBuffer $buffer;
    private float $value = 0.0;

    public function __construct(string $name, array $tags, MetricsBuffer $buffer)
    {
        $this->name = $name;
        $this->tags = $tags;
        $this->buffer = $buffer;
    }

    public function set(float $value): void
    {
        $this->value = $value;

        $this->buffer->add([
            'name' => $this->name,
            'tags' => $this->tags,
            'value' => $value,
            'timestamp' => microtime(true),
            'type' => 'gauge'
        ]);
    }

    public function inc(): void
    {
        $this->value += 1.0;

        $this->buffer->add([
            'name' => $this->name,
            'tags' => $this->tags,
            'value' => $this->value,
            'timestamp' => microtime(true),
            'type' => 'gauge'
        ]);
    }

    public function dec(): void
    {
        $this->value -= 1.0;

        $this->buffer->add([
            'name' => $this->name,
            'tags' => $this->tags,
            'value' => $this->value,
            'timestamp' => microtime(true),
            'type' => 'gauge'
        ]);
    }
}

/**
 * Internal Histogram implementation
 */
class HistogramImpl implements Histogram
{
    private string $name;
    private array $tags;
    private MetricsBuffer $buffer;

    public function __construct(string $name, array $tags, MetricsBuffer $buffer)
    {
        $this->name = $name;
        $this->tags = $tags;
        $this->buffer = $buffer;
    }

    public function record(float $value): void
    {
        $this->buffer->add([
            'name' => $this->name,
            'tags' => $this->tags,
            'value' => $value,
            'timestamp' => microtime(true),
            'type' => 'histogram'
        ]);
    }
}
