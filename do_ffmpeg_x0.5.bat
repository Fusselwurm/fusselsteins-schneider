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
  -af "volume=0.3" ^
  -map 0:2 "%outfile%_ts.wav"

"%sox%" -M "%outfile%_game.wav" "%outfile%_ts.wav" "%outfile%.wav" remix -m 1,3 2,3

"%ffmpeg%" ^
	-ss %startAt% ^
	-t %duration% ^
	-i %infile% ^
	-i "%outfile%.wav" ^
	-map 0:0 ^
	-vcodec h264 ^
	-filter:v "setpts=2*PTS,hue=s=1.2" ^
	-filter:a "atempo=0.5" ^
	-preset slow ^
	-crf 28 ^
	-map 1:0 ^
	-acodec libvorbis ^
	"%outfile%"

if %ERRORLEVEL% GEQ 1 EXIT /B 1
	
echo "done"