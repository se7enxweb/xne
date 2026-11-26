# windows (skeleton, phase 4)

xne's engine is pure PHP 7.4 to 8.5 and needs no shell tool, so the same commands run on Windows. Phase 4 finishes
the Windows side for less technical users (owner Q10, F6):

| Part | Planned |
|---|---|
| `bin\xne.bat`, `bin\xne.ps1` | launchers (in place now, as stubs): run `bin\xne` with the PHP on PATH or `XNE_PHP` |
| `msi/` | a WiX-built MSI that installs xne.phar, the launchers and the scheduled task |
| `task/` | the daily check as a Windows scheduled task |
| guides | WAMP, XAMPP and IIS specifics; the Windows guide for non-technical users |

Paths on Windows: state in `%ProgramData%\xne\lib`, cache in `%ProgramData%\xne\cache`.
