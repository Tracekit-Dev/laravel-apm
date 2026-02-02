<?php

namespace TraceKit\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use TraceKit\Laravel\TracekitClient;
use ReflectionClass;
use ReflectionMethod;

class UrlResolutionTest extends TestCase
{
    private TracekitClient $client;
    private ReflectionMethod $resolveEndpointMethod;
    private ReflectionMethod $extractBaseURLMethod;

    protected function setUp(): void
    {
        // Use Reflection to access private methods without running constructor
        $reflection = new ReflectionClass(TracekitClient::class);

        // Create instance without calling constructor to avoid initialization issues
        $this->client = $reflection->newInstanceWithoutConstructor();

        $this->resolveEndpointMethod = $reflection->getMethod('resolveEndpoint');
        $this->resolveEndpointMethod->setAccessible(true);

        $this->extractBaseURLMethod = $reflection->getMethod('extractBaseURL');
        $this->extractBaseURLMethod->setAccessible(true);
    }

    // Just host cases
    public function testJustHostWithSSL(): void
    {
        $result = $this->resolveEndpointMethod->invoke($this->client, 'app.tracekit.dev', '/v1/traces', true);
        $this->assertEquals('https://app.tracekit.dev/v1/traces', $result);
    }

    public function testJustHostWithoutSSL(): void
    {
        $result = $this->resolveEndpointMethod->invoke($this->client, 'localhost:8081', '/v1/traces', false);
        $this->assertEquals('http://localhost:8081/v1/traces', $result);
    }

    public function testJustHostWithTrailingSlash(): void
    {
        $result = $this->resolveEndpointMethod->invoke($this->client, 'app.tracekit.dev/', '/v1/metrics', true);
        $this->assertEquals('https://app.tracekit.dev/v1/metrics', $result);
    }

    // Host with scheme cases
    public function testHttpWithHostOnly(): void
    {
        $result = $this->resolveEndpointMethod->invoke($this->client, 'http://localhost:8081', '/v1/traces', true);
        $this->assertEquals('http://localhost:8081/v1/traces', $result);
    }

    public function testHttpsWithHostOnly(): void
    {
        $result = $this->resolveEndpointMethod->invoke($this->client, 'https://app.tracekit.dev', '/v1/metrics', false);
        $this->assertEquals('https://app.tracekit.dev/v1/metrics', $result);
    }

    public function testHttpWithHostAndTrailingSlash(): void
    {
        $result = $this->resolveEndpointMethod->invoke($this->client, 'http://localhost:8081/', '/v1/traces', true);
        $this->assertEquals('http://localhost:8081/v1/traces', $result);
    }

    // Full URL cases
    public function testFullUrlWithStandardPath(): void
    {
        $result = $this->resolveEndpointMethod->invoke($this->client, 'http://localhost:8081/v1/traces', '/v1/traces', true);
        $this->assertEquals('http://localhost:8081/v1/traces', $result);
    }

    public function testFullUrlWithCustomPath(): void
    {
        $result = $this->resolveEndpointMethod->invoke($this->client, 'http://localhost:8081/custom/path', '/v1/traces', true);
        $this->assertEquals('http://localhost:8081/custom/path', $result);
    }

    public function testFullUrlWithTrailingSlash(): void
    {
        $result = $this->resolveEndpointMethod->invoke($this->client, 'https://app.tracekit.dev/api/v2/', '/v1/traces', false);
        $this->assertEquals('https://app.tracekit.dev/api/v2', $result);
    }

    // Edge cases
    public function testEmptyPathForSnapshots(): void
    {
        $result = $this->resolveEndpointMethod->invoke($this->client, 'app.tracekit.dev', '', true);
        $this->assertEquals('https://app.tracekit.dev', $result);
    }

    public function testHttpWithEmptyPath(): void
    {
        $result = $this->resolveEndpointMethod->invoke($this->client, 'http://localhost:8081', '', true);
        $this->assertEquals('http://localhost:8081', $result);
    }

    public function testHttpWithTrailingSlashAndEmptyPath(): void
    {
        $result = $this->resolveEndpointMethod->invoke($this->client, 'http://localhost:8081/', '', true);
        $this->assertEquals('http://localhost:8081', $result);
    }

    public function testSnapshotWithFullUrlExtractsBaseHttp(): void
    {
        $result = $this->resolveEndpointMethod->invoke($this->client, 'http://localhost:8081/v1/traces', '', true);
        $this->assertEquals('http://localhost:8081', $result);
    }

    public function testSnapshotWithFullUrlExtractsBaseHttps(): void
    {
        $result = $this->resolveEndpointMethod->invoke($this->client, 'https://app.tracekit.dev/v1/traces', '', false);
        $this->assertEquals('https://app.tracekit.dev', $result);
    }

    // ExtractBaseURL tests
    public function testExtractBaseFromTracesEndpointHttp(): void
    {
        $result = $this->extractBaseURLMethod->invoke($this->client, 'http://localhost:8081/v1/traces');
        $this->assertEquals('http://localhost:8081', $result);
    }

