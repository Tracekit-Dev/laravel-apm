<?php

namespace TraceKit\Laravel\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use TraceKit\Laravel\TracekitClient;

class JobListener
{
    private TracekitClient $client;
    private array $jobSpans = []; // Now stores SpanInterface

    public function __construct(TracekitClient $client)
    {
        $this->client = $client;
    }

    public function handleJobProcessing(JobProcessing $event): void
    {
        // Check if queue tracing is enabled
        if (!config('tracekit.enabled') || !config('tracekit.features.queue')) {
            return;
        }

        $jobName = $event->job->resolveName();
        $connectionName = $event->connectionName;

        // Start a new trace for this job
        $span = $this->client->startTrace("job: {$jobName}", [
            'job.name' => $jobName,
            'job.connection' => $connectionName,
            'job.queue' => $event->job->getQueue(),
            'job.attempts' => $event->job->attempts(),
        ]);

        // Store span ID for later
        $this->jobSpans[$event->job->getJobId()] = $span;
    }

    public function handleJobProcessed(JobProcessed $event): void
    {
        if (!config('tracekit.enabled') || !config('tracekit.features.queue')) {
            return;
        }

        $jobId = $event->job->getJobId();

        if (isset($this->jobSpans[$jobId])) {
            $span = $this->jobSpans[$jobId];
            $this->client->endSpan($span, [
                'job.status' => 'completed',
            ], 'OK');
            $this->client->flush();

            unset($this->jobSpans[$jobId]);
        }
    }

    public function handleJobFailed(JobFailed $event): void
    {
        if (!config('tracekit.enabled') || !config('tracekit.features.queue')) {
            return;
        }

        $jobId = $event->job->getJobId();

        if (isset($this->jobSpans[$jobId])) {
            $span = $this->jobSpans[$jobId];
            $this->client->recordException($span, $event->exception);
            $this->client->endSpan($span, [
                'job.status' => 'failed',
                'job.failed_reason' => $event->exception->getMessage(),
            ], 'ERROR');
            $this->client->flush();

            unset($this->jobSpans[$jobId]);
        }
    }
}
