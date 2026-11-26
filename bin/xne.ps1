# xne launcher for PowerShell (Windows PowerShell 5.1 and PowerShell 7): runs bin\xne with $env:XNE_PHP, else the
# php on PATH, passes every argument through unchanged and returns xne's exit code. The engine is pure PHP, so the
# commands and their results are the same as on Linux.
#
# The command line is built here with the quoting rules of the Windows C runtime (an argument with spaces or quotes
# is quoted, backslashes before a quote doubled), because Windows PowerShell 5.1 does not quote native arguments
# reliably: "--root=C:\my site" arrived at PHP as two arguments.
$php = if ($env:XNE_PHP) { $env:XNE_PHP } else { 'php' }
$cmd = Get-Command $php -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
if (-not $cmd) {
    [Console]::Error.WriteLine("xne: PHP not found ($php); set XNE_PHP to the full path of php.exe")
    exit 2
}

function ConvertTo-XneArgument([string] $s) {
    if ($s -eq '') { return '""' }
    if ($s -notmatch '[\s"]') { return $s }
    $out = '"'
    $slashes = 0
    foreach ($c in $s.ToCharArray()) {
        if ($c -eq [char] '\') { $slashes++; continue }
        if ($c -eq [char] '"') { $out += ('\' * ($slashes * 2 + 1)) + '"'; $slashes = 0; continue }
        $out += ('\' * $slashes) + $c
        $slashes = 0
    }
    return $out + ('\' * ($slashes * 2)) + '"'
}

$line = (@((Join-Path $PSScriptRoot 'xne')) + @($args) | ForEach-Object { ConvertTo-XneArgument ([string] $_) }) -join ' '
$psi = New-Object System.Diagnostics.ProcessStartInfo
$psi.FileName = $cmd.Path
$psi.Arguments = $line
$psi.UseShellExecute = $false
$p = [System.Diagnostics.Process]::Start($psi)
$p.WaitForExit()
exit $p.ExitCode
