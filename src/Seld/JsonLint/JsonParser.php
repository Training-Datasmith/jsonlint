<?php

declare(strict_types=1);

/*
 * This file is part of the JSON Lint package.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Seld\JsonLint;

use stdClass;

/**
 * Parser class
 *
 * Example:
 *
 * $parser = new JsonParser();
 * // returns null if it's valid json, or an error object
 * $parser->lint($json);
 * // returns parsed json, like json_decode does, but slower, throws exceptions on failure.
 * $parser->parse($json);
 *
 * Ported from https://github.com/zaach/jsonlint
 */
class JsonParser
{
    public const DETECT_KEY_CONFLICTS = 1;
    public const ALLOW_DUPLICATE_KEYS = 2;
    public const PARSE_TO_ASSOC = 4;
    public const ALLOW_COMMENTS = 8;
    public const ALLOW_DUPLICATE_KEYS_TO_ARRAY = 16;

    /** @var Lexer */
    private $lexer;

    /**
     * @var int
     * @phpstan-var int-mask-of<self::*>
     */
    private $flags;
    /** @var list<int> */
    private $stack;
    /** @var list<stdClass|array<mixed>|int|bool|float|string|null> */
    private $vstack; // semantic value stack
    /** @var list<array{first_line: int, first_column: int, last_line: int, last_column: int}> */
    private $lstack; // location stack

    /**
     * @phpstan-var array<string, int>
     */
    private $symbols = [
        'error'                 => 2,
        'JSONString'            => 3,
        'STRING'                => 4,
        'JSONNumber'            => 5,
        'NUMBER'                => 6,
        'JSONNullLiteral'       => 7,
        'NULL'                  => 8,
        'JSONBooleanLiteral'    => 9,
        'TRUE'                  => 10,
        'FALSE'                 => 11,
        'JSONText'              => 12,
        'JSONValue'             => 13,
        'EOF'                   => 14,
        'JSONObject'            => 15,
        'JSONArray'             => 16,
        '{'                     => 17,
        '}'                     => 18,
        'JSONMemberList'        => 19,
        'JSONMember'            => 20,
        ':'                     => 21,
        ','                     => 22,
        '['                     => 23,
        ']'                     => 24,
        'JSONElementList'       => 25,
        '$accept'               => 0,
        '$end'                  => 1,
    ];

    /**
     * @phpstan-var array<int, string>
     * @const
     */
    private $terminals_ = [
        2   => 'error',
        4   => 'STRING',
        6   => 'NUMBER',
        8   => 'NULL',
        10  => 'TRUE',
        11  => 'FALSE',
        14  => 'EOF',
        17  => '{',
        18  => '}',
        21  => ':',
        22  => ',',
        23  => '[',
        24  => ']',
    ];

    /**
     * @phpstan-var array<int<1,21>, array{int, int}>
     * @const
     */
    private $productions_ = [
        1 => [3, 1],
        2 => [5, 1],
        3 => [7, 1],
        4 => [9, 1],
        5 => [9, 1],
        6 => [12, 2],
        7 => [13, 1],
        8 => [13, 1],
        9 => [13, 1],
        10 => [13, 1],
        11 => [13, 1],
        12 => [13, 1],
        13 => [15, 2],
        14 => [15, 3],
        15 => [20, 3],
        16 => [19, 1],
        17 => [19, 3],
        18 => [16, 2],
        19 => [16, 3],
        20 => [25, 1],
        21 => [25, 3],
    ];

