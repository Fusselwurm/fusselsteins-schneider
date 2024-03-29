@set infile=%1
set outfile=%infile%.out.mkv

@set startAt=%2
if not defined startAt set startAt=00:00:00

@set duration=%3
if not defined duration set duration="02:00:00"

@set ffmpeg=C:\Program Files\ffmpeg\bin\ffmpeg.exe
@set sox=C:\Program Files (x86)\sox-14-4-1\sox.exe

"%ffmpeg%" ^
  -ss %startAt% ^
  -t %duration% ^
  -i %infile% ^
  -ar 22050 ^
  -map 0:1 "%outfile%_game.wav"

"%sox%" -M "%outfile%_game.wav" "%outfile%_game.wav" "%outfile%.wav" remix -m 1,3 2,4

"%ffmpeg%" ^
    -ss %startAt% ^
	-t %duration% ^
	-i %infile% ^
	-i "%outfile%_game.wav" ^
	-map 0:0 ^
	-vcodec h264 ^
	-preset slow ^
	-crf 18 ^
	-map 1:0 ^
	-acodec libvorbis ^
	"%outfile%"
	
if %ERRORLEVEL% GEQ 1 EXIT /B 1

del "%outfile%.wav"
del "%outfile%_game.wav"
del "%outfile%_ts.wav"

@REM 	-profile:v high ^
@REM	-vf scale=iw*0.9:ih*0.9 ^
@REM    -crf 18 is extrem viel daten, 25 is bei fullscreen ~ 12MBit/s , 28 => 3Mb/s
echo "done"
