<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PulseIndex\Client;
use PulseIndex\Exception\PulseIndexException;
use ReflectionMethod;

/**
 * What a batch of ids is allowed to be, before any of it reaches the wire.
 *
 * A page can carry ten thousand ids. One bad value in it has to name its own
 * position, or the caller is left bisecting their own input against a server
 * error — and nothing must be sent, because a page that half-applies and then
 * fails is the case the ceiling and the skip semantics exist to avoid.
 */
final class BatchDeleteTest extends TestCase
{
    public function test_integer_ids_pass_through_in_order(): void
    {
        self::assertSame([3, 1, 2], $this->normalise([3, 1, 2]));
    }

    public function test_an_empty_page_is_allowed(): void
    {
        self::assertSame([], $this->normalise([]));
    }

    public function test_a_sparse_array_is_renumbered_not_rejected(): void
    {
        // unset() leaves gaps, and protobuf wants a list.
        $ids = [10, 11, 12];
        unset($ids[1]);

        self::assertSame([10, 12], $this->normalise($ids));
    }

    public function test_a_non_integer_id_names_its_own_position(): void
    {
        $this->expectException(PulseIndexException::class);
        $this->expectExceptionMessageMatches('/entityIds\[1\] is string/');

        /** @phpstan-ignore-next-line deliberately wrong type */
        $this->normalise([10, 'eleven', 12]);
    }

    public function test_a_negative_id_names_its_own_position(): void
    {
        $this->expectException(PulseIndexException::class);
        $this->expectExceptionMessageMatches('/entityIds\[2\] is -5/');

        $this->normalise([10, 11, -5]);
    }

    /**
     * @param array<int, mixed> $entityIds
     * @return list<int>
     */
    private function normalise(array $entityIds): array
    {
        $method = new ReflectionMethod(Client::class, 'normaliseEntityIds');

        /** @var list<int> */
        return $method->invoke(null, $entityIds);
    }
}