    /**
     * @var array<int<0, 31>, array<int, array<int>|int>> List of stateID=>symbolID=>actionIDs|actionID
     * @const
     */
    private $table = [
        0 => [ 3 => 5, 4 => [1,12], 5 => 6, 6 => [1,13], 7 => 3, 8 => [1,9], 9 => 4, 10 => [1,10], 11 => [1,11], 12 => 1, 13 => 2, 15 => 7, 16 => 8, 17 => [1,14], 23 => [1,15]],
        1 => [ 1 => [3]],
        2 => [ 14 => [1,16]],
        3 => [ 14 => [2,7], 18 => [2,7], 22 => [2,7], 24 => [2,7]],
        4 => [ 14 => [2,8], 18 => [2,8], 22 => [2,8], 24 => [2,8]],
        5 => [ 14 => [2,9], 18 => [2,9], 22 => [2,9], 24 => [2,9]],
        6 => [ 14 => [2,10], 18 => [2,10], 22 => [2,10], 24 => [2,10]],
        7 => [ 14 => [2,11], 18 => [2,11], 22 => [2,11], 24 => [2,11]],
        8 => [ 14 => [2,12], 18 => [2,12], 22 => [2,12], 24 => [2,12]],
        9 => [ 14 => [2,3], 18 => [2,3], 22 => [2,3], 24 => [2,3]],
        10 => [ 14 => [2,4], 18 => [2,4], 22 => [2,4], 24 => [2,4]],
        11 => [ 14 => [2,5], 18 => [2,5], 22 => [2,5], 24 => [2,5]],
        12 => [ 14 => [2,1], 18 => [2,1], 21 => [2,1], 22 => [2,1], 24 => [2,1]],
        13 => [ 14 => [2,2], 18 => [2,2], 22 => [2,2], 24 => [2,2]],
        14 => [ 3 => 20, 4 => [1,12], 18 => [1,17], 19 => 18, 20 => 19 ],
        15 => [ 3 => 5, 4 => [1,12], 5 => 6, 6 => [1,13], 7 => 3, 8 => [1,9], 9 => 4, 10 => [1,10], 11 => [1,11], 13 => 23, 15 => 7, 16 => 8, 17 => [1,14], 23 => [1,15], 24 => [1,21], 25 => 22 ],
        16 => [ 1 => [2,6]],
        17 => [ 14 => [2,13], 18 => [2,13], 22 => [2,13], 24 => [2,13]],
        18 => [ 18 => [1,24], 22 => [1,25]],
        19 => [ 18 => [2,16], 22 => [2,16]],
        20 => [ 21 => [1,26]],
        21 => [ 14 => [2,18], 18 => [2,18], 22 => [2,18], 24 => [2,18]],
        22 => [ 22 => [1,28], 24 => [1,27]],
        23 => [ 22 => [2,20], 24 => [2,20]],
        24 => [ 14 => [2,14], 18 => [2,14], 22 => [2,14], 24 => [2,14]],
        25 => [ 3 => 20, 4 => [1,12], 20 => 29 ],
        26 => [ 3 => 5, 4 => [1,12], 5 => 6, 6 => [1,13], 7 => 3, 8 => [1,9], 9 => 4, 10 => [1,10], 11 => [1,11], 13 => 30, 15 => 7, 16 => 8, 17 => [1,14], 23 => [1,15]],
        27 => [ 14 => [2,19], 18 => [2,19], 22 => [2,19], 24 => [2,19]],
        28 => [ 3 => 5, 4 => [1,12], 5 => 6, 6 => [1,13], 7 => 3, 8 => [1,9], 9 => 4, 10 => [1,10], 11 => [1,11], 13 => 31, 15 => 7, 16 => 8, 17 => [1,14], 23 => [1,15]],
        29 => [ 18 => [2,17], 22 => [2,17]],
        30 => [ 18 => [2,15], 22 => [2,15]],
        31 => [ 22 => [2,21], 24 => [2,21]],
    ];

    /**
     * @var array{16: array{2, 6}}
     * @const
     */
    private $defaultActions = [
        16 => [2, 6],
    ];

    /**
     * @param  string                $input JSON string
     * @param  int                   $flags Bitmask of parse/lint options (see constants of this class)
     * @return null|ParsingException null if no error is found, a ParsingException containing all details otherwise
     *
     * @phpstan-param int-mask-of<self::*> $flags
     */
    public function lint($input, $flags = 0)
    {
        try {
            $this->parse($input, $flags);
        } catch (ParsingException $e) {
            return $e;
        }
        return null;
    }

