<?php

namespace TraceKit\Laravel;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\SemConv\ResourceAttributes;

/**
 * Custom span processor that sends traces to Local UI
 */
class LocalUISpanProcessor implements SpanProcessorInterface
{
    private array $spans = [];
    private const LOCAL_UI_TRACES_URL = 'http://localhost:9999/v1/traces';
    private bool $hasLogged = false;

    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
    {
        // No action needed on start
    }

    public function onEnd(ReadableSpanInterface $span): void
    {
        // Collect span data
        $this->spans[] = $span;
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        if (empty($this->spans)) {
            return true;
        }

        try {
            // Convert spans to OTLP format
            $exporter = new SpanExporter(
                (new OtlpHttpTransportFactory())->create(
                    self::LOCAL_UI_TRACES_URL,
                    'application/json'
                )
            );

            // Export the spans
            $exporter->export($this->spans);

            if (!$this->hasLogged) {
                Log::info('🔍 Sent traces to Local UI');
                $this->hasLogged = true;
            }

            $this->spans = [];
            return true;
        } catch (\Exception $e) {
            // Silently fail - don't block trace sending
            Log::debug('Failed to send traces to Local UI: ' . $e->getMessage());
            $this->spans = [];
            return false;
        }
    }

    public function shutdown(): bool
    {
        return $this->forceFlush();
    }
}

class TracekitClient
{
    private TracerProviderInterface $tracerProvider;
    private TracerInterface $tracer;
    private string $endpoint;
    private string $apiKey;
    private string $serviceName;
    private bool $enabled;
    private ?bool $localUIAvailable = null;
    private const LOCAL_UI_HEALTH_URL = 'http://localhost:9999/api/health';
    private const LOCAL_UI_TRACES_URL = 'http://localhost:9999/v1/traces';

    public function __construct()
    {
        $this->endpoint = config('tracekit.endpoint');
        $this->apiKey = config('tracekit.api_key');
        $this->serviceName = config('tracekit.service_name');
        $this->enabled = config('tracekit.enabled', true);

        // Suppress OpenTelemetry error output (export failures, etc.) in development
        // Set TRACEKIT_SUPPRESS_ERRORS=false in .env to see export errors
        if (config('tracekit.suppress_errors', true)) {
            $this->suppressOpenTelemetryErrors();
        }

        // Create resource with service name
        $resource = ResourceInfoFactory::defaultResource()->merge(
            ResourceInfo::create(Attributes::create([
                ResourceAttributes::SERVICE_NAME => $this->serviceName,
            ]))
        );

        // Create span processors
        $spanProcessors = [];

        // Add Local UI processor in local/development environments
        if (app()->environment('local', 'development')) {
            if ($this->detectLocalUI()) {
                $spanProcessors[] = new LocalUISpanProcessor();
            }
        }

        // Add cloud API processor if enabled and API key exists
        if ($this->enabled && $this->apiKey) {
            // Configure OTLP HTTP transport
            $transportFactory = new OtlpHttpTransportFactory();
            $transport = $transportFactory->create(
                $this->endpoint,
                'application/json',
                [
                    'X-API-Key' => $this->apiKey,
                ]
            );

            // Create OTLP exporter with transport
            $exporter = new SpanExporter($transport);

            // Create span processor
            $spanProcessors[] = new SimpleSpanProcessor($exporter);
        }

        // Initialize tracer provider with processors
        $this->tracerProvider = new TracerProvider(
            $spanProcessors,
            null,
            $resource
        );

        // Get tracer instance
        $this->tracer = $this->tracerProvider->getTracer(
            'tracekit-laravel',
            '1.0.0'
        );
    }

    public function startTrace(string $operationName, array $attributes = []): SpanInterface
    {
        return $this->tracer
            ->spanBuilder($operationName)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttributes($this->normalizeAttributes($attributes))
            ->startSpan();
    }

    /**
     * Start a SERVER span with optional parent context from traceparent header.
     * This is used by middleware to create spans that are children of incoming trace context.
     */
    public function startServerSpan(
        string $operationName,
        array $attributes = [],
        ?ContextInterface $parentContext = null
    ): SpanInterface {
        $builder = $this->tracer
            ->spanBuilder($operationName)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttributes($this->normalizeAttributes($attributes));

        if ($parentContext !== null) {
            $builder->setParent($parentContext);
        }

        return $builder->startSpan();
    }

