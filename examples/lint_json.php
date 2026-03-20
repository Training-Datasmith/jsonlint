<?php

declare(strict_types=1);

/**
 * Example: lint and parse JSON with detailed error reporting.
 *
 * Run from the jsonlint project root:
 *   php examples/lint_json.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Seld\JsonLint\JsonParser;
use Seld\JsonLint\ParsingException;

$parser = new JsonParser();

// --- Lint only (no exception on error) ---
$error = $parser->lint('{"name": "Alice", "age": 30}');
if ($error === null) {
    echo "Valid JSON\n";
} else {
    echo "Error: " . $error->getMessage() . "\n";
}

// --- Detect duplicate keys ---
$error = $parser->lint('{"key": 1, "key": 2}', JsonParser::DETECT_KEY_CONFLICTS);
if ($error !== null) {
    echo "Duplicate key error: " . $error->getMessage() . "\n";
}

// --- Parse with comments allowed ---
$json = '{"name": "Bob" /* the builder */}';
try {
    $data = $parser->parse($json, JsonParser::ALLOW_COMMENTS);
    echo "Parsed name: " . $data->name . "\n";
} catch (ParsingException $e) {
    echo "Parse error: " . $e->getMessage() . "\n";
}

// --- Parse invalid JSON with informative error ---
try {
    $parser->parse('{"missing": "comma" "second": "key"}');
} catch (ParsingException $e) {
    echo "Parse error at line " . $e->getDetails()['line'] . ": " . $e->getMessage() . "\n";
}