    /**
     * @param  string           $input JSON string
     * @param  int              $flags Bitmask of parse/lint options (see constants of this class)
     * @return mixed
     * @throws ParsingException
     *
     * @phpstan-param int-mask-of<self::*> $flags
     */
    public function parse($input, $flags = 0)
    {
        if (($flags & self::ALLOW_DUPLICATE_KEYS_TO_ARRAY) && ($flags & self::ALLOW_DUPLICATE_KEYS)) {
            throw new \InvalidArgumentException('Only one of ALLOW_DUPLICATE_KEYS and ALLOW_DUPLICATE_KEYS_TO_ARRAY can be used, you passed in both.');
        }

        $this->failOnBOM($input);

        $this->flags = $flags;

        $this->stack = [0];
        $this->vstack = [null];
        $this->lstack = [];

        $yytext = '';
        $yylineno = 0;
        $yyleng = 0;
        /** @var int<0,3> */
        $recovering = 0;

        $this->lexer = new Lexer($flags);
        $this->lexer->setInput($input);

        $yyloc = $this->lexer->yylloc;
        $this->lstack[] = $yyloc;

        $symbol = null;
        $preErrorSymbol = null;
        $action = null;
        $p = null;
        $len = null;
        $newState = null;
        $expected = null;
        /** @var string|null */
        $errStr = null;

        while (true) {
            // retrieve state number from top of stack
            $state = $this->stack[\count($this->stack) - 1];

            // use default actions if available
            if (isset($this->defaultActions[$state])) {
                $action = $this->defaultActions[$state];
            } else {
                if ($symbol === null) {
                    $symbol = $this->lexer->lex();
                }
                // read action for current state and first input
                /** @var array<int, int>|false */
                $action = isset($this->table[$state][$symbol]) ? $this->table[$state][$symbol] : false;
            }

            // handle parse error
            if (!$action || !$action[0]) {
                assert(isset($symbol));
                if (!$recovering) {
                    // Report error
                    $expected = [];
                    foreach ($this->table[$state] as $p => $ignore) {
                        if (isset($this->terminals_[$p]) && $p > 2) {
                            $expected[] = "'" . $this->terminals_[$p] . "'";
                        }
                    }

                    $message = null;
                    if (\in_array("'STRING'", $expected) && \in_array(substr($this->lexer->match, 0, 1), ['"', "'"])) {
                        $message = 'Invalid string';
                        if ("'" === substr($this->lexer->match, 0, 1)) {
                            $message .= ', it appears you used single quotes instead of double quotes';
                        } elseif (preg_match('{".+?(\\\\[^"bfnrt/\\\\u](...)?)}', $this->lexer->getFullUpcomingInput(), $match)) {
                            $message .= ', it appears you have an unescaped backslash at: '.$match[1];
                        } elseif (preg_match('{"(?:[^"]+|\\\\")*$}m', $this->lexer->getFullUpcomingInput())) {
                            $message .= ', it appears you forgot to terminate a string, or attempted to write a multiline string which is invalid';
                        }
                    }

                    $errStr = 'Parse error on line ' . ($yylineno + 1) . ":\n";
                    $errStr .= $this->lexer->showPosition() . "\n";
                    if ($message) {
                        $errStr .= $message;
                    } else {
                        $errStr .= (\count($expected) > 1) ? 'Expected one of: ' : 'Expected: ';
                        $errStr .= implode(', ', $expected);
                    }

                    if (',' === substr(trim($this->lexer->getPastInput()), -1)) {
                        $errStr .= ' - It appears you have an extra trailing comma';
                    }

                    $this->parseError($errStr, [
                        'text' => $this->lexer->match,
                        'token' => isset($this->terminals_[$symbol]) ? $this->terminals_[$symbol] : $symbol,
                        'line' => $this->lexer->yylineno,
                        'loc' => $yyloc,
                        'expected' => $expected,
                    ]);
                }

                // just recovered from another error
                if ($recovering == 3) {
                    if ($symbol === Lexer::EOF) {
                        throw new ParsingException($errStr ?: 'Parsing halted.');
                    }

                    // discard current lookahead and grab another
                    $yyleng = $this->lexer->yyleng;
                    $yytext = $this->lexer->yytext;
                    $yylineno = $this->lexer->yylineno;
                    $yyloc = $this->lexer->yylloc;
                    $symbol = $this->lexer->lex();
                }

                // try to recover from error
                while (true) {
                    // check for error recovery rule in this state
                    if (\array_key_exists(Lexer::T_ERROR, $this->table[$state])) {
                        break;
                    }
                    if ($state == 0) {
                        throw new ParsingException($errStr ?: 'Parsing halted.');
                    }
                    $this->popStack(1);
                    $state = $this->stack[\count($this->stack) - 1];
                }

                $preErrorSymbol = $symbol; // save the lookahead token
                $symbol = Lexer::T_ERROR;         // insert generic error symbol as new lookahead
                $state = $this->stack[\count($this->stack) - 1];
                /** @var array<int, int>|false */
                $action = isset($this->table[$state][Lexer::T_ERROR]) ? $this->table[$state][Lexer::T_ERROR] : false;
                if ($action === false) {
                    throw new \LogicException('No table value found for '.$state.' => '.Lexer::T_ERROR);
                }
                $recovering = 3; // allow 3 real symbols to be shifted before reporting a new error
            }

            // this shouldn't happen, unless resolve defaults are off
            if (\is_array($action[0]) && \count($action) > 1) {
                throw new ParsingException('Parse Error: multiple actions possible at state: ' . $state . ', token: ' . $symbol);
            }

            switch ($action[0]) {
                case 1: // shift
                    assert(isset($symbol));
                    $this->stack[] = $symbol;
                    $this->vstack[] = $this->lexer->yytext;
                    $this->lstack[] = $this->lexer->yylloc;
                    $this->stack[] = $action[1]; // push state
                    $symbol = null;
                    if (!$preErrorSymbol) { // normal execution/no error
                        $yyleng = $this->lexer->yyleng;
                        $yytext = $this->lexer->yytext;
                        $yylineno = $this->lexer->yylineno;
                        $yyloc = $this->lexer->yylloc;
                        if ($recovering > 0) {
                            $recovering--;
                        }
                    } else { // error just occurred, resume old lookahead from before error
                        $symbol = $preErrorSymbol;
                        $preErrorSymbol = null;
                    }
                    break;

                case 2: // reduce
                    $len = $this->productions_[$action[1]][1];

                    // perform semantic action
                    $currentToken = $this->vstack[\count($this->vstack) - $len]; // default to $$ = $1
                    // default location, uses first token for firsts, last for lasts
                    $position = [ // _$ = store
                        'first_line' => $this->lstack[\count($this->lstack) - ($len ?: 1)]['first_line'],
                        'last_line' => $this->lstack[\count($this->lstack) - 1]['last_line'],
                        'first_column' => $this->lstack[\count($this->lstack) - ($len ?: 1)]['first_column'],
                        'last_column' => $this->lstack[\count($this->lstack) - 1]['last_column'],
                    ];
                    list($newToken, $actionResult) = $this->performAction($currentToken, $yytext, $yylineno, $action[1]);

                    if (!$actionResult instanceof Undefined) {
                        return $actionResult;
                    }

                    if ($len) {
                        $this->popStack($len);
                    }

                    $this->stack[] = $this->productions_[$action[1]][0];    // push nonterminal (reduce)
                    $this->vstack[] = $newToken;
                    $this->lstack[] = $position;
                    /** @var int */
                    $newState = $this->table[$this->stack[\count($this->stack) - 2]][$this->stack[\count($this->stack) - 1]];
                    $this->stack[] = $newState;
                    break;

                case 3: // accept

                    return true;
            }
        }
    }

