<?php

declare(strict_types=1);

/**
 * Strict PHPUnit output gate: baseline counts, allowlisted skips only, no warnings.
 *
 * Usage: php scripts/check-phpunit-gate.php <output-file>
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/check-phpunit-gate.php <phpunit-output-file>\n");
    exit(2);
}

$outputFile = $argv[1];
if (!is_readable($outputFile)) {
    fwrite(STDERR, "Cannot read {$outputFile}\n");
    exit(2);
}

$text = file_get_contents($outputFile);
if ($text === false) {
    fwrite(STDERR, "Failed to read {$outputFile}\n");
    exit(2);
}

$baselinePath = dirname(__DIR__) . '/scripts/phpunit-baseline.json';
$baseline = json_decode((string) file_get_contents($baselinePath), true);
if (!is_array($baseline)) {
    fwrite(STDERR, "Invalid baseline file\n");
    exit(2);
}

$allowlistPath = dirname(__DIR__) . '/scripts/allowed-skipped-tests.txt';
$allowedSkips = array();
foreach (file($allowlistPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    $allowedSkips[$line] = true;
}

$failures = array();

foreach (preg_split('/\R/', $text) as $line) {
    if ($line === '') {
        continue;
    }
    if (preg_match('/^(PHP )?(Warning|Deprecated|Notice):/', $line)) {
        $failures[] = "Disallowed line: {$line}";
    }
}

if (preg_match('/^FAILURES!$/m', $text)) {
    $failures[] = 'Output contains FAILURES!';
}
if (preg_match('/^ERRORS!$/m', $text)) {
    $failures[] = 'Output contains ERRORS!';
}
if (preg_match('/^WARNINGS!$/m', $text)) {
    $failures[] = 'Output contains WARNINGS!';
}

if (!preg_match('/^OK, but incomplete, skipped, or risky tests!$/m', $text)) {
    $failures[] = 'Missing success banner: OK, but incomplete, skipped, or risky tests!';
}

$skipped = 0;
if (!preg_match('/^Tests: (\d+), Assertions: (\d+)(?:, Skipped: (\d+))?\.?$/m', $text, $m)) {
    $failures[] = 'Missing Tests: N, Assertions: M summary line';
} else {
    $tests = (int) $m[1];
    $assertions = (int) $m[2];
    $skipped = isset($m[3]) ? (int) $m[3] : 0;

    $minTests = (int) $baseline['tests'];
    if ($tests < $minTests) {
        $failures[] = sprintf('Test count %d below baseline minimum %d', $tests, $minTests);
    }

    $expectedAssertions = (int) $baseline['assertions'];
    if ($expectedAssertions > 0 && $assertions !== $expectedAssertions) {
        $failures[] = sprintf('Assertion count %d does not match baseline %d', $assertions, $expectedAssertions);
    }

    if ($skipped !== count($allowedSkips)) {
        $failures[] = sprintf('Skipped count %d != allowlist count %d', $skipped, count($allowedSkips));
    }

    if (preg_match_all('/^There (?:was|were) \d+ skipped tests?:$/m', $text, $skipHeaders)) {
        foreach ($skipHeaders[0] as $header) {
            // ok
        }
    }

    if (preg_match('/There were (\d+) skipped tests:/', $text, $skipHeader)) {
        $listed = 0;
        if (preg_match_all('/^\d+\) ([A-Za-z0-9_\\\\]+::[A-Za-z0-9_]+)/m', $text, $listedMatches)) {
            foreach ($listedMatches[1] as $id) {
                $listed++;
                if (!isset($allowedSkips[$id])) {
                    $failures[] = 'Unexpected skipped test (not on allowlist): ' . $id;
                }
            }
        }
        if ($listed !== $skipped) {
            $failures[] = sprintf('Listed skipped methods %d != summary Skipped %d', $listed, $skipped);
        }
    } elseif ($skipped > 0) {
        $failures[] = 'Skipped tests reported but no skip list header found';
    }
}

if ($failures !== array()) {
    fwrite(STDERR, "phpunit-gate failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "phpunit-gate: OK\n");
exit(0);
