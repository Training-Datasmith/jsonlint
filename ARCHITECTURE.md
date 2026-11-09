# Architecture: jsonlint

## Purpose
A JSON linter and parser. Parses JSON strictly (detecting duplicate keys, trailing commas, comments, and other common errors) and throws informative exceptions with line/column information.

## Directory structure
```
src/Seld/Json_Lint/
  Json_Parser.php              # Main class — lint() and parse()
  Lexer.php                    # Tokenizer
  Parsing_Exception.php        # Exception with details (line, column, excerpt)
  Duplicate_Key_Exception.php  # Duplicate object keys
  Undefined.php                # Sentinel for the LALR reduction table
tests/
  Json_Parser_Test.php
  bootstrap.php
```

## Design notes
- Port of zaach/jsonlint — LALR(1) parse table with a PHP lexer.
- Flags: `DETECT_KEY_CONFLICTS`, `ALLOW_DUPLICATE_KEYS`, `ALLOW_DUPLICATE_KEYS_TO_ARRAY`, `ALLOW_COMMENTS`, `PARSE_TO_ASSOC`.
- `lint()` returns `null` or a `Parsing_Exception`; `parse()` throws on error.

## Dependency flow
```
Json_Parser::lint($json) / parse($json, $flags)
  └─ Lexer::lex() → token stream
       └─ LALR table embedded in Json_Parser
```
