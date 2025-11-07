<?php

namespace TraceKit\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TraceKit\Laravel\TracekitClient;

class TracekitMiddleware
{
    private TracekitClient $client;

    public function __construct(TracekitClient $client)
    {
        $this->client = $client;
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Check if tracing is enabled
        if (!config('tracekit.enabled') || !config('tracekit.features.http')) {
            return $next($request);
        }

        // Check if API key is set
        if (empty(config('tracekit.api_key'))) {
            return $next($request);
        }

        // Check if route should be ignored
        if ($this->shouldIgnoreRoute($request)) {
            return $next($request);
        }

        // Sample rate check
        if (mt_rand() / mt_getrandmax() > config('tracekit.sample_rate', 1.0)) {
            return $next($request);
        }

        // Start trace
        $operationName = $this->getOperationName($request);
        $span = $this->client->startTrace($operationName, [
            'http.method' => $request->method(),
            'http.url' => $request->fullUrl(),
            'http.route' => $request->route()?->uri() ?? $request->path(),
            'http.user_agent' => $request->userAgent(),
            'http.client_ip' => $request->ip(),
        ]);

        // Activate span in context so child spans can use it as parent
        $scope = $span->activate();

        $startTime = microtime(true);

        try {
            $response = $next($request);

            // Record successful response
            $this->client->endSpan($span, [
                'http.status_code' => $response->getStatusCode(),
                'http.duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            ], $response->isSuccessful() ? 'OK' : 'ERROR');

            return $response;
        } catch (\Throwable $e) {
            // Record exception
            $this->client->recordException($span, $e);
            $this->client->endSpan($span, [
                'http.duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            ], 'ERROR');

            throw $e;
        } finally {
            // Detach scope and flush traces
            $scope->detach();
            $this->client->flush();
        }
    }

    private function shouldIgnoreRoute(Request $request): bool
    {
        $ignoredRoutes = config('tracekit.ignored_routes', []);
        $path = $request->path();

        foreach ($ignoredRoutes as $pattern) {
            if ($path === ltrim($pattern, '/')) {
                return true;
            }
        }

        return false;
    }

    private function getOperationName(Request $request): string
    {
        $route = $request->route();

        if ($route && $route->getName()) {
            return $route->getName();
        }

        if ($route && $route->uri()) {
            return $request->method() . ' /' . $route->uri();
        }

        return $request->method() . ' ' . $request->path();
    }
}
