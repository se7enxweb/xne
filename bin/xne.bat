@echo off
rem xne launcher for Windows (phase 4 stub): runs bin\xne with the PHP on PATH, or with XNE_PHP.
rem The engine is pure PHP, so the commands and their results are the same as on Linux.
setlocal
if "%XNE_PHP%"=="" set XNE_PHP=php
"%XNE_PHP%" "%~dp0xne" %*
exit /b %ERRORLEVEL%