    public function startSpan(
        string $operationName,
        ?SpanInterface $parentSpan = null,
        array $attributes = []
    ): SpanInterface {
        $builder = $this->tracer
            ->spanBuilder($operationName)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttributes($this->normalizeAttributes($attributes));

        // Span builder automatically uses current active context
        // No need to explicitly set parent

        return $builder->startSpan();
    }

    public function endSpan(SpanInterface $span, array $finalAttributes = [], ?string $status = 'OK'): void
    {
        // Add final attributes
        if (!empty($finalAttributes)) {
            $span->setAttributes($this->normalizeAttributes($finalAttributes));
        }

        // Set status
        if ($status === 'ERROR') {
            $span->setStatus(StatusCode::STATUS_ERROR);
        } elseif ($status === 'OK') {
            $span->setStatus(StatusCode::STATUS_OK);
        }

        $span->end();
    }

    public function addEvent(SpanInterface $span, string $name, array $attributes = []): void
    {
        $span->addEvent($name, $this->normalizeAttributes($attributes));
    }

    public function recordException(SpanInterface $span, \Throwable $exception): void
    {
        // Format stack trace for code discovery
        $stacktrace = $this->formatStackTrace($exception);
        
        // Add exception event with formatted stack trace
        $span->addEvent('exception', [
            'exception.type' => get_class($exception),
            'exception.message' => $exception->getMessage(),
            'exception.stacktrace' => $stacktrace,
        ]);
        
        // Also use OpenTelemetry's built-in exception recording
        $span->recordException($exception);
        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
    }
    
    /**
     * Format exception stack trace for code discovery
     */
    private function formatStackTrace(\Throwable $exception): string
    {
        $frames = [];
        // First line: where the exception was thrown
        $frames[] = $exception->getFile() . ':' . $exception->getLine();
        
        foreach ($exception->getTrace() as $frame) {
            $file = $frame['file'] ?? '';
            $line = $frame['line'] ?? 0;
            $function = $frame['function'] ?? '';
            $class = $frame['class'] ?? '';
            
            if ($file && $line) {
                $functionName = $class ? "$class::$function" : $function;
                $frames[] = "$functionName at $file:$line";
            }
        }
        
        return implode("\n", $frames);
    }

    public function flush(): void
    {
        if ($this->tracerProvider instanceof TracerProvider) {
            $this->tracerProvider->forceFlush();
        }
    }

    public function shutdown(): void
    {
        if ($this->tracerProvider instanceof TracerProvider) {
            $this->tracerProvider->shutdown();
        }
    }

    public function getTracer(): TracerInterface
    {
        return $this->tracer;
    }

    /**
     * Detect if TraceKit Local UI is running at localhost:9999
     */
    private function detectLocalUI(): bool
    {
        // Cache the result to avoid multiple health checks
        if ($this->localUIAvailable !== null) {
            return $this->localUIAvailable;
        }

        try {
            $response = Http::timeout(1)->get(self::LOCAL_UI_HEALTH_URL);
            $this->localUIAvailable = $response->successful();

            if ($this->localUIAvailable) {
                Log::info('🔍 Local UI detected at http://localhost:9999');
            }

            return $this->localUIAvailable;
        } catch (\Exception $e) {
            $this->localUIAvailable = false;
            return false;
        }
    }

    /**
     * Suppress OpenTelemetry internal error output (export failures, etc.)
     * This prevents noisy error messages when running without a valid API key
     */
    private function suppressOpenTelemetryErrors(): void
    {
        // Set environment variable to disable OpenTelemetry error logging
        putenv('OTEL_PHP_LOG_DESTINATION=none');

        // Also register a custom error handler that filters out OpenTelemetry errors
        $previousHandler = set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$previousHandler) {
            // Suppress OpenTelemetry-related errors
            if (str_contains($errfile, 'open-telemetry') || str_contains($errstr, 'OpenTelemetry')) {
                return true; // Suppress the error
            }

            // Call previous handler for other errors
            if ($previousHandler) {
                return $previousHandler($errno, $errstr, $errfile, $errline);
            }

            return false; // Let PHP handle it
        });
    }

    private function normalizeAttributes(array $attributes): array
    {
        $normalized = [];

        foreach ($attributes as $key => $value) {
            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                $normalized[$key] = $value;
            } elseif (is_array($value)) {
                $normalized[$key] = array_map('strval', $value);
            } else {
                $normalized[$key] = (string) $value;
            }
        }

        return $normalized;
    }

    // Legacy methods for backwards compatibility
    public function getCurrentTraceId(): ?string
    {
        return null; // Not needed with OpenTelemetry SDK
    }

    public function getRootSpanId(): ?string
    {
        return null; // Not needed with OpenTelemetry SDK
    }
}
