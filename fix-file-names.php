<?php
// fix-file-names.php

foreach (scandir(__DIR__) as $filename) {
	if (strpos($filename, ' ') !== false) {
		$saneFilename = str_replace(' ', '_', $filename);
		rename($filename, $saneFilename);
	}
}