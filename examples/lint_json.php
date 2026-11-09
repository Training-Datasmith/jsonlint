<?php

/**
 * Example: lint and parse JSON with detailed error reporting.
 *
 * Run from the jsonlint project root:
 *   php examples/lint_json.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Seld\Json_Lint\Duplicate_Key_Exception;
use Seld\Json_Lint\Json_Parser;
use Seld\Json_Lint\Parsing_Exception;

$parser = new Json_Parser();

$error = $parser->lint('{"name": "Alice", "age": 30}');
if ($error === null) {
    echo "Valid JSON\n";
} else {
    echo "Error: " . $error->getMessage() . "\n";
}

$error = $parser->lint('{"key": 1, "key": 2}', Json_Parser::DETECT_KEY_CONFLICTS);
if ($error !== null) {
    echo "Duplicate key error: " . $error->getMessage() . "\n";
}

$json = '{"name": "Bob" /* the builder */}';
try {
    $data = $parser->parse($json, Json_Parser::ALLOW_COMMENTS);
    echo "Parsed name: " . $data->name . "\n";
} catch (Parsing_Exception $e) {
    echo "Parse error: " . $e->getMessage() . "\n";
}

try {
    $parser->parse('{"missing": "comma" "second": "key"}');
} catch (Parsing_Exception $e) {
    $details = $e->get_details();
    $line = isset($details['line']) ? $details['line'] : '?';
    echo "Parse error at line " . $line . ": " . $e->getMessage() . "\n";
}
