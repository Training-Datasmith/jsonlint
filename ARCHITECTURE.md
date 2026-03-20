# Architecture: jsonlint

## Purpose
A JSON linter and parser. Parses JSON strictly (detecting duplicate keys, trailing commas, comments, and other common errors) and throws informative exceptions with line/column information.

## Directory Structure
```
src/Seld/JsonLint/
  Json_Parser.php          # Main class — lint() and parse() methods
  Lexer.php                # Tokenizer: converts JSON text to a token stream
  Parsing_Exception.php    # Exception with details (line, column, excerpt)
  Duplicate_Key_Exception.php  # Thrown when duplicate object keys detected
  Undefined.php            # Sentinel value representing undefined/absent
tests/
  Json_Parser_Test.php
  bootstrap.php
```

## Key Design Decisions
- **Port of zaach/jsonlint** — ported from the JavaScript reference implementation; shares the same LALR(1) parse table structure with a PHP-specific lexer.
- **Flags for strictness** — `DETECT_KEY_CONFLICTS`, `ALLOW_DUPLICATE_KEYS`, `ALLOW_DUPLICATE_KEYS_TO_ARRAY`, `ALLOW_COMMENTS`, and `PARSE_TO_ASSOC` are bitmask constants that control parser behaviour.
- **`lint()` vs `parse()`** — `lint()` returns `null` on valid JSON or a `Parsing_Exception` on error (no throw); `parse()` throws on error and returns the decoded value on success, like a strict `json_decode()`.
- **Line/column errors** — `Parsing_Exception` includes `getDetails()` with the line, column, and a text excerpt of the problematic location.

## Extension Points
- Use `ALLOW_COMMENTS` flag to accept JSON5-style `//` and `/* */` comments.
- Use `PARSE_TO_ASSOC` to return associative arrays instead of `stdClass` objects.
- Use `ALLOW_DUPLICATE_KEYS_TO_ARRAY` to collect duplicate keys into arrays rather than erroring.

## Dependency Flow
```
Json_Parser::lint($json) / parse($json, $flags)
  └─ Lexer::lex($json) → token stream
       └─ LALR(1) parse table (embedded in Json_Parser)
            └─ builds PHP value tree or throws Parsing_Exception
```
