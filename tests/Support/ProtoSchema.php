<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Support;

/**
 * Structural reader for `proto/engine.proto`.
 *
 * Dependency-free and small enough to review. Shared by the schema regression
 * test and by `scripts/check-proto.php`, so the guard and the fixture can never
 * disagree about what the proto says.
 *
 * This reads the *vendored* file. It cannot, and does not, establish that the
 * vendored file matches the engine — see `scripts/check-proto.php` for that.
 */
final class ProtoSchema
{
    /** @param list<string> $rpcs
     *  @param array<string, list<string>> $messages
     *  @param array<string, list<string>> $enums */
    private function __construct(
        public readonly array $rpcs,
        public readonly array $messages,
        public readonly array $enums,
    ) {
    }

    public static function parse(string $text): self
    {
        $src = (string) preg_replace('~/\*.*?\*/~s', '', $text);
        $src = (string) preg_replace('~//[^\n]*~', '', $src);

        $rpcs = [];
        preg_match_all(
            '~rpc\s+(\w+)\s*\(\s*([\w.]+)\s*\)\s*returns\s*\(\s*([\w.]+)\s*\)~',
            $src,
            $m,
            PREG_SET_ORDER,
        );
        foreach ($m as $match) {
            $rpcs[] = sprintf('%s(%s) -> %s', $match[1], $match[2], $match[3]);
        }

        $messages = [];
        $enums = [];
        $offset = 0;
        while (preg_match('~\bmessage\s+(\w+)\s*\{~', $src, $mm, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $name = $mm[1][0];
            $open = $mm[0][1] + strlen($mm[0][0]) - 1;
            $close = self::matchBrace($src, $open);
            $body = substr($src, $open + 1, $close - $open - 1);

            if (preg_match_all('~\benum\s+(\w+)\s*\{([^}]*)\}~', $body, $em, PREG_SET_ORDER) > 0) {
                foreach ($em as $enum) {
                    preg_match_all('~(\w+)\s*=\s*(\d+)\s*;~', $enum[2], $vals, PREG_SET_ORDER);
                    $enums[$name . '.' . $enum[1]] = array_map(
                        static fn (array $v): string => $v[1] . '=' . $v[2],
                        $vals,
                    );
                }
            }
            $body = (string) preg_replace('~\benum\s+\w+\s*\{[^}]*\}~', '', $body);

            preg_match_all(
                '~(repeated\s+)?([\w.]+)\s+(\w+)\s*=\s*(\d+)\s*;~',
                $body,
                $fm,
                PREG_SET_ORDER,
            );
            $messages[$name] = array_map(
                static fn (array $f): string => sprintf(
                    '%s:%s%s %s',
                    $f[4],
                    $f[1] !== '' ? 'repeated ' : '',
                    $f[2],
                    $f[3],
                ),
                $fm,
            );

            $offset = $close;
        }

        return new self($rpcs, $messages, $enums);
    }

    /** @return array{rpcs: list<string>, messages: array<string, list<string>>, enums: array<string, list<string>>} */
    public function toArray(): array
    {
        return ['rpcs' => $this->rpcs, 'messages' => $this->messages, 'enums' => $this->enums];
    }

    /**
     * Semantic differences, immune to line shifts, comment edits and reordering.
     *
     * @return list<string>
     */
    public static function diff(self $expected, self $actual): array
    {
        $out = [];

        foreach ($expected->rpcs as $rpc) {
            if (!in_array($rpc, $actual->rpcs, true)) {
                $out[] = "- rpc removed: {$rpc}";
            }
        }
        foreach ($actual->rpcs as $rpc) {
            if (!in_array($rpc, $expected->rpcs, true)) {
                $out[] = "+ rpc added:   {$rpc}";
            }
        }

        $names = array_unique([
            ...array_keys($expected->messages),
            ...array_keys($actual->messages),
        ]);
        sort($names);
        foreach ($names as $name) {
            $a = $expected->messages[$name] ?? null;
            $b = $actual->messages[$name] ?? null;
            if ($a === null) {
                $out[] = "+ message added:   {$name}";
                continue;
            }
            if ($b === null) {
                $out[] = "- message removed: {$name}";
                continue;
            }
            foreach ($a as $field) {
                if (!in_array($field, $b, true)) {
                    $out[] = "- {$name}: lost   {$field}";
                }
            }
            foreach ($b as $field) {
                if (!in_array($field, $a, true)) {
                    $out[] = "+ {$name}: gained {$field}";
                }
            }
        }

        $enumNames = array_unique([
            ...array_keys($expected->enums),
            ...array_keys($actual->enums),
        ]);
        sort($enumNames);
        foreach ($enumNames as $name) {
            $a = implode(',', $expected->enums[$name] ?? []);
            $b = implode(',', $actual->enums[$name] ?? []);
            if ($a !== $b) {
                $out[] = "~ enum {$name}: [{$a}] -> [{$b}]";
            }
        }

        return $out;
    }

    private static function matchBrace(string $src, int $openBrace): int
    {
        $depth = 0;
        $len = strlen($src);
        for ($i = $openBrace; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        throw new \RuntimeException('unbalanced braces in engine.proto');
    }
}
