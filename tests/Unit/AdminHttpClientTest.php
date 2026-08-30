<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PulseIndex\AdminHttpClient;
use PulseIndex\Exception\PulseIndexException;
use ReflectionClass;

final class AdminHttpClientTest extends TestCase
{
    public function test_from_config_prefers_an_explicit_url(): void
    {
        $client = AdminHttpClient::fromConfig('http://engine:8081/', 'engine:50051', 8081, 'tok');
        self::assertSame('http://engine:8081', $this->baseUrl($client));
    }

    public function test_from_config_derives_url_from_grpc_host(): void
    {
        $client = AdminHttpClient::fromConfig(null, 'engine.internal:50051', 8081, null);
        self::assertSame('http://engine.internal:8081', $this->baseUrl($client));

        $tls = AdminHttpClient::fromConfig(null, 'engine.example.com:443', 443, null);
        self::assertSame('https://engine.example.com:443', $this->baseUrl($tls));
    }

    public function test_transport_failure_is_a_pulseindex_exception(): void
    {
        // Port 1 is reliably connection-refused.
        $client = new AdminHttpClient('http://127.0.0.1:1', 'tok', 2);

        $this->expectException(PulseIndexException::class);
        $this->expectExceptionMessageMatches('/could not reach the engine admin endpoint/');
        $client->markReindexComplete();
    }

    private function baseUrl(AdminHttpClient $client): string
    {
        $prop = (new ReflectionClass($client))->getProperty('baseUrl');
        $prop->setAccessible(true);

        return (string) $prop->getValue($client);
    }
}
