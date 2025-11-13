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
- **Code Monitoring** - Live debugging with breakpoints and variable inspection
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

## Code Monitoring (Live Debugging)

TraceKit includes production-safe code monitoring for live debugging without redeployment.

### Enable Code Monitoring

Add to your `.env` file:

```env
TRACEKIT_CODE_MONITORING_ENABLED=true
TRACEKIT_CODE_MONITORING_POLL_INTERVAL=30  # How often to check for breakpoints (seconds)
                                            # Supported: 1, 5, 10, 15, 30, 60, 300, 600
                                            # Lower = faster updates, higher load
TRACEKIT_CODE_MONITORING_MAX_DEPTH=3       # Nested array/object inspection depth
TRACEKIT_CODE_MONITORING_MAX_STRING=1000   # Max length for captured strings
```

**Configuration Options:**

- **`enabled`**: Master switch for code monitoring (default: `false`)
- **`poll_interval`**: Background polling frequency in seconds (default: `30`)
  - `1` = Every second (highest load, instant updates)
  - `5` = Every 5 seconds (high load, very fast updates)
  - `10` = Every 10 seconds (moderate load, fast updates)
  - `30` = Every 30 seconds (recommended for production)
  - `60` = Every minute (low load, slower updates)
  - `300+` = Every 5+ minutes (very low load, periodic checks)
- **`max_variable_depth`**: How deep to inspect nested structures (default: `3`)
- **`max_string_length`**: Maximum string length to capture (default: `1000`)

### Add Debug Points

Add checkpoints anywhere in your code to capture variable state and stack traces:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function processPayment(Request $request)
    {
        $cart = $request->input('cart');
        $userId = $request->input('user_id');

        // Automatic snapshot capture with label
        tracekit_snapshot('checkout-validation', [
            'user_id' => $userId,
            'cart_items' => count($cart['items'] ?? []),
            'total_amount' => $cart['total'] ?? 0,
        ]);

        try {
            // Process payment
            $result = $this->paymentService->charge($cart['total'], $userId);

            // Another checkpoint
            tracekit_snapshot('payment-success', [
                'user_id' => $userId,
                'payment_id' => $result['payment_id'],
                'amount' => $result['amount'],
            ]);

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            // Automatic error capture (configured in service provider)
            tracekit_error_snapshot($e, [
                'user_id' => $userId,
                'cart_total' => $cart['total'] ?? 0,
            ]);

            return response()->json(['error' => 'Payment failed'], 500);
        }
    }
}
```

### Helper Functions

TraceKit provides convenient helper functions:

```php
// Basic snapshot capture
tracekit_snapshot('my-label', ['key' => 'value']);

// Debug helper (logs + captures)
tracekit_debug('debug-point', ['data' => $data]);

// Error snapshot with exception details
tracekit_error_snapshot($exception, ['context' => 'additional data']);
```

### Automatic Breakpoint Management

- **Auto-Registration**: First call to `tracekit_snapshot()` automatically creates breakpoints in TraceKit
- **Smart Matching**: Breakpoints match by function name + label (stable across code changes)
- **Background Sync**: SDK polls for active breakpoints every 30 seconds
- **Production Safe**: No performance impact when breakpoints are inactive

### What Gets Captured

Snapshots include:
- **Variables**: Local variables at capture point
- **Stack Trace**: Full call stack with file/line numbers
- **Request Context**: HTTP method, URL, headers, query params
- **Execution Time**: When the snapshot was captured
- **Eloquent Models**: Automatically serialized with relationships

### Exception Handling

**Exceptions are automatically captured** when `TRACEKIT_CODE_MONITORING_ENABLED=true` - no additional code required!

The TraceKit service provider automatically registers an exception reporter that:
- Captures full stack traces with file and line numbers
- Records exception type, message, and context
- Includes HTTP request details (route, headers, params)
- Enables automatic code discovery for debugging

```php
// Exceptions are automatically captured - just throw them as normal!
Route::post('/payment', function (Request $request) {
    if (!$request->has('amount')) {
        // This exception will be automatically captured with full context
        throw new \Exception('Amount is required');
    }
    
    $payment = Payment::process($request->amount);
    return response()->json(['payment_id' => $payment->id]);
});

// You can also manually add snapshots before throwing
Route::post('/checkout', function (Request $request) {
    try {
        $order = Order::create($request->all());
    } catch (\Exception $e) {
        // Optional: Add additional context before exception is auto-captured
        tracekit_snapshot('checkout-failed', [
            'cart_total' => $request->input('total'),
            'user_id' => auth()->id(),
            'error' => $e->getMessage(),
        ]);
        
        throw $e; // Still automatically captured!
    }
});
```

**How It Works:**

1. Any uncaught exception is automatically reported to TraceKit
2. Stack trace is formatted and attached to the current OpenTelemetry span
3. Exception event includes `exception.stacktrace` for code discovery
4. TraceKit backend parses stack traces and indexes your code locations
5. Visit the "Browse Code" tab in `/snapshots` to see discovered code

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
