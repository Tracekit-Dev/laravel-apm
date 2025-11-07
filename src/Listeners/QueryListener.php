<?php

namespace TraceKit\Laravel\Listeners;

use Illuminate\Database\Events\QueryExecuted;
use TraceKit\Laravel\TracekitClient;

class QueryListener
{
    private TracekitClient $client;

    public function __construct(TracekitClient $client)
    {
        $this->client = $client;
    }

    public function handle(QueryExecuted $event): void
    {
        // Check if database tracing is enabled
        if (!config('tracekit.enabled') || !config('tracekit.features.database')) {
            return;
        }

        // Only trace if we have an active trace
        if (!$this->client->getCurrentTraceId()) {
            return;
        }

        $duration = $event->time; // milliseconds
        $sql = $event->sql;
        $bindings = config('tracekit.include_query_bindings', true) ? $event->bindings : [];

        // Replace bindings in SQL (for display purposes)
        $boundSql = $this->bindQueryParameters($sql, $bindings);

        $attributes = [
            'db.system' => $event->connection->getDriverName(),
            'db.name' => $event->connection->getDatabaseName(),
            'db.statement' => $boundSql,
            'db.duration_ms' => $duration,
        ];

        // Highlight slow queries
        $slowThreshold = config('tracekit.slow_query_threshold', 100);
        if ($duration > $slowThreshold) {
            $attributes['db.slow_query'] = true;
        }

        // Create a span for this query
        $spanId = $this->client->startSpan('db.query', null, $attributes);
        $this->client->endSpan($spanId, [], $duration > $slowThreshold ? 'ERROR' : 'OK');
    }

    private function bindQueryParameters(string $sql, array $bindings): string
    {
        if (empty($bindings)) {
            return $sql;
        }

        // Simple binding replacement (not perfect, but good enough for tracing)
        $boundSql = $sql;

        foreach ($bindings as $binding) {
            $value = $this->formatBinding($binding);
            $boundSql = preg_replace('/\?/', $value, $boundSql, 1);
        }

        return $boundSql;
    }

    private function formatBinding($binding): string
    {
        if (is_null($binding)) {
            return 'NULL';
        }

        if (is_bool($binding)) {
            return $binding ? 'TRUE' : 'FALSE';
        }

        if (is_int($binding) || is_float($binding)) {
            return (string) $binding;
        }

        if ($binding instanceof \DateTime) {
            return "'" . $binding->format('Y-m-d H:i:s') . "'";
        }

        // Escape and quote strings
        return "'" . addslashes((string) $binding) . "'";
    }
}
