<?php

namespace Sanjeev\Custom;

class ScriptMinifier
{
    private function __construct()
    {
    }
    protected mixed $input, $content = [], $locks = [], $output = "";
    protected mixed $opts = [
        "allowComments" => false,
        "inputIsFile" => false,
        "errorReporting" => false,
    ], $noNewLineCharacters = [
        '(' => true,
        '-' => true,
        '+' => true,
        '[' => true,
        '@' => true
    ], $strDelims = [
        '\'' => true,
        '"' => true,
        '`' => true
    ];
    protected ?int $length = 0, $inputPos = 0;
    protected static function echo(mixed $args, bool $auto_exit = false)
    {
        \Sanjeev\Custom\ErrorReporting::echo($args, $auto_exit);
    }
    public function addOpts(mixed $opts): void
    {
        $this->opts = array_merge($this->opts, $opts);
    }
    public function addOpt(mixed $kn, mixed $kv): void
    {
        $this->opts[$kn] = $kv;
    }
    public function minify(mixed $opts = [], string $fileSrc = "", string $inputStream = ""): mixed
    {
        $this->init(opts: $opts, fileSrc: $fileSrc, inputStream: $inputStream);
        $this->lock();
        $this->filterInput();
        $this->process();
        $this->clean();
        $this->unlock();
        return $this->output;
    }

    protected function fileReader($fileSrc): mixed
    {
        $proceed = false;
        if (file_exists($fileSrc)) {
            if ($fp = fopen($fileSrc, 'r')) {
                $proceed = true;
                fclose($fp);
            }
        }
        if ($proceed) {
            return file_get_contents($fileSrc);
        } else {
            static::echo(args: "Failed to read Data Index", auto_exit: true);
        }
    }

    protected function init(mixed $opts = [], string $fileSrc = "", string $inputStream = ""): void
    {
        $this->opts = array_merge($this->opts, $opts);
        if ($this->opts['errorReporting']) {
            \Sanjeev\Custom\ErrorReporting::init();
        }
        if ($this->opts['inputIsFile']) {
            $this->input = $this->fileReader($fileSrc);
        } else {
            if (strlen($inputStream) > 0) {
                $this->input = $inputStream;
            } else {
                static::echo(args: "Invalid Input Stream", auto_exit: true);
            }
        }
    }
    protected function filterInput()
    {
        $this->input = str_replace(["\r\n", '/**/', "\r"], ["\n", "", "\n"], $this->input);
        $this->input .= PHP_EOL;
        $this->length = strlen($this->input);
        $this->content[0] = "\n";
        $this->content[1] = $this->getReal();
    }

    protected function process(): void
    {
        while ($this->content[0] !== false && !is_null($this->content[0]) && $this->content[0] !== '') {
            switch ($this->content[0]) {
                case "\n":
                    if ($this->content[1] !== false && isset($this->noNewLineCharacters[$this->content[1]])) {
                        $this->output .= $this->content[0];
                        $this->saveString();
                        break;
                    }
                    if ($this->content[1] === ' ') {
                        break;
                    }
                case ' ':
                    if (static::isAlphaNumeric($this->content[1])) {
                        $this->output .= $this->content[0];
                    }
                    $this->saveString();
                    break;
                default:
                    switch ($this->content[1]) {
                        case "\n":
                            if (strpos('}])+-"\'', $this->content[0]) !== false) {
                                $this->output .=  $this->content[0];
                                $this->saveString();
                                break;
                            } else {
                                if (static::isAlphaNumeric($this->content[0])) {
                                    $this->output .=  $this->content[0];
                                    $this->saveString();
                                }
                            }
                            break;

                        case ' ':
                            if (!static::isAlphaNumeric($this->content[0])) {
                                break;
                            }
                        default:
                            if ($this->content[0] === '/' && ($this->content[1] === '\'' || $this->content[1] === '"')) {
                                $this->saveRegex();
                                continue 3;
                            }
                            $this->output .= $this->content[0];
                            $this->saveString();
                            break;
                    }
            }
            $this->content[1] = $this->getReal();
            if (($this->content[1] == '/' && strpos('(,=:[!&|?', $this->content[0]) !== false)) {
                $this->saveRegex();
            }
        }
    }
    protected function saveString(): void
    {
        $startPos = $this->inputPos;
        $this->content[0] = $this->content[1];
        if (!isset($this->stringDelimiters[$this->content[0]])) {
            return;
        }
        $stringType = $this->content[0];
        $this->output .= $this->content[0];
        while (($this->content[0] = $this->getCurrentChar()) !== false) {
            switch ($this->content[0]) {
                case $stringType:
                    break 2;
                case "\n":
                    if ($stringType === '`') {
                        $this->output .= $this->content[0];
                    } else {
                        static::echo(args: ['Unclosed string at position: ' . $startPos . '\n', 'Chararcted Caught\n', $this->input[$startPos]], auto_exit: true);
                    }
                    break;
                case '\\':
                    $this->content[1] = $this->getCurrentChar();
                    if ($this->content[1] === "\n") {
                        break;
                    }
                    $this->output .= $this->content[0] . $this->content[1];
                    break;
                default:
                    $this->output .= $this->content[0];
            }
        }
    }

