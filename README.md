# TraceKit APM for Laravel

Zero-config distributed tracing and performance monitoring for Laravel applications.

[![Latest Version](https://img.shields.io/packagist/v/tracekit/laravel-apm.svg)](https://packagist.org/packages/tracekit/laravel-apm)
[![Total Downloads](https://img.shields.io/packagist/dt/tracekit/laravel-apm.svg)](https://packagist.org/packages/tracekit/laravel-apm)
[![License](https://img.shields.io/packagist/l/tracekit/laravel-apm.svg)](https://packagist.org/packages/tracekit/laravel-apm)

## Features

- **Zero Configuration** - Works out of the box with sensible defaults
- **Automatic Instrumentation** - No code changes needed
- **HTTP Request Tracing** - Track every request, route, and middleware
- **Database Query Monitoring** - See every query with actual SQL and bindings
- **Queue Job Tracking** - Monitor Laravel jobs and queues
- **Slow Query Detection** - Automatically highlight slow database queries
- **Error Tracking** - Capture exceptions with full context
- **Low Overhead** - < 5% performance impact

## Installation

```bash
composer require tracekit/laravel-apm
```

## Quick Start

### 1. Install the package

```bash
php artisan tracekit:install
```

### 2. Add your API key to `.env`

```env
TRACEKIT_API_KEY=your-api-key-here
TRACEKIT_SERVICE_NAME=my-laravel-app

# Optional: Custom endpoint (defaults to https://app.tracekit.dev/v1/traces)
# TRACEKIT_ENDPOINT=https://your-custom-endpoint.com/v1/traces
```

Get your API key at [https://app.tracekit.dev](https://app.tracekit.dev)

### 3. Done!

Your Laravel app is now automatically traced. Visit your TraceKit dashboard to see your traces.

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=tracekit-config
```

This creates `config/tracekit.php` where you can customize:

### Laravel 12 Setup

Laravel 12 changed how middleware is registered. TraceKit attempts to register middleware automatically for all Laravel versions (10, 11, and 12).

**If automatic registration doesn't work**, you can manually add the TraceKit middleware to your `bootstrap/app.php`:

```php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use TraceKit\Laravel\Middleware\TracekitMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            TracekitMiddleware::class,
        ]);
        $middleware->api(append: [
            TracekitMiddleware::class,
        ]);
    })
    // ... rest of your configuration
    ->create();
```

For most cases, the automatic registration via the service provider should work without any manual configuration.

### Configuration Options

```php
return [
    // Enable/disable tracing
    'enabled' => env('TRACEKIT_ENABLED', env('APP_ENV') !== 'local'),

    // Your TraceKit API key
    'api_key' => env('TRACEKIT_API_KEY', ''),

    // OTLP endpoint for sending traces
    'endpoint' => env('TRACEKIT_ENDPOINT', 'https://app.tracekit.dev/v1/traces'),

    // Service name as it appears in TraceKit
    'service_name' => env('TRACEKIT_SERVICE_NAME', env('APP_NAME', 'laravel-app')),

    // Sample rate (0.0 to 1.0)
    'sample_rate' => env('TRACEKIT_SAMPLE_RATE', 1.0),

    // Enable/disable specific features
    'features' => [
        'http' => env('TRACEKIT_HTTP_ENABLED', true),
        'database' => env('TRACEKIT_DATABASE_ENABLED', true),
        'cache' => env('TRACEKIT_CACHE_ENABLED', true),      // Coming soon
        'queue' => env('TRACEKIT_QUEUE_ENABLED', true),
        'redis' => env('TRACEKIT_REDIS_ENABLED', true),      // Coming soon
    ],

    // Routes to ignore
    'ignored_routes' => [
        '/health',
        '/up',
        '/_healthz',
    ],

    // Slow query threshold (ms)
    'slow_query_threshold' => env('TRACEKIT_SLOW_QUERY_MS', 100),

    // Include query bindings in traces
    'include_query_bindings' => env('TRACEKIT_INCLUDE_BINDINGS', true),
];
```

## What Gets Traced?

### HTTP Requests

Every HTTP request is automatically traced with:

- Route name and URI
- HTTP method and status code
- Request duration
- User agent and client IP
- Query parameters
- Response size

### Database Queries

All database queries are traced with:

- Actual SQL with bound parameters
- Query duration
- Slow query highlighting (configurable threshold)
- Connection name and database

### Queue Jobs

Laravel queue jobs are traced with:

- Job class name
- Queue name and connection
- Job status (completed/failed)
- Execution time
- Failure reasons and exceptions

### Errors and Exceptions

All exceptions are automatically captured with:

- Exception type and message
- Full stack trace
- Request context
- User information

### Coming Soon

The following features are planned for future releases:

- **Cache Operations** - Redis, Memcached, and file cache tracing
- **Redis Commands** - Direct Redis command tracing
- **External HTTP Calls** - Outgoing HTTP request tracking

## Advanced Usage

### Manual Tracing

You can create custom traces in your code:

```php
use TraceKit\Laravel\TracekitClient;

class MyController extends Controller
{
    public function myMethod(TracekitClient $tracekit)
    {
        $span = $tracekit->startSpan('my-custom-operation', null, [
            'user.id' => auth()->id(),
            'custom.attribute' => 'value',
        ]);

        try {
            // Your code here
            $result = $this->doSomething();

            $tracekit->endSpan($span, [
                'result.count' => count($result),
            ]);
        } catch (\Exception $e) {
            $tracekit->recordException($span, $e);
            $tracekit->endSpan($span, [], 'ERROR');
            throw $e;
        }
    }
}
```

### Environment-Based Configuration

Disable tracing in local development:

```env
# .env.local
TRACEKIT_ENABLED=false
```

Enable only specific features:

```env
TRACEKIT_HTTP_ENABLED=true
TRACEKIT_DATABASE_ENABLED=true
TRACEKIT_CACHE_ENABLED=true
TRACEKIT_QUEUE_ENABLED=false
TRACEKIT_REDIS_ENABLED=true
```

### Sampling

Trace only a percentage of requests (e.g., 10%):

```env
TRACEKIT_SAMPLE_RATE=0.1
```

## Performance

TraceKit APM is designed to have minimal performance impact:

- **< 5% overhead** on average request time
- Asynchronous trace sending (doesn't block responses)
- Automatic batching and compression
- Configurable sampling for high-traffic apps

## Security

- Sensitive data handling: Query bindings can be disabled
- Secure transmission: HTTPS only
- API key authentication
- No PII collected by default

Disable query bindings:

```env
TRACEKIT_INCLUDE_BINDINGS=false
```

## Requirements

- PHP 8.1 or higher
- Laravel 10.x, 11.x, or 12.x

## Support

- Documentation: [https://app.tracekit.dev/docs](https://app.tracekit.dev/docs)
- Issues: [https://github.com/Tracekit-Dev/laravel-apm/issues](https://github.com/Tracekit-Dev/laravel-apm/issues)
- Email: support@tracekit.dev

## License

MIT License. See [LICENSE](LICENSE) for details.

## Credits

Built with ❤️ by the TraceKit team.
