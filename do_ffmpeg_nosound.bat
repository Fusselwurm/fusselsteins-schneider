@set infile=%1
set outfile=%infile%.out.mkv

@set startAt=%2
if not defined startAt set startAt=00:00:00

@set duration=%3
if not defined duration set duration="02:00:00"

@set ffmpeg=C:\Program Files\ffmpeg\bin\ffmpeg.exe
"%ffmpeg%" ^
    -ss %startAt% ^
	-t %duration% ^
	-i %infile% ^
	-map 0:0 ^
	-vcodec h264 ^
	-preset slow ^
	-crf 28 ^
	"%outfile%"
	
if %ERRORLEVEL% GEQ 1 EXIT /B 1

echo "done"