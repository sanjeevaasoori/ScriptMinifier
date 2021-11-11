# ScriptMinifier
PHP library to minify Javascripts
Simple Usage
use namespace Sanjeev\Custom\ScriptMinifier

$min = new ScriptMinifier();
//$stream = javascript contents
//minify(mixed $opts = [], string $fileSrc = "", string $inputStream = "")
//$opts = [
        "allowComments" => false,
        "inputIsFile" => false,
        "errorReporting" => false,
    ]
echo $min->minify(inputStream:$stream,opts:$opts);

OR

echo $min->minify(fileSrc:source of file , opts:$opts);
