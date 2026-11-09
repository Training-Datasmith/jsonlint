<?php

declare(strict_types=1);

/**
 * Fail if legacy upstream repo-owned identifiers remain after snake_case rename.
 */

$root = dirname(__DIR__);

$scanPaths = array(
    'bin',
    'bin/jsonlint',
    'examples',
    'src',
    'tests',
    'README.md',
    'ARCHITECTURE.md',
    'phpstan.neon.dist',
    'phpstan-baseline.neon',
    'phpunit.xml.dist',
);

$forbidden = array(
    'namespace Seld\\JsonLint',
    'use Seld\\JsonLint\\',
    'JsonParserTest',
    'class JsonParser',
    'new JsonParser',
    'JsonParser::',
    'DuplicateKeyException',
    'ParsingException',
    'JsonParser.php',
    'DuplicateKeyException.php',
    'ParsingException.php',
    'function getKey(',
    'function getDetails(',
    '->getKey()',
    '->getDetails()',
    'function setInput(',
    'function showPosition(',
    'function includeIfExists(',
    'function lintFile(',
    'function showUsage(',
    'provideValidStrings',
    'provideStringsWithComments',
);

$allowedContexts = array(
    'CHANGELOG.md',
);

$hits = array();

if (!run_rename_content_demo($forbidden)) {
    exit(1);
}

foreach ($scanPaths as $rel) {
    $path = $root . '/' . $rel;
    if (!file_exists($path)) {
        continue;
    }
    if (is_dir($path)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
            if (!$fileInfo->isFile()) {
                continue;
            }
            $relPath = substr($fileInfo->getPathname(), strlen($root) + 1);
            if (strpos($relPath, '.system-test/') === 0 || strpos($relPath, 'vendor/') === 0) {
                continue;
            }
            $name = $fileInfo->getFilename();
            $isBinTree = strpos($relPath, 'bin/') === 0;
            if (!$isBinTree && substr($name, -4) !== '.php' && substr($name, -3) !== '.md') {
                continue;
            }
            scan_file($fileInfo->getPathname(), $root, $forbidden, $allowedContexts, $hits);
        }
    } else {
        scan_file($path, $root, $forbidden, $allowedContexts, $hits);
    }
}

foreach (glob($root . '/*.md') ?: array() as $mdPath) {
    $relPath = substr($mdPath, strlen($root) + 1);
    if ($relPath === 'CHANGELOG.md') {
        continue;
    }
    if (!in_array($relPath, $scanPaths, true)) {
        scan_file($mdPath, $root, $forbidden, $allowedContexts, $hits);
    }
}

if ($hits !== array()) {
    fwrite(STDERR, "check-rename: legacy identifiers found:\n");
    foreach ($hits as $hit) {
        fwrite(STDERR, $hit . "\n");
    }
    exit(1);
}

$filesCounted = count(glob($root . '/src/Seld/Json_Lint/*.php'));
fwrite(STDOUT, sprintf("check-rename: OK (%d files).\n", $filesCounted));
exit(0);

/**
 * Prove README scanning rejects legacy ParsingException in content (in-memory only).
 *
 * @param list<string> $forbidden
 */
function run_rename_content_demo(array $forbidden): bool
{
    $legacy = "// returns null if it's valid json, or a ParsingException object.\n";
    $fixed = "// returns null if it's valid json, or a Parsing_Exception object.\n";

    $hits = array();
    collect_forbidden_hits($legacy, 'README.md', $forbidden, $hits);
    if ($hits === array()) {
        fwrite(STDERR, "check-rename: content demo did not fail on ParsingException in README.\n");
        return false;
    }

    $hits = array();
    collect_forbidden_hits($fixed, 'README.md', $forbidden, $hits);
    if ($hits !== array()) {
        fwrite(STDERR, "check-rename: content demo unexpectedly failed on Parsing_Exception in README.\n");
        return false;
    }

    fwrite(STDOUT, "check-rename: content demo OK (README legacy ParsingException rejected)\n");

    return true;
}

/**
 * @param list<string> $forbidden
 * @param list<string> $hits
 */
function collect_forbidden_hits(string $content, string $rel, array $forbidden, array &$hits): void
{
    foreach ($forbidden as $needle) {
        if (strpos($content, $needle) !== false) {
            $hits[] = $rel . ': contains ' . $needle;
        }
    }
}

/**
 * @param list<string> $forbidden
 * @param list<string> $allowedContexts
 * @param list<string> $hits
 */
function scan_file(string $path, string $root, array $forbidden, array $allowedContexts, array &$hits): void
{
    $rel = substr($path, strlen($root) + 1);
    foreach ($allowedContexts as $allowed) {
        if (strpos($rel, $allowed) !== false) {
            return;
        }
    }
    $content = file_get_contents($path);
    if ($content === false) {
        return;
    }
    collect_forbidden_hits($content, $rel, $forbidden, $hits);
}
