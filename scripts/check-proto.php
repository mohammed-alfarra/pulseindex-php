#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Compare the vendored proto against the engine's own copy.
 *
 * This is the ONLY check that can detect the engine moving ahead of this SDK.
 * The schema fixture in tests/Unit/ProtoSchemaTest.php cannot: it pins what
 * this repository believes, so a stale file and a stale fixture agree with each
 * other and pass.
 *
 * Usage:
 *   composer check:proto                    # skip loudly when the engine is absent
 *   composer check:proto -- --require-engine # absent engine is a FAILURE (use in CI)
 *   PULSEINDEX_PROTO=/path/to/engine.proto composer check:proto
 *
 * A guard that cannot reach the source it is guarding against must not report
 * success. Without --require-engine it exits 0 but says, in as many words, that
 * nothing was verified; with it, that same state is an error.
 */

$root = dirname(__DIR__);
$vendored = $root . '/proto/engine.proto';
$requireEngine = in_array('--require-engine', array_slice($argv, 1), true);

require $root . '/vendor/autoload.php';

use PulseIndex\Tests\Support\ProtoSchema;

/** php_* options are local to this SDK and are never present upstream. */
$normalise = static function (string $text, bool $forHash = false): string {
    // Comments never reach the generated stubs, and the vendored copy's are
    // deliberately shorter than the engine's because this file is published.
    $text = (string) preg_replace('~//.*~', '', $text);
    $lines = preg_split('~\R~', $text) ?: [];
    $kept = array_filter($lines, static function (string $l) use ($forHash): bool {
        if (preg_match('~^\s*option\s+php_~', $l) === 1) {
            return false;
        }

        // Blank lines go in both paths. Stripping comments above turns every
        // comment-only line into an empty one, and the two files carry
        // different amounts of prose by design — so keeping blanks would
        // compare documentation volume rather than declarations.
        return trim($l) !== '';
    });

    $joined = implode("\n", $kept);

    return $forHash ? $joined . "\n" : rtrim(preg_replace('~[ \t]+$~m', '', $joined) ?? '');
};

// ---------------------------------------------------------------------------
// (1) Stub consistency: does the vendored proto still match what was last
//     compiled into generated/? Neither the schema fixture nor the engine diff
//     covers this — a hand-edited proto leaves the generated stubs stale.
// ---------------------------------------------------------------------------
$hashFile = $root . '/proto/engine.proto.sha256';
$vendorText = (string) file_get_contents($vendored);

if (!is_file($hashFile)) {
    fwrite(STDERR, "error: {$hashFile} is missing — run 'composer build-proto'.\n");
    exit(1);
}

$have = hash('sha256', $normalise($vendorText, true));
$want = trim((string) file_get_contents($hashFile));

if ($have !== $want) {
    fwrite(STDERR, "error: proto/engine.proto was edited without regenerating.\n");
    fwrite(STDERR, "       The stubs in generated/ no longer correspond to it.\n");
    fwrite(STDERR, "       Run 'composer build-proto' and commit the result.\n");
    exit(1);
}

echo "ok: vendored proto matches the stubs generated from it\n";

// ---------------------------------------------------------------------------
// (2) Engine comparison: has the engine moved ahead?
// ---------------------------------------------------------------------------
$explicit = getenv('PULSEINDEX_PROTO');
$source = null;

if (is_string($explicit) && $explicit !== '') {
    // An explicitly configured path that does not exist is a misconfiguration.
    // Falling back to a sibling checkout would hide it.
    if (!is_file($explicit)) {
        fwrite(STDERR, "error: PULSEINDEX_PROTO points at a path that does not exist: {$explicit}\n");
        exit(1);
    }
    $source = $explicit;
} else {
    foreach ([
        $root . '/../pulseindex-engine/proto/engine.proto',
        $root . '/../PulseIndex/proto/engine.proto',
    ] as $candidate) {
        if (is_file($candidate)) {
            $source = $candidate;
            break;
        }
    }
}

if ($source === null) {
    $message = <<<TXT
    NOT VERIFIED: the engine proto was not found, so the vendored copy was not
                  compared against it. This check has established nothing about
                  whether the engine has moved ahead.
                  Provide it with PULSEINDEX_PROTO, or check out the engine
                  beside this repository.
    TXT;

    if ($requireEngine) {
        fwrite(STDERR, "error: --require-engine was given but no engine proto is reachable.\n");
        fwrite(STDERR, $message . "\n");
        fwrite(STDERR, "       In CI this usually means the engine checkout failed —\n");
        fwrite(STDERR, "       check ENGINE_REPO_TOKEN rather than relaxing this check.\n");
        exit(1);
    }

    fwrite(STDERR, $message . "\n");
    fwrite(STDERR, "       Run with --require-engine to make this state an error.\n");
    exit(0);
}

$engineText = (string) file_get_contents($source);

$diff = ProtoSchema::subsetDiff(
    ProtoSchema::parse($engineText),
    ProtoSchema::parse($vendorText),
);

if ($diff !== []) {
    fwrite(STDERR, "error: vendored proto/engine.proto is out of sync with the engine.\n");
    fwrite(STDERR, "       engine:   {$source}\n");
    fwrite(STDERR, "       vendored: {$vendored}\n\n");
    fwrite(STDERR, "       schema differences (engine -> vendored):\n");
    foreach ($diff as $line) {
        fwrite(STDERR, "         {$line}\n");
    }
    fwrite(STDERR, "\n       fix: composer build-proto   (re-copies, regenerates stubs and the hash)\n");
    fwrite(STDERR, "       then update the fixture in tests/Unit/ProtoSchemaTest.php so the\n");
    fwrite(STDERR, "       change is visible in review, and check whether the SDK must read\n");
    fwrite(STDERR, "       any new or renamed field.\n");
    exit(1);
}



echo "ok: every declaration in the vendored proto matches the engine ({$source})\n";
echo "    the published copy omits the operator RPCs on purpose.\n";
