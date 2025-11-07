<?php

namespace TraceKit\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    protected $signature = 'tracekit:install';
    protected $description = 'Install TraceKit APM for Laravel';

    public function handle(): int
    {
        $this->info('Installing TraceKit APM...');
        $this->newLine();

        // Publish configuration
        $this->call('vendor:publish', [
            '--tag' => 'tracekit-config',
            '--force' => $this->option('force', false),
        ]);

        $this->info('✓ Configuration published');

        // Check if .env has API key
        if (!env('TRACEKIT_API_KEY')) {
            $this->newLine();
            $this->warn('⚠ TRACEKIT_API_KEY not found in your .env file');
            $this->line('Add the following to your .env:');
            $this->newLine();
            $this->line('TRACEKIT_API_KEY=your-api-key-here');
            $this->line('TRACEKIT_SERVICE_NAME=' . config('app.name', 'laravel-app'));
            $this->newLine();
            $this->info('Get your API key at: https://app.tracekit.dev');
        }

        $this->newLine();
        $this->info('✅ TraceKit APM installed successfully!');
        $this->newLine();
        $this->line('Next steps:');
        $this->line('1. Add TRACEKIT_API_KEY to your .env file');
        $this->line('2. Configure tracing options in config/tracekit.php');
        $this->line('3. Start monitoring: your app is now automatically traced!');
        $this->newLine();

        return self::SUCCESS;
    }
}
