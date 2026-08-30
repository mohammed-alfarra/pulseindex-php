<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PulseIndex\Client;
use ReflectionMethod;

final class ClientSslTest extends TestCase
{
    public function test_string_false_is_not_treated_as_true(): void
    {
        $this->assertFalse($this->ssl(['ssl' => 'false']));
        $this->assertFalse($this->ssl(['ssl' => '0']));
        $this->assertFalse($this->ssl(['ssl' => false]));
    }

    public function test_true_variants_enable_tls(): void
    {
        $this->assertTrue($this->ssl(['ssl' => true]));
        $this->assertTrue($this->ssl(['ssl' => 'true']));
        $this->assertTrue($this->ssl(['ssl' => '1']));
    }

    public function test_absent_ssl_defaults_to_plaintext_for_local_development(): void
    {
        $previous = getenv('PULSEINDEX_SSL');
        putenv('PULSEINDEX_SSL');

        try {
            $this->assertFalse($this->ssl([]));
        } finally {
            if ($previous === false) {
                putenv('PULSEINDEX_SSL');
            } else {
                putenv('PULSEINDEX_SSL='.$previous);
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function ssl(array $config): bool
    {
        $method = new ReflectionMethod(Client::class, 'sslEnabled');

        return (bool) $method->invoke(null, $config);
    }
}
