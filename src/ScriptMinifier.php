<?php

namespace Sanjeev\Custom;

class ScriptMinifier
{
    public function __construct(bool $errorReporting=false)
    {
        $this->opts['errorReporting']= $errorReporting;
    }
    protected mixed $input, $content = [], $locks = [];
    public mixed $output = "";
    protected mixed $opts = [
        "allowComments" => false,
        "inputIsFile" => false,
        "obfuscate" => false
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

    protected static function echo(mixed $args, bool $auto_exit = false): void
    {
        \Sanjeev\Custom\ErrorReporting::echo($args, $auto_exit);
    }
    public function addOpts(array|string $opt_or_key, $value = null): void
    {
        if (is_array($opt_or_key)) {
            $this->opts = array_merge($this->opts, $opt_or_key);
        } else {
            $this->opts[$opt_or_key] = $value;
        }
    }
    public function minify(string $source, mixed $opts = []): mixed
    {
        $this->init(opts: $opts, source: $source);
        $this->processComments();
        $this->lock();
        $this->process();
        $this->clean();
        $this->unlock();
        return $this->output;
    }

    protected function fileReader($fileSrc): mixed
    {
        try {
            return file_get_contents($fileSrc);
        } catch (\Throwable $th) {
            static::echo(args: "Failed to read Data Index", auto_exit: true);
        }
    }

    protected function init(string $source, ?array $opts = null): void
    {
        if ($opts !== null) {
            $this->opts = array_merge($this->opts, $opts);
        }
        if ($this->opts['errorReporting'] ?? false) {
            \Sanjeev\Custom\ErrorReporting::init();
        }
        if ($this->opts['inputIsFile'] ?? false) {
            $this->input = $this->fileReader($source);
        } else {
            if (strlen($source) > 0) {
                $this->input = $source;
            } else {
                static::echo(args: "Invalid Input Stream", auto_exit: true);
            }
        }
    }
    protected function process(): void
    {
        $this->input = str_replace(["\r\n", "\r"], ["\n", "\n"], $this->input) . "\n";
        $this->length = strlen($this->input);
        $this->content[0] = "\n";
        $this->content[1] = $this->getRealCharacter();
        while (($t = $this->content[0] ?? false) !== false && $t !== null && $t !== '') {
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
            $this->content[1] = $this->getRealCharacter();
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
        $this->content[1] = $this->getRealCharacter();
    }

    protected function lock(): mixed
    {
        $lock = '"LOCK---' . crc32(time()) . '"';
        $matches = [];
        preg_match('/([+-])(\s+)([+-])/s', $this->input, $matches);
        if (empty($matches)) {
            return $this->input;
        }
        $this->locks[$lock] = $matches[2];
        $this->input = preg_replace('/([+-])\s+([+-])/S', "$1{$lock}$2", $this->input);
        return $this->input;
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

    protected function clean(): void
    {
        unset($this->input);
        $this->length = 0;
        $this->inputPos = 0;
        unset($this->content);
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
    protected function processComments(): void
    {
        if ($this->opts['allowComments'] ?? false) {
            return;
        }
        /*
        Pattern taken from: https://stackoverflow.com/questions/30573253/remove-all-real-javascript-comments-in-php
        By: Casimir et Hippolyte [ https://stackoverflow.com/users/2255089/casimir-et-hippolyte ]
        */
        $pattern = <<<'EOD'
~
(?(DEFINE)
    (?<squoted> ' [^'\n\\]*+ (?: \\. [^'\n\\]* )*+ ' )
    (?<dquoted> " [^"\n\\]*+ (?: \\. [^"\n\\]* )*+ " )
    (?<tquoted> ` [^`\\]*+ (?s: \\. [^`\\]*)*+ ` )
    (?<quoted>  \g<squoted> | \g<dquoted> | \g<tquoted> )
    
    (?<scomment> // \N* )
    (?<mcomment> /\* [^*]*+ (?: \*+ (?!/) [^*]* )*+ \*/ )
    (?<comment> \g<scomment> | \g<mcomment> )
    
    (?<pattern> / [^\n/*] [^\n/\\]*+ (?>\\.[^\n/\\]*)* / [gimuy]* ) 
)

(?=[[(:,=/"'`])
(?|
    \g<quoted> (*SKIP)(*FAIL)
    |
    ( [[(:,=] \s* ) (*SKIP) (?: \g<comment> \s* )*+ ( \g<pattern> )
    | 
    ( \g<pattern> \s* ) (?: \g<comment> \s* )*+ 
    ( \. \s* ) (?:\g<comment> \s* )*+ ([A-Za-z_]\w*)
    |
    \g<comment>
)
~x
EOD;
        $this->input = preg_replace($pattern, '$9${10}${11}', $this->input);
    }
}