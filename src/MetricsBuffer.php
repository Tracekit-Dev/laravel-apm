<?php

namespace TraceKit\Laravel;

use TraceKit\Laravel\MetricsExporter;

/**
 * MetricsBuffer collects metrics and flushes them periodically
 */
class MetricsBuffer
{
    private array $data = [];
    private MetricsExporter $exporter;
    private int $maxSize = 100;
    private int $lastFlushTime;

    public function __construct(string $endpoint, string $apiKey, string $serviceName)
    {
        $this->exporter = new MetricsExporter($endpoint, $apiKey, $serviceName);
        $this->lastFlushTime = time();

        // Register shutdown function to flush on script end
        register_shutdown_function([$this, 'shutdown']);
    }

    public function add(array $dataPoint): void
    {
        $this->data[] = $dataPoint;

        // Flush if buffer is full or 10 seconds have passed
        if (count($this->data) >= $this->maxSize || (time() - $this->lastFlushTime) >= 10) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        if (empty($this->data)) {
            return;
        }

        $toExport = $this->data;
        $this->data = [];
        $this->lastFlushTime = time();

        try {
            $this->exporter->export($toExport);
        } catch (\Exception $e) {
            // Log error using Laravel's logger
            \Illuminate\Support\Facades\Log::error('Failed to export metrics: ' . $e->getMessage());
        }
    }

    public function shutdown(): void
    {
        $this->flush();
    }
}
