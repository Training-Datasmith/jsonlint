<?php

declare (strict_types=1);
/*
 * This file is part of the JSON Lint package.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Seld\Json_Lint;

class Parsing_Exception extends \Exception
{
    /**
     * @var array{text?: string, token?: string|int, line?: int, loc?: array{first_line: int, first_column: int, last_line: int, last_column: int}, expected?: string[]}
     */
    protected $details;
    /**
     * @param string $message
     * @phpstan-param array{text?: string, token?: string|int, line?: int, loc?: array{first_line: int, first_column: int, last_line: int, last_column: int}, expected?: string[]} $details
     */
    public function __construct($message, $details = [])
    {
        $this->details = $details;
        parent::__construct($message);
    }
    /**
     * @phpstan-return array{text?: string, token?: string|int, line?: int, loc?: array{first_line: int, first_column: int, last_line: int, last_column: int}, expected?: string[]}
     */
    public function get_details()
    {
        return $this->details;
    }
}