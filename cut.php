<?php
/*

php cut.php <cutfile>

<cutfile> enthält Zeilen vom Format 

[p] <avifile>
[i|n|f] [von] [bis]

wobei [von] und [bis] timestamps im Format hh:mm:ss oder mm:ss sind
und 
* [n]: normalmodus
* [i]: mit intro als overlay
* [f]: 4fach zeitbeschleunigung 

benötigt werden: do_ffmpeg.bat , do_ffmpeg_introed.bat,  do_ffmpeg_x4.bat, do_concat.bat

*/

const BUFFER_LENGTH = 1;

date_default_timezone_set('UTC') ;

error_reporting(E_ALL);
set_error_handler(function ($errno, $errstr, $errfile, $errline ) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

function fill_object($obj, $arr, $keys) {
	foreach ($keys as $idx => $key) {
		$obj->$key = $arr[$idx];
	}
}

function array_trim($array) {
	return array_map(function ($bit) { return trim($bit); }, $array);
}

if (!isset($argv[1])) {
    echo sprintf("usage: php %s %s", __FILE__, "<cutfile.txt>");
    exit(1);
}

$cutfile = $argv[1];

class ConcatPart {
	public $sourceFile;
	public $mode;
	public $from; 
	public $to;
	
	public function getFilename(): string {
		$outFilename = $this->sourceFilenameToOutfilename($this->sourceFile);
		return sprintf("%s-%s-%s.mkv", 
			$outFilename, 
			$this->sanitizeForFilename($this->from), 
			$this->sanitizeForFilename($this->to)
		);
	}
	
	public function getOutFilename(): string  {
		return $this->sourceFilenameToOutfilename($this->sourceFile);
	}
	
	private function sanitizeForFilename($string): string {
		return str_replace(':', '', $string);
	}
	
	private  function sourceFilenameToOutfilename($sourceFilename) {
		return $sourceFilename . ".out.mkv";
	}
}

class Padding {
    /**
     * @var ConcatPart
     */
    private $part;

    public function __construct(ConcatPart $part) {
        $this->part = $part;
    }
    public function getLeftPaddingPart() {
        $paddingPart = clone $this->part;
        $paddingPart->to = $this->part->from;
        $paddingPart->from = (new DateTime($this->part->from))->sub($this->getInterval())->format('H:i:s');
        $paddingPart->mode = 'f';
        return $paddingPart;
    }
    public function getRightPaddingPart() {
        $paddingPart = clone $this->part;
        $paddingPart->from = $this->part->to;
        $paddingPart->to = (new DateTime($this->part->to))->add($this->getInterval())->format('H:i:s');
        $paddingPart->mode = 'f';
        return $paddingPart;
    }

    private function getInterval() {
        return new DateInterval('PT' . BUFFER_LENGTH . 'S');
    }
}

class Parser {

    /**
     * @var
     */
    private $currentSourceFile;

    /**
     * @var string
     */
    private $cutfile;

    /**
     * @var ConcatPart[]
     */
    private $parts;

    public function __construct(string $cutfile)
    {
        $this->cutfile = $cutfile;
    }

    private function doParse() {

        $cutfile = file_get_contents($this->cutfile);
        if (!$cutfile) {
            echo "cutfile not found or empty\n";
            exit;
        }

        $lines = array_filter(array_trim(array_map(function ($line) {
            return explode('#', $line)[0];
        }, explode("\n", $cutfile))));

        $parts = [];

        array_walk($lines, function ($part) use (&$parts) {

            $lineBits = explode(" ", trim($part), 2);

            $methodName = 'do_' . $lineBits[0];
            if (!method_exists(Parser::class, $methodName)) {
                echo "WARN: unknown line: $part\n";
                return null;
            }

            $newPart = $this->$methodName($lineBits[1]);
            if (!is_array($newPart)) {
                $newPart = [$newPart];
            }
            $parts = array_merge($parts, $newPart);
        });
        $this->parts = array_filter($parts);
    }

    /**
     * @return ConcatPart[]
     */
    public function getConcatParts(): array {
        $this->doParse();
        return $this->parts;
    }

    public function getCurrentSourceFile(): string
    {
        return $this->currentSourceFile;
    }

    public function getConcatPart($mode, $line) {

        $lineBits = explode(" ", $line);
        $lineBits = array_filter(array_map('trim', $lineBits));

        $part = new ConcatPart();
        $part->sourceFile = $this->currentSourceFile;
        $part->mode = $mode;
        echo "DEBUG: " . json_encode($lineBits) . "\n";
        fill_object($part, $lineBits, ['from', 'to']);

        if (strlen($part->from) === 5) { $part->from = "00:" . $part->from; }
        if (strlen($part->to) === 5) { $part->to = "00:" . $part->to; }
        if (!preg_match('/^[0-9]{2}:[0-9]{2}:[0-9]{2}$/', $part->from)) { throw new RuntimeException('invalid "from" input '); }
        if (!preg_match('/^[0-9]{2}:[0-9]{2}:[0-9]{2}$/', $part->to)) { throw new RuntimeException('invalid "to" input '); }

        return $part;
    }
    public function do_i($line) {
        return $this->getConcatPart('i', $line);
    }
    public function do_n($line) {
        return $this->getConcatPart('n', $line);
    }
    public function do_f($line) {
        return $this->getConcatPart('f', $line);
    }
    public function do_p($line) {
        $this->currentSourceFile = trim($line);
    }
    public function do_if($line) {
        $middlePart = $this->do_i($line);
        $padding = new Padding($middlePart);
        return [
            $middlePart,
            $padding->getRightPaddingPart(),
        ];
    }
    public function do_fnf($line) {
        $middlePart = $this->do_n($line);
        $padding = new Padding($middlePart);
        return [
            $padding->getLeftPaddingPart(),
            $middlePart,
            $padding->getRightPaddingPart(),
        ];
    }
    public function do_nf($line) {
        $middlePart = $this->do_n($line);
        $padding = new Padding($middlePart);
        return [
            $middlePart,
            $padding->getRightPaddingPart(),
        ];
    }
    public function do_fn($line) {
        $middlePart = $this->do_n($line);
        $padding = new Padding($middlePart);
        return [
            $padding->getLeftPaddingPart(),
            $middlePart,
        ];
    }
}

$parts = (new Parser($cutfile))->getConcatParts();

$modeMap = [
    'i' => 'do_ffmpeg_introed.bat',
    'n' => 'do_ffmpeg.bat',
    'f' => 'do_ffmpeg_x4.bat',
];

$sourceFiles = implode(', ', array_unique(array_map(function (ConcatPart $part) { return $part->sourceFile; }, $parts)));

echo "sourcefiles: $sourceFiles; cutfile: $cutfile\n. starting in 5s...\n";
sleep(5);

$filestoconcat = array_map(function (ConcatPart $part) use ($modeMap) {
	$outFilename = $part->getOutFilename();
	$outConcatFilename = $part->getFilename();
	if (file_exists($outConcatFilename)) {
		echo "$outConcatFilename already exists, skipping...\n";
		return $outConcatFilename;
	}

	$exec = $modeMap[$part->mode];
	if (!$exec) {	
		echo "unknown mode {$part->mode}\n";
	}

	$datetime1 = new DateTime($part->from);
	$datetime2 = new DateTime($part->to);
	$interval = $datetime2->diff($datetime1);
	
	passthru(sprintf("%s %s %s %s", $exec, $part->sourceFile, $part->from, $interval->format('%H:%I:%s')));
	
	rename($outFilename, $outConcatFilename);
	return $outConcatFilename;
}, $parts, array_keys($parts));

$concatFilename = "$cutfile.concatfile";

file_put_contents($concatFilename, implode("\r\n", array_map(function ($f) {
	return "file $f";
}, $filestoconcat)));

passthru("do_concat.bat $concatFilename");