    /**
     * @param  string $str
     * @param  array{text: string, token: string|int, line: int, loc: array{first_line: int, first_column: int, last_line: int, last_column: int}, expected: string[]}|null $hash
     * @return never
     */
    protected function parseError($str, $hash = null)
    {
        throw new ParsingException($str, $hash ?: []);
    }

    /**
     * @param  stdClass|array<mixed>|int|bool|float|string|null $currentToken
     * @param  string   $yytext
     * @param  int      $yylineno
     * @param  int      $yystate
     * @return array{stdClass|array<mixed>|int|bool|float|string|null, stdClass|array<mixed>|int|bool|float|string|null|Undefined}
     */
    private function performAction($currentToken, $yytext, $yylineno, $yystate)
    {
        $token = $currentToken;

        $len = \count($this->vstack) - 1;
        switch ($yystate) {
            case 1:
                $yytext = preg_replace_callback('{(?:\\\\["bfnrt/\\\\]|\\\\u[a-fA-F0-9]{4})}', [$this, 'stringInterpolation'], $yytext);
                $token = $yytext;
                break;
            case 2:
                if (strpos($yytext, 'e') !== false || strpos($yytext, 'E') !== false) {
                    $token = \floatval($yytext);
                } else {
                    $token = strpos($yytext, '.') === false ? \intval($yytext) : \floatval($yytext);
                }
                break;
            case 3:
                $token = null;
                break;
            case 4:
                $token = true;
                break;
            case 5:
                $token = false;
                break;
            case 6:
                $token = $this->vstack[$len - 1];

                return [$token, $token];
            case 13:
                if ($this->flags & self::PARSE_TO_ASSOC) {
                    $token = [];
                } else {
                    $token = new stdClass();
                }
                break;
            case 14:
            case 19:
                $token = $this->vstack[$len - 1];
                break;
            case 15:
                $token = [$this->vstack[$len - 2], $this->vstack[$len]];
                break;
            case 16:
                assert(\is_array($this->vstack[$len]));
                if (PHP_VERSION_ID < 70100) {
                    $property = $this->vstack[$len][0] === '' ? '_empty_' : $this->vstack[$len][0];
                } else {
                    $property = $this->vstack[$len][0];
                }
                if ($this->flags & self::PARSE_TO_ASSOC) {
                    $token = [];
                    $token[$property] = $this->vstack[$len][1];
                } else {
                    $token = new stdClass();
                    $token->$property = $this->vstack[$len][1];
                }
                break;
            case 17:
                assert(\is_array($this->vstack[$len]));
                if ($this->flags & self::PARSE_TO_ASSOC) {
                    assert(\is_array($this->vstack[$len - 2]));
                    $token = & $this->vstack[$len - 2];
                    $key = $this->vstack[$len][0];
                    if (($this->flags & self::DETECT_KEY_CONFLICTS) && isset($this->vstack[$len - 2][$key])) {
                        $errStr = 'Parse error on line ' . ($yylineno + 1) . ":\n";
                        $errStr .= $this->lexer->showPosition() . "\n";
                        $errStr .= 'Duplicate key: '.$this->vstack[$len][0];
                        throw new DuplicateKeyException($errStr, $this->vstack[$len][0], ['line' => $yylineno + 1]);
                    }
                    if (($this->flags & self::ALLOW_DUPLICATE_KEYS) && isset($this->vstack[$len - 2][$key])) {
                        $duplicateCount = 1;
                        do {
                            $duplicateKey = $key . '.' . $duplicateCount++;
                        } while (isset($this->vstack[$len - 2][$duplicateKey]));
                        $this->vstack[$len - 2][$duplicateKey] = $this->vstack[$len][1];
                    } elseif (($this->flags & self::ALLOW_DUPLICATE_KEYS_TO_ARRAY) && isset($this->vstack[$len - 2][$key])) {
                        if (!isset($this->vstack[$len - 2][$key]['__duplicates__']) || !is_array($this->vstack[$len - 2][$key]['__duplicates__'])) {
                            $this->vstack[$len - 2][$key] = ['__duplicates__' => [$this->vstack[$len - 2][$key]]];
                        }
                        $this->vstack[$len - 2][$key]['__duplicates__'][] = $this->vstack[$len][1];
                    } else {
                        $this->vstack[$len - 2][$key] = $this->vstack[$len][1];
                    }
                } else {
                    assert($this->vstack[$len - 2] instanceof stdClass);
                    $token = $this->vstack[$len - 2];
                    if (PHP_VERSION_ID < 70100) {
                        $key = $this->vstack[$len][0] === '' ? '_empty_' : $this->vstack[$len][0];
                    } else {
                        $key = $this->vstack[$len][0];
                    }
                    if (($this->flags & self::DETECT_KEY_CONFLICTS) && isset($this->vstack[$len - 2]->$key)) {
                        $errStr = 'Parse error on line ' . ($yylineno + 1) . ":\n";
                        $errStr .= $this->lexer->showPosition() . "\n";
                        $errStr .= 'Duplicate key: '.$this->vstack[$len][0];
                        throw new DuplicateKeyException($errStr, $this->vstack[$len][0], ['line' => $yylineno + 1]);
                    }
                    if (($this->flags & self::ALLOW_DUPLICATE_KEYS) && isset($this->vstack[$len - 2]->$key)) {
                        $duplicateCount = 1;
                        do {
                            $duplicateKey = $key . '.' . $duplicateCount++;
                        } while (isset($this->vstack[$len - 2]->$duplicateKey));
                        $this->vstack[$len - 2]->$duplicateKey = $this->vstack[$len][1];
                    } elseif (($this->flags & self::ALLOW_DUPLICATE_KEYS_TO_ARRAY) && isset($this->vstack[$len - 2]->$key)) {
                        if (!isset($this->vstack[$len - 2]->$key->__duplicates__)) {
                            $this->vstack[$len - 2]->$key = (object) ['__duplicates__' => [$this->vstack[$len - 2]->$key]];
                        }
                        $this->vstack[$len - 2]->$key->__duplicates__[] = $this->vstack[$len][1];
                    } else {
                        $this->vstack[$len - 2]->$key = $this->vstack[$len][1];
                    }
                }
                break;
            case 18:
                $token = [];
                break;
            case 20:
                $token = [$this->vstack[$len]];
                break;
            case 21:
                assert(\is_array($this->vstack[$len - 2]));
                $this->vstack[$len - 2][] = $this->vstack[$len];
                $token = $this->vstack[$len - 2];
                break;
        }

        return [$token, new Undefined()];
    }

    /**
     * @param  string $match
     * @return string
     */
    private function stringInterpolation($match)
    {
        switch ($match[0]) {
            case '\\\\':
                return '\\';
            case '\"':
                return '"';
            case '\b':
                return \chr(8);
            case '\f':
                return \chr(12);
            case '\n':
                return "\n";
            case '\r':
                return "\r";
            case '\t':
                return "\t";
            case '\/':
                return '/';
            default:
                return html_entity_decode('&#x'.ltrim(substr($match[0], 2), '0').';', ENT_QUOTES, 'UTF-8');
        }
    }

    /**
     * @param  int $n
     * @return void
     */
    private function popStack($n)
    {
        $this->stack = \array_slice($this->stack, 0, - (2 * $n));
        $this->vstack = \array_slice($this->vstack, 0, - $n);
        $this->lstack = \array_slice($this->lstack, 0, - $n);
    }

    /**
     * @param  string $input
     * @return void
     */
    private function failOnBOM($input)
    {
        // UTF-8 ByteOrderMark sequence
        $bom = "\xEF\xBB\xBF";

        if (substr($input, 0, 3) === $bom) {
            $this->parseError('BOM detected, make sure your input does not include a Unicode Byte-Order-Mark');
        }
    }
}
