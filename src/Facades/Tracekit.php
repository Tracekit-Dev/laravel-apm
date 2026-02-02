<?php

namespace TraceKit\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * TraceKit Facade
 *
 * @method static \TraceKit\Laravel\Counter counter(string $name, array $tags = [])
 * @method static \TraceKit\Laravel\Gauge gauge(string $name, array $tags = [])
 * @method static \TraceKit\Laravel\Histogram histogram(string $name, array $tags = [])
 * @method static \OpenTelemetry\API\Trace\SpanInterface startTrace(string $operationName, array $attributes = [])
 * @method static \OpenTelemetry\API\Trace\SpanInterface startSpan(string $operationName, \OpenTelemetry\API\Trace\SpanInterface|null $parentSpan = null, array $attributes = [])
 * @method static void endSpan(\OpenTelemetry\API\Trace\SpanInterface $span, array $finalAttributes = [], string $status = null)
 * @method static void addEvent(\OpenTelemetry\API\Trace\SpanInterface $span, string $name, array $attributes = [])
 * @method static void recordException(\OpenTelemetry\API\Trace\SpanInterface $span, \Throwable $exception)
 * @method static void captureSnapshot(string $label, array $variables = [])
 * @method static void shutdown()
 *
 * @see \TraceKit\Laravel\TracekitClient
 */
class Tracekit extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'tracekit';
    }
}
