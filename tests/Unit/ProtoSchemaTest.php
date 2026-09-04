<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PulseIndex\Tests\Support\ProtoSchema;

/**
 * Regression guard for the vendored `proto/engine.proto`.
 *
 * WHAT THIS PROVES: the vendored proto still declares exactly the schema this
 * SDK and its generated stubs were built against. Any field added, removed,
 * renamed, renumbered or retyped — and any RPC change — fails here until the
 * fixture below is updated, which puts the change in the pull-request diff
 * where a reviewer sees precisely what moved.
 *
 * WHAT THIS DOES NOT PROVE: that the vendored copy matches the engine. It
 * cannot. The fixture pins what this repository believes, so if the engine
 * moves ahead and nobody syncs, both the file and the fixture stay stale and
 * agree with each other. That gap is closed only by `composer check:proto`
 * with the engine reachable — see scripts/check-proto.php.
 */
final class ProtoSchemaTest extends TestCase
{
    private const PROTO = __DIR__ . '/../../proto/engine.proto';

    /** @return array{rpcs: list<string>, messages: array<string, list<string>>, enums: array<string, list<string>>} */
    private static function expected(): array
    {
        return [
            'rpcs' => [
                'IndexEntity(IndexEntityRequest) -> IndexEntityResponse',
                'BatchIndexEntities(BatchIndexEntitiesRequest) -> BatchIndexEntitiesResponse',
                'DeleteEntity(DeleteEntityRequest) -> DeleteEntityResponse',
                'Search(SearchQueryRequest) -> SearchQueryResponse',
            ],
            'messages' => [
                'IndexEntityRequest' => [
                    '1:uint64 entity_id',
                    '2:uint64 location_prefix',
                    '3:uint32 price',
                    '4:repeated string categories',
                    '5:string tenant_id',
                ],
                'IndexEntityResponse' => ['1:bool success'],
                'BatchIndexEntitiesRequest' => ['1:repeated IndexEntityRequest entities'],
                'BatchIndexEntitiesResponse' => ['1:uint32 indexed_count'],
                'DeleteEntityRequest' => ['1:uint64 entity_id', '2:string tenant_id'],
                'DeleteEntityResponse' => ['1:bool success'],
                'FilterPredicate' => ['1:Operation op', '2:string attribute', '3:uint32 group'],
                'RangePredicate' => ['1:string field', '2:uint32 min_val', '3:uint32 max_val'],
                'SortSpec' => ['1:string field', '2:bool descending'],
                'SearchQueryRequest' => [
                    '1:uint64 location_prefix',
                    '2:repeated FilterPredicate filters',
                    '3:repeated RangePredicate ranges',
                    '4:uint32 limit',
                    '5:uint32 offset',
                    '6:string tenant_id',
                    '7:SortSpec sort',
                ],
                'SearchQueryResponse' => [
                    '1:repeated uint64 matched_entity_ids',
                    '2:uint32 total_matches',
                    '3:uint64 execution_time_us',
                ],
            ],
            'enums' => [
                'FilterPredicate.Operation' => ['MUST=0', 'SHOULD=1', 'MUST_NOT=2'],
            ],
        ];
    }

    private static function actual(): ProtoSchema
    {
        $path = realpath(self::PROTO);
        self::assertIsString($path, 'proto/engine.proto is missing');

        return ProtoSchema::parse((string) file_get_contents($path));
    }

    public function test_declares_exactly_the_expected_rpcs(): void
    {
        self::assertSame(self::expected()['rpcs'], self::actual()->rpcs);
    }

    public function test_declares_exactly_the_expected_messages(): void
    {
        $expected = array_keys(self::expected()['messages']);
        $actual = array_keys(self::actual()->messages);
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
    }

    public function test_every_field_keeps_its_number_and_type(): void
    {
        $actual = self::actual();
        foreach (self::expected()['messages'] as $message => $fields) {
            self::assertArrayHasKey($message, $actual->messages, "message {$message} is missing");
            self::assertSame($fields, $actual->messages[$message], "field drift in message {$message}");
        }
    }

    public function test_declares_the_expected_enum_values(): void
    {
        self::assertSame(self::expected()['enums'], self::actual()->enums);
    }

    // ---------------------------------------------------------------------
    // Meta-tests: prove the guard actually bites.
    //
    // A schema guard nobody has tried to fool is an assumption, not a guard.
    // These mutate the proto text in memory — no filesystem, no Docker file
    // sync, deterministic — and assert that ProtoSchema::diff reports the
    // drift, or stays silent when nothing structural changed.
    // ---------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string, 2: bool, 3: string}> */
    public static function driftCases(): array
    {
        return [
            'removed field' => [
                '  uint32 total_matches = 2;',
                '',
                true,
                'lost   2:uint32 total_matches',
            ],
            'renumbered field' => [
                'uint32 total_matches = 2;',
                'uint32 total_matches = 9;',
                true,
                'gained 9:uint32 total_matches',
            ],
            'renamed field' => [
                'uint64 execution_time_us = 3;',
                'uint64 executionTimeUs = 3;',
                true,
                'gained 3:uint64 executionTimeUs',
            ],
            'retyped field' => [
                'uint32 total_matches = 2;',
                'uint64 total_matches = 2;',
                true,
                'gained 2:uint64 total_matches',
            ],
            'removed rpc' => [
                'rpc DeleteEntity (DeleteEntityRequest) returns (DeleteEntityResponse);',
                '',
                true,
                'rpc removed: DeleteEntity',
            ],
            'renumbered enum value' => [
                'MUST_NOT = 2;',
                'MUST_NOT = 3;',
                true,
                'enum FilterPredicate.Operation',
            ],
            'comment only' => [
                '// Tenant / namespace isolation key.',
                '// reworded, structurally identical',
                false,
                '',
            ],
        ];
    }

    #[DataProvider('driftCases')]
    public function test_guard_detects_drift(
        string $find,
        string $replace,
        bool $expectDrift,
        string $expectedFragment,
    ): void {
        $path = realpath(self::PROTO);
        self::assertIsString($path);
        $original = (string) file_get_contents($path);

        self::assertStringContainsString(
            $find,
            $original,
            "the sabotage anchor is stale: proto no longer contains {$find}",
        );

        $mutated = str_replace($find, $replace, $original);
        $diff = ProtoSchema::diff(
            ProtoSchema::parse($original),
            ProtoSchema::parse($mutated),
        );

        if (!$expectDrift) {
            self::assertSame([], $diff, 'a comment-only edit must not register as schema drift');

            return;
        }

        self::assertNotSame([], $diff, 'the guard did not notice the mutation');
        self::assertStringContainsString(
            $expectedFragment,
            implode("\n", $diff),
            'the guard noticed drift but did not name it usefully',
        );
    }

    public function test_still_carries_the_recovery_fields_the_sdk_reads(): void
    {
        // Client::search() reads these by name, so a rename upstream would be
        // silent at runtime rather than loud.
        $search = self::actual()->messages['SearchQueryResponse'] ?? [];
        foreach (self::expected()['messages']['SearchQueryResponse'] as $field) {
            self::assertContains($field, $search, "SearchQueryResponse lost {$field}");
        }
    }
}
