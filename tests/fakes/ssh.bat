@echo off
rem test stand-in: runs the PHP script of the same name (Windows cannot start a script by its shebang)
"%XNE_TEST_PHP%" "%~dp0ssh" %*
exit /b %ERRORLEVEL%
