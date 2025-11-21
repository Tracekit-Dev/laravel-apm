<?php

namespace TraceKit\Laravel\Middleware;

use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;

class HttpClientMiddleware
{
    private $tracer;
    private static array $activeSpans = [];

    public function __construct()
    {
        // Get tracer from TraceKit client
        if (app()->bound(\TraceKit\Laravel\TracekitClient::class)) {
            $this->tracer = app(\TraceKit\Laravel\TracekitClient::class)->getTracer();
        }
    }

    /**
     * Register HTTP client event listeners
     */
    public function register(): void
    {
        // Listen for outgoing HTTP requests
        Event::listen(RequestSending::class, function (RequestSending $event) {
            $this->handleRequestSending($event);
        });

        // Listen for HTTP responses
        Event::listen(ResponseReceived::class, function (ResponseReceived $event) {
            $this->handleResponseReceived($event);
        });
    }

    /**
     * Handle outgoing HTTP request - create CLIENT span
     */
    private function handleRequestSending(RequestSending $event): void
    {
        if (!$this->tracer) {
            return;
        }

        $request = $event->request;
        $url = $request->url();
        $method = $request->method();

        // Extract service name from URL
        $serviceName = $this->extractServiceName($url);

        // Start CLIENT span (inherits from current active span context)
        $span = $this->tracer
            ->spanBuilder("HTTP {$method}")
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('http.url', $url)
            ->setAttribute('http.method', $method)
            ->setAttribute('peer.service', $serviceName)
            ->startSpan();

        // Activate span in context
        $scope = $span->activate();

        // Store span and scope for later access via static property
        // Note: We can't modify headers on Request object as it's already built
        // The traceparent header should be added by the caller using Http::withHeaders()
        static::$activeSpans[spl_object_id($request)] = [
            'span' => $span,
            'scope' => $scope,
        ];
    }

    /**
     * Handle HTTP response - end CLIENT span
     */
    private function handleResponseReceived(ResponseReceived $event): void
    {
        $request = $event->request;
        $requestId = spl_object_id($request);

        // Retrieve span from static storage
        if (!isset(static::$activeSpans[$requestId])) {
            return;
        }

        $spanData = static::$activeSpans[$requestId];
        $span = $spanData['span'];
        $scope = $spanData['scope'];

        // Add response status code
        $statusCode = $event->response->status();
        $span->setAttribute('http.status_code', $statusCode);

        // Set error status if response failed
        if ($event->response->failed()) {
            $span->setStatus(StatusCode::STATUS_ERROR);
        } else {
            $span->setStatus(StatusCode::STATUS_OK);
        }

        // End span and detach scope
        // Suppress scope ordering warnings from OpenTelemetry debug mode
        $span->end();
        @$scope->detach();

        // Clean up
        unset(static::$activeSpans[$requestId]);
    }

    /**
     * Extract service name from URL
     */
    private function extractServiceName(string $url): string
    {
        $parsed = parse_url($url);
        $hostname = $parsed['host'] ?? 'unknown';
        $port = $parsed['port'] ?? null;
        $hostWithPort = $port ? "{$hostname}:{$port}" : $hostname;

        // First, check if there's a configured mapping for this hostname
        // This allows mapping localhost:port to actual service names
        $mappings = config('tracekit.service_name_mappings', []);
        if (!empty($mappings)) {
            // Check with port first
            if (isset($mappings[$hostWithPort])) {
                return $mappings[$hostWithPort];
            }
            // Check without port
            if (isset($mappings[$hostname])) {
                return $mappings[$hostname];
            }
        }

        // Handle Kubernetes service names
        // e.g., payment.internal.svc.cluster.local -> payment
        if (str_contains($hostname, '.svc.cluster.local')) {
            $parts = explode('.', $hostname);
            return $parts[0] ?? $hostname;
        }

        // Handle internal domain
        // e.g., payment.internal -> payment
        if (str_contains($hostname, '.internal')) {
            $parts = explode('.', $hostname);
            return $parts[0] ?? $hostname;
        }

        // Default: return full hostname
        return $hostname;
    }

    /**
     * Generate W3C traceparent header
     */
    private function generateTraceparent($span): string
    {
        $traceId = $span->getContext()->getTraceId();
        $spanId = $span->getContext()->getSpanId();
        $flags = $span->getContext()->getTraceFlags();

        return sprintf('00-%s-%s-%02x', $traceId, $spanId, $flags);
    }
}
