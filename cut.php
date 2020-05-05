<?php
/*

php cut.php <cutfile>

<cutfile> enthält Zeilen vom Format

[p] <avifile>
[i][n][f][h] <von> <bis> [nots|nosound]

wobei <von> und <bis> timestamps im Format hh:mm:ss oder mm:ss sind
und
* [n]: normalmodus
* [i]: mit intro als overlay
* [f]: 4fach zeitbeschleunigung, ohne sound (darf *nicht* erster teil im zusammenschnitt sein!)
* [h]: halb so schnell

benötigt werden: do_ffmpeg.bat , do_ffmpeg_introed.bat,  do_ffmpeg_x4.bat,  do_ffmpeg_x0.5.bat, do_concat.bat

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

function stringToTime(string $timeString): DateTime {
    if (!preg_match('/^([0-9]{2}:)?([0-9]{2}):([0-9]{2})$/', $timeString)) {
        throw new UnexpectedValueException('time string must be of format nn:nn:nn or nn:nn, but was ' . json_encode($timeString));
    }
    $timeString = trim($timeString);

    if (strlen($timeString) === 5) {
        $timeString = "00:" . $timeString;
    }

    return new DateTime('0000-00-00 ' .$timeString);
}

if (!isset($argv[1])) {
    echo sprintf("usage: php %s %s", __FILE__, "<cutfile.txt>");
    exit(1);
}

$cutfile = $argv[1];

class ConcatPart {
	public $sourceFile;
	public $mode;

    /**
     * @var DateTime
     */
	public $from;

    /**
     * @var DateTime
    */
	public $to;

    /**
     * @var string nots|nosound
     */
    public $modifier;

	public function getFilename(): string {
		$outFilename = $this->sourceFilenameToOutfilename($this->sourceFile);
		return sprintf("%s-%s-%s.mkv",
			$outFilename,
			$this->dateTimeToFilenamePart($this->from),
			$this->dateTimeToFilenamePart($this->to)
		);
	}

	public function getOutFilename(): string  {
		return $this->sourceFilenameToOutfilename($this->sourceFile);
	}

	private function dateTimeToFilenamePart(DateTime $time): string {
	    return $time->format('His');
	}

	private  function sourceFilenameToOutfilename(string $sourceFilename) {
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
        $paddingPart->to = clone $this->part->from;
        $paddingPart->from = (clone $this->part->from)->sub($this->getInterval());
        $paddingPart->mode = 'f';
        $paddingPart->modifier = '';
        return $paddingPart;
    }
    public function getRightPaddingPart() {
        $paddingPart = clone $this->part;
        $paddingPart->from = clone $this->part->to;
        $paddingPart->to = (clone $this->part->to)->add($this->getInterval());
        $paddingPart->mode = 'f';
        $paddingPart->modifier = '';
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

        $lines = array_values(array_filter(array_trim(array_map(function ($line) {
            return explode('#', $line)[0];
        }, explode("\n", $cutfile)))));

        $parts = [];

        array_walk($lines, function ($line) use (&$parts) {

            list($mode, $a, $b, $c) = array_pad(array_values(array_filter(array_trim(explode(" ", $line)))), 4, '');

            if ($mode === 'p') {
                $this->do_p($a);
                return;
            }

            $methods = str_split($mode);

            array_walk($methods, function (string $method, int $index) use ($a, $b, $c, $methods, &$parts) {
                if (in_array($method, ['i', 'f', 'n', 'h'])) {
                    $numberOfParts = count($methods);
                    if ($method === 'f' && $numberOfParts > 1) {
                        $padding = new Padding($this->getConcatPart('n', $a, $b, $c));
                        if ($index === 0) {
                            $parts[] = $padding->getLeftPaddingPart();
                        } else if ($index === $numberOfParts - 1) {
                            $parts[] = $padding->getRightPaddingPart();
                        } else {
                            throw new RuntimeException("something's off" . json_encode([func_get_args(), $a, $b, $c, $methods]));
                        }

                    } else {
                        $parts[] = $this->getConcatPart($method, $a, $b, $c);
                    }
                    return;
                }
            });
        });
        $this->parts = array_values(array_filter($parts));
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

    public function getConcatPart($mode, $from, $to, $modifier) {
        $part = new ConcatPart();
        $part->sourceFile = $this->currentSourceFile;
        $part->mode = $mode;

        $part->from = stringToTime($from);
        $part->to = stringToTime($to);

        $part->modifier = $modifier;
        return $part;
    }

    public function do_p(string $file) {
        $this->currentSourceFile = trim($file);
    }
}

