<#
.SYNOPSIS
    Registers (or removes / reports) the Windows task that runs the AIMS Laravel scheduler every minute.

.DESCRIPTION
    The task runs "php artisan schedule:run" once a minute from Windows Task Scheduler, so attendance sync,
    device probing, daily recalculation and automatic corrections keep running after PowerShell is closed and
    after Windows restarts. It runs as the current user because Python and pyzk are installed for this user.

    - Preferred mode (S4U): runs whether or not the user is logged on, with no window and no stored password.
      Windows may refuse it from a non-elevated shell; run this script "as Administrator" to get it.
    - Fallback mode (Interactive): runs while this user is logged on, hidden through a small launcher.
    - A run starts every minute like cron (the previous one may still be finishing its last 30-second probe);
      Laravel's withoutOverlapping locks prevent the same command from running twice, and each run is capped at
      10 minutes. Do not also keep "php artisan schedule:work" open in a terminal.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File attendance_bridge\windows\Install-AimsScheduler.ps1
    powershell -ExecutionPolicy Bypass -File attendance_bridge\windows\Install-AimsScheduler.ps1 -Status
    powershell -ExecutionPolicy Bypass -File attendance_bridge\windows\Install-AimsScheduler.ps1 -Uninstall
#>
param(
    [string]$ProjectPath = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path,
    [string]$PhpPath = '',
    [string]$TaskName = 'AIMS Laravel Scheduler',
    [switch]$Status,
    [switch]$Uninstall
)

$ErrorActionPreference = 'Stop'

function Show-Status {
    $task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    if (-not $task) { Write-Output "Task '$TaskName' is not installed."; return }
    $info = Get-ScheduledTaskInfo -TaskName $TaskName
    Write-Output ("Task: {0} | State: {1} | Logon: {2} | Last run: {3} | Last result: {4} | Next run: {5}" -f `
        $TaskName, $task.State, $task.Principal.LogonType, $info.LastRunTime, $info.LastTaskResult, $info.NextRunTime)
}

if ($Status) { Show-Status; return }

if ($Uninstall) {
    if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
        Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
        Write-Output "Removed task '$TaskName'."
    } else {
        Write-Output "Task '$TaskName' was not installed."
    }
    return
}

if (-not (Test-Path (Join-Path $ProjectPath 'artisan'))) { throw "artisan not found under $ProjectPath" }
if (-not $PhpPath) {
    # Resolve the real php.exe (Herd exposes a php.bat wrapper on PATH).
    $PhpPath = (& php -r 'echo PHP_BINARY;').Trim()
}
if (-not (Test-Path $PhpPath)) { throw "php.exe not found: $PhpPath" }

$user = [Security.Principal.WindowsIdentity]::GetCurrent().Name
$start = (Get-Date).Date.AddHours((Get-Date).Hour).AddMinutes((Get-Date).Minute + 1)
$trigger = New-ScheduledTaskTrigger -Once -At $start -RepetitionInterval (New-TimeSpan -Minutes 1)
# Parallel = cron semantics: a run that is finishing its last sub-minute probe must not make the next minute skip.
# Laravel's withoutOverlapping() mutexes stop the same command from running twice; the time limit stops pile-ups.
$settings = New-ScheduledTaskSettingsSet -MultipleInstances Parallel -ExecutionTimeLimit (New-TimeSpan -Minutes 10) `
    -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -Hidden
$description = 'AIMS: php artisan schedule:run every minute (attendance sync, probes, recalculation, corrections). Installed by attendance_bridge\windows\Install-AimsScheduler.ps1.'

if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
}

$mode = 'S4U'
try {
    $action = New-ScheduledTaskAction -Execute $PhpPath -Argument 'artisan schedule:run' -WorkingDirectory $ProjectPath
    $principal = New-ScheduledTaskPrincipal -UserId $user -LogonType S4U -RunLevel Limited
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Description $description | Out-Null
} catch {
    $mode = 'Interactive'
    # Hidden launcher: php.exe gets a hidden console that its child commands inherit, so no window flashes.
    $launcher = Join-Path $PSScriptRoot 'run-scheduler-hidden.vbs'
    $vbs = @"
' AIMS: runs "php artisan schedule:run" with a hidden window (used by the Interactive scheduler task).
Set shell = CreateObject("WScript.Shell")
shell.CurrentDirectory = "$ProjectPath"
shell.Run """$PhpPath"" artisan schedule:run", 0, True
"@
    Set-Content -Path $launcher -Value $vbs -Encoding ASCII
    $action = New-ScheduledTaskAction -Execute 'wscript.exe' -Argument "//B //Nologo `"$launcher`"" -WorkingDirectory $ProjectPath
    $principal = New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Limited
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Description $description | Out-Null
    Write-Warning "S4U registration was refused ($($_.Exception.Message.Trim())). Installed in Interactive mode: it runs while '$user' is logged on. Re-run this script as Administrator for the always-on mode."
}

Write-Output "Installed task '$TaskName' ($mode) for $user, every minute from $start, PHP: $PhpPath"
Show-Status
