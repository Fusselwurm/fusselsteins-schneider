@set infile=%1
REM @set outfile=""
set outfile=%infile%.out.mkv

@set startAt=%2
if not defined startAt set startAt=00:00:00

@set duration=%3
if not defined duration set duration="02:00:00"

@set ffmpeg=C:\Program Files\ffmpeg-20190219-ff03418-win64-static\bin\ffmpeg.exe
@set sox=C:\Program Files (x86)\sox-14-4-2\sox.exe

"%ffmpeg%" ^
  -ss %startAt% ^
  -t %duration% ^
  -i %infile% ^
  -ar 22050 ^
  -map 0:1 "%outfile%_game.wav"

"%ffmpeg%" ^
  -ss %startAt% ^
  -t %duration% ^
  -i %infile% ^
  -ar 22050 ^
  -af "volume=0.7" ^
  -map 0:2 "%outfile%_ts.wav"

  "%ffmpeg%" ^
    -ss %startAt% ^
    -t %duration% ^
    -i %infile% ^
    -ar 22050 ^
    -af "volume=0.7" ^
    -map 0:3 "%outfile%_mike.wav"

"%sox%" -M "%outfile%_game.wav" "%outfile%_ts.wav" "%outfile%_mike.wav" "%outfile%.wav" remix -m 1,3,5 2,4,5

"%ffmpeg%" ^
    -ss %startAt% ^
	-t %duration% ^
	-i %infile% ^
	-i "%outfile%.wav" ^
	-map 0:0 ^
	-vcodec h264 ^
	-preset slow ^
	-crf 28 ^
	-map 1:0 ^
	-acodec libvorbis ^
	"%outfile%"


if %ERRORLEVEL% GEQ 1 EXIT /B 1

del "%outfile%.wav"
del "%outfile%_game.wav"
del "%outfile%_ts.wav"
del "%outfile%_mike.wav"

@REM 	-profile:v high ^
@REM	-vf scale=iw*0.9:ih*0.9 ^
@REM    -crf 18 is extrem viel daten, 25 is bei fullscreen ~ 12MBit/s , 28 => 3Mb/s
echo "done"
