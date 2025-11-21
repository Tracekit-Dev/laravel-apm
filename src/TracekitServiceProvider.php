<?php

namespace TraceKit\Laravel;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use TraceKit\Laravel\Commands\InstallCommand;
use TraceKit\Laravel\Listeners\JobListener;
use TraceKit\Laravel\Listeners\QueryListener;
use TraceKit\Laravel\Middleware\TracekitMiddleware;
use TraceKit\Laravel\Middleware\HttpClientMiddleware;
use TraceKit\Laravel\SnapshotClient;

class TracekitServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge configuration
        $this->mergeConfigFrom(__DIR__ . '/../config/tracekit.php', 'tracekit');

        // Register TracekitClient as singleton
        $this->app->singleton(TracekitClient::class, function ($app) {
            return new TracekitClient();
        });

        // Register SnapshotClient as singleton
        $this->app->singleton(SnapshotClient::class, function ($app) {
            // Extract base URL from endpoint (remove /v1/traces suffix)
            $endpoint = config('tracekit.endpoint');
            $baseURL = preg_replace('#/v1/traces$#', '', $endpoint);
            
            return new SnapshotClient(
                config('tracekit.api_key'),
                $baseURL,
                config('tracekit.service_name'),
                config('tracekit.code_monitoring.poll_interval', 30),
                config('tracekit.code_monitoring.max_variable_depth', 3),
                config('tracekit.code_monitoring.max_string_length', 1000)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__ . '/../config/tracekit.php' => config_path('tracekit.php'),
        ], 'tracekit-config');

        // Register middleware (Laravel 12+ compatible)
        if (config('tracekit.enabled') && config('tracekit.features.http')) {
            // For Laravel 12+, we need to register global middleware
            // since middleware groups are now configured in bootstrap/app.php
            try {
                $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);

                // Try to append to middleware groups
                if (method_exists($kernel, 'appendMiddlewareToGroup')) {
                    $kernel->appendMiddlewareToGroup('web', TracekitMiddleware::class);
                    $kernel->appendMiddlewareToGroup('api', TracekitMiddleware::class);
                } elseif (method_exists($kernel, 'pushMiddleware')) {
                    // Use global middleware as fallback
                    $kernel->pushMiddleware(TracekitMiddleware::class);
                } else {
                    // Fallback for Laravel 10/11
                    $this->app['router']->pushMiddlewareToGroup('web', TracekitMiddleware::class);
                    $this->app['router']->pushMiddlewareToGroup('api', TracekitMiddleware::class);
                }
            } catch (\Exception $e) {
                // Log error but don't break the application
                if (function_exists('logger')) {
                    logger()->warning('TraceKit: Failed to register middleware', ['error' => $e->getMessage()]);
                }
            }
        }

        // Register database query listener
        if (config('tracekit.enabled') && config('tracekit.features.database')) {
            Event::listen(QueryExecuted::class, QueryListener::class);
        }

        // Register queue job listeners
        if (config('tracekit.enabled') && config('tracekit.features.queue')) {
            $jobListener = $this->app->make(JobListener::class);

            Event::listen(JobProcessing::class, [$jobListener, 'handleJobProcessing']);
            Event::listen(JobProcessed::class, [$jobListener, 'handleJobProcessed']);
            Event::listen(JobFailed::class, [$jobListener, 'handleJobFailed']);
        }

        // Register HTTP client instrumentation for outgoing requests
        if (config('tracekit.enabled') && config('tracekit.auto_instrument_http_client', true)) {
            $httpClientMiddleware = new HttpClientMiddleware();
            $httpClientMiddleware->register();
        }

        // Register exception reporter for tracing
        if (config('tracekit.enabled')) {
            $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)
                ->reportable(function (\Throwable $e) {
                    $tracekitClient = $this->app->make(TracekitClient::class);
                    
                    // Get the current span from the tracer
                    $tracer = $tracekitClient->getTracer();
                    $span = \OpenTelemetry\API\Trace\Span::getCurrent();
                    
                    // Only record if we have an active span
                    if ($span->getContext()->isValid()) {
                        $tracekitClient->recordException($span, $e);
                    }
                });
        }

        // Initialize code monitoring
        if (config('tracekit.enabled') && config('tracekit.code_monitoring.enabled')) {
            $snapshotClient = $this->app->make(SnapshotClient::class);
            $snapshotClient->start();

            // Register exception listener for automatic error capture
            Event::listen(\Illuminate\Foundation\Exceptions\Handler::class, function ($event) {
                if ($event instanceof \Throwable) {
                    $snapshotClient = app(SnapshotClient::class);
                    $snapshotClient->checkAndCaptureWithContext(
                        null, // Will extract from current request
                        'exception_' . class_basename($event),
                        [
                            'exception_type' => get_class($event),
                            'message' => $event->getMessage(),
                            'code' => $event->getCode(),
                            'file' => $event->getFile(),
                            'line' => $event->getLine(),
                        ]
                    );
                }
            });
        }

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);
        }
    }
}