    protected static function isAlphaNumeric($char): mixed
    {
        return preg_match('/^[\w\$\pL]$/', $char) === 1 || $char == '/';
    }

    protected function saveRegex()
    {
        $this->output .= $this->content[0] . $this->content[1];
        while (($this->content[0] = $this->getCurrentChar()) !== false) {
            if ($this->content[0] === '/') {
                break;
            }
            if ($this->content[0] === '\\') {
                $this->output .= $this->content[0];
                $this->content[0] = $this->getCurrentChar();
            }
            if ($this->content[0] === "\n") {
                static::echo(args: ['Unclosed regex pattern at position: ' . $this->inputPos . '\n', 'Chararcted Caught\n', $this->input[$this->inputPos]], auto_exit: true);
            }

            $this->output .= $this->content[0];
        }
        $this->content[1] = $this->getReal();
    }

    protected function lock(): mixed
    {
        $lock = '"LOCK---' . crc32(time()) . '"';
        $matches = [];
        preg_match('/([+-])(\s+)([+-])/S', $this->input, $matches);
        if (empty($matches)) {
            return $this->input;
        }

        $this->locks[$lock] = $matches[2];

        $this->input = preg_replace('/([+-])\s+([+-])/S', "$1{$lock}$2", $this->input);
        /* -- */

        return $this->input;
    }

    protected function getReal(): mixed
    {
        $index = $this->inputPos;
        $char = $this->getRealCharacter();
        if ($char !== '/') {
            return $char;
        }
        $this->content[2] = $this->getRealCharacter();
        if ($this->content[2] === '/') {
            $this->processComments(index: $index, delim: '/');
            return $this->getReal();
        } elseif ($this->content[2] === '*') {
            $this->processComments(index: $index, delim: '*');
            return $this->getReal();
        }

        return $char;
    }

    protected function getRealCharacter(): mixed
    {

        if (isset($this->content[2])) {
            $character = $this->content[2];
            unset($this->content[2]);
        } else {
            $character = $this->getInputPosChar();
            if (isset($character) && $character === false) {
                return false;
            }
            $this->inputPos++;
        }
        if ($character !== "\n" && $character < "\x20") {
            return ' ';
        }
        return $character;
    }

    protected function getCurrentChar(): mixed
    {
        if (isset($this->content[2])) {
            $character = $this->content[2];
            unset($this->content[2]);
        } else {
            $character = $this->getInputPosChar();
            if (isset($character) && $character === false) {
                return false;
            }
            $this->inputPos++;
        }
        if ($character !== "\n" && $character < "\x20") {
            return ' ';
        }
        return $character;
    }

    protected function getNextCharacter(string $str): mixed
    {
        $newPos = strpos($this->input, $str, $this->inputPos);
        if ($newPos === false) {
            return false;
        }
        $this->inputPos = $newPos;
        return $this->getInputPosChar();
    }

    protected function getInputPosChar(): mixed
    {
        return $this->inputPos < $this->length ? $this->input[$this->inputPos] : false;
    }

    protected function processComments(int $index, string $delim): void
    {
        if ($delim === '/') {
            $thirdCommentString = $this->getInputPosChar();
            $this->getNextCharacter(str: '\n');
            unset($this->content[2]);
            if ($thirdCommentString == '@') {
                $endPoint = $this->inputPos - $index;
                $this->content[2] = "\n" . substr($this->input, $index, $endPoint);
            }
        }
        if ($delim === '*') {
            $posChar = $this->getCurrentChar();
            $commentCharacter = $this->getCurrentChar();
            if ($this->getNextCharacter(str: '*/')) {
                $this->getCurrentChar();
                $this->getCurrentChar();
                $character = $this->getCurrentChar();
                if (($this->opts['allowComments'])) {
                    if ($index > 0) {
                        $this->content[0] = " ";
                        $this->output .= $this->content[0];
                        if ($this->input[($index - 1)] === "\n") {
                            $this->output .= "\n";
                        }
                    }
                    $endPoint = ($this->inputPos - 1) - $index;
                    $this->output .= substr($this->input, $index, $endPoint);
                    $this->content[2] = $character;
                }
            } else {
                static::echo(args: ['Unclosed multiline comment at position: \n' . ($this->inputPos - 2), 'Chararcted Caught\n', $this->input[$this->inputPos - 2]], auto_exit: true);
            }
            $this->content[2] = $character;
        }
    }
    protected function clean(): void
    {
        unset($this->input);
        $this->length = 0;
        $this->inputPos = 0;
        $this->content[0] = $this->content[1] = '';
        unset($this->content[2]);
        unset($this->opts);
    }

    protected function unlock(): mixed
    {
        if (empty($this->locks)) {
            return $this->output;
        }
        foreach ($this->locks as $lock => $replacement) {
            $this->output = str_replace($lock, $replacement, $this->output);
        }
        return $this->output;
    }
}