$parts = (new Parser($cutfile))->getConcatParts();

$modeMap = [
    'i' => 'do_ffmpeg_introed.bat',
    'i_nots' => 'do_ffmpeg_introed_nots.bat',
    'n' => 'do_ffmpeg.bat',
    'n_nots' => 'do_ffmpeg_nots.bat',
    'n_nosound' => 'do_ffmpeg_nosound.bat',
    'f' => 'do_ffmpeg_x4.bat',
	'h' => 'do_ffmpeg_x0.5.bat',
];

$sourceFiles = implode(', ', array_unique(array_map(function (ConcatPart $part) { return $part->sourceFile; }, $parts)));

$totalLength = array_sum(array_map(function (ConcatPart $part) {
    return $part->to->diff($part->from)->s;
}, $parts));
echo "total length: " . $totalLength . "s\n";
echo "details:\n";
echo implode("\n", array_map(function (ConcatPart $part) {
    return implode(" ", [
        $part->sourceFile,
        $part->from->format('H:i:s'),
        $part->to->format('H:i:s'),
        $part->modifier ?: '',
    ]);
}, $parts)) . "\n";
echo "sourcefiles: $sourceFiles; cutfile: $cutfile\n. ...\n";

$filestoconcat = array_map(function (ConcatPart $part) use ($modeMap) {
	$outFilename = $part->getOutFilename();
	$outConcatFilename = $part->getFilename();
	if (file_exists($outConcatFilename)) {
		echo "$outConcatFilename already exists, skipping...\n";
		return $outConcatFilename;
	}

    $mode = implode("_", array_filter([$part->mode, $part->modifier]));
	$exec = $modeMap[$mode];
	if (!$exec) {
        $msg = "[EE] unknown mode $mode ( {$part->mode} / {$part->modifier} )";
		echo $msg . PHP_EOL;
        throw new RuntimeException($msg);
        return;
	}

	$lengthString = $part->to->diff($part->from)->format('%H:%I:%s'); // NOTE: interval formatting != date formatting :/
    $offsetString = $part->from->format('H:i:s');

    $cmd = sprintf("%s %s %s %s", $exec, $part->sourceFile, $offsetString, $lengthString);
    $pipes = [];
    $descriptors = [
        ["file", "php://stdin", "r"],
        ["pipe", "w"],
        ["file", "cut.err.log", "w"],
    ];
	$proc = proc_open(
        $cmd,
        $descriptors,
        $pipes, // pipes
        null,
        null,
        ['bypass_shell' => true]
    );

    if (!is_resource($proc)) {
        throw new RuntimeException("couldnt start process for '$cmd'");
    }


    // stream_set_blocking($pipes[1], false);

    // TODO: have err/out as non-blocking streams and poll both
    // TODO: also, dont go via bash scripts, theyre stupid

    file_put_contents("cut.out.log", "");
    // fclose($pipes[0]);
    while (!feof($pipes[1])) {
        $out = fgets($pipes[1]);
        if ($out) {
            echo ".";
            file_put_contents("cut.out.log", $out."\n", FILE_APPEND);
        }
    }
    fclose($pipes[1]);

    $exit = proc_close($proc);
    if ($exit !== 0) {
        echo "$cmd exited with $exit . see cut.*.log for details";
    }

	rename($outFilename, $outConcatFilename);
	return $outConcatFilename;
}, $parts, array_keys($parts));

$concatFilename = "$cutfile.concatfile";

file_put_contents($concatFilename, implode("\r\n", array_map(function ($f) {
	return "file $f";
}, $filestoconcat)));

passthru("do_concat.bat $concatFilename");
