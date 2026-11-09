<?php

declare(strict_types=1);

/**
 * Fail if legacy upstream repo-owned identifiers remain after snake_case rename.
 */

$root = dirname(__DIR__);

$scanPaths = array(
    'bin',
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
    'README.md', // historical prose mentioning upstream API names
);

$hits = array();

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
            if (substr($fileInfo->getFilename(), -4) !== '.php' && substr($fileInfo->getFilename(), -3) !== '.md') {
                continue;
            }
            scan_file($fileInfo->getPathname(), $root, $forbidden, $allowedContexts, $hits);
        }
    } else {
        scan_file($path, $root, $forbidden, $allowedContexts, $hits);
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
    foreach ($forbidden as $needle) {
        if (strpos($content, $needle) !== false) {
            $hits[] = $rel . ': contains ' . $needle;
        }
    }
}