    public function testExtractBaseFromTracesEndpointHttps(): void
    {
        $result = $this->extractBaseURLMethod->invoke($this->client, 'https://app.tracekit.dev/v1/traces');
        $this->assertEquals('https://app.tracekit.dev', $result);
    }

    public function testExtractBaseFromMetricsEndpoint(): void
    {
        $result = $this->extractBaseURLMethod->invoke($this->client, 'https://app.tracekit.dev/v1/metrics');
        $this->assertEquals('https://app.tracekit.dev', $result);
    }

    public function testKeepCustomPathUrlsAsIs(): void
    {
        $result = $this->extractBaseURLMethod->invoke($this->client, 'http://localhost:8081/custom');
        $this->assertEquals('http://localhost:8081/custom', $result);
    }

    public function testKeepCustomBasePathUrlsAsIs(): void
    {
        $result = $this->extractBaseURLMethod->invoke($this->client, 'http://localhost:8081/api');
        $this->assertEquals('http://localhost:8081/api', $result);
    }

    public function testExtractFromApiV1TracesPath(): void
    {
        $result = $this->extractBaseURLMethod->invoke($this->client, 'https://app.tracekit.dev/api/v1/traces');
        $this->assertEquals('https://app.tracekit.dev', $result);
    }

    public function testExtractFromApiV1MetricsPath(): void
    {
        $result = $this->extractBaseURLMethod->invoke($this->client, 'https://app.tracekit.dev/api/v1/metrics');
        $this->assertEquals('https://app.tracekit.dev', $result);
    }

    public function testReturnAsIsWhenNoPathComponent(): void
    {
        $result = $this->extractBaseURLMethod->invoke($this->client, 'https://app.tracekit.dev');
        $this->assertEquals('https://app.tracekit.dev', $result);
    }

    /**
     * @dataProvider endpointResolutionScenariosProvider
     */
    public function testEndpointResolutionScenarios(
        string $endpoint,
        string $tracesPath,
        string $metricsPath,
        bool $useSSL,
        array $expected
    ): void {
        // Resolve endpoints
        $tracesEndpoint = $this->resolveEndpointMethod->invoke($this->client, $endpoint, $tracesPath, $useSSL);
        $metricsEndpoint = $this->resolveEndpointMethod->invoke($this->client, $endpoint, $metricsPath, $useSSL);
        $snapshotEndpoint = $this->resolveEndpointMethod->invoke($this->client, $endpoint, '', $useSSL);

        $this->assertEquals($expected['traces'], $tracesEndpoint, 'Traces endpoint mismatch');
        $this->assertEquals($expected['metrics'], $metricsEndpoint, 'Metrics endpoint mismatch');
        $this->assertEquals($expected['snapshots'], $snapshotEndpoint, 'Snapshots endpoint mismatch');
    }

    public static function endpointResolutionScenariosProvider(): array
    {
        return [
            'default production config' => [
                'app.tracekit.dev',
                '/v1/traces',
                '/v1/metrics',
                true,
                [
                    'traces' => 'https://app.tracekit.dev/v1/traces',
                    'metrics' => 'https://app.tracekit.dev/v1/metrics',
                    'snapshots' => 'https://app.tracekit.dev',
                ],
            ],
            'local development' => [
                'localhost:8080',
                '/v1/traces',
                '/v1/metrics',
                false,
                [
                    'traces' => 'http://localhost:8080/v1/traces',
                    'metrics' => 'http://localhost:8080/v1/metrics',
                    'snapshots' => 'http://localhost:8080',
                ],
            ],
            'custom paths' => [
                'app.tracekit.dev',
                '/api/v2/traces',
                '/api/v2/metrics',
                true,
                [
                    'traces' => 'https://app.tracekit.dev/api/v2/traces',
                    'metrics' => 'https://app.tracekit.dev/api/v2/metrics',
                    'snapshots' => 'https://app.tracekit.dev',
                ],
            ],
            'full URLs provided' => [
                'http://localhost:8081/custom',
                '/v1/traces',
                '/v1/metrics',
                true, // Should be ignored
                [
                    'traces' => 'http://localhost:8081/custom',
                    'metrics' => 'http://localhost:8081/custom',
                    'snapshots' => 'http://localhost:8081/custom',
                ],
            ],
            'trailing slash handling' => [
                'http://localhost:8081/',
                '/v1/traces',
                '/v1/metrics',
                false,
                [
                    'traces' => 'http://localhost:8081/v1/traces',
                    'metrics' => 'http://localhost:8081/v1/metrics',
                    'snapshots' => 'http://localhost:8081',
                ],
            ],
            'full URL with path - snapshots extract base' => [
                'http://localhost:8081/v1/traces',
                '/v1/traces',
                '/v1/metrics',
                true, // Should be ignored
                [
                    'traces' => 'http://localhost:8081/v1/traces',
                    'metrics' => 'http://localhost:8081/v1/traces',
                    'snapshots' => 'http://localhost:8081', // Should extract base URL
                ],
            ],
        ];
    }
}
