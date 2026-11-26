$ErrorActionPreference = 'Stop'
$root = 'C:\Projects\al-amal-system'
if (!(Test-Path (Join-Path $root 'artisan'))) { throw "Laravel project not found: $root" }
Set-Location $root
$package = $PSScriptRoot
$migrationName = '2026_10_28_140000_create_attendance_punch_corrections.php'
$migrationTarget = Join-Path $root ('database\migrations\' + $migrationName)
$controllerTarget = Join-Path $root 'app\Http\Controllers\Admin\AttendancePunchCorrectionController.php'
foreach ($target in @($migrationTarget, $controllerTarget)) {
    if (Test-Path $target) { throw "Destination already exists. Inspect manually: $target" }
}
$backupDir = Join-Path $root ('attendance_correction_install_backup_' + (Get-Date -Format 'yyyyMMdd_HHmmss'))
New-Item -ItemType Directory -Path $backupDir | Out-Null
$patchTargets = @('routes\web.php','app\Http\Controllers\Admin\AttendanceOperationsController.php','app\Services\Attendance\AttendanceDailyService.php','app\Services\Attendance\CompensatoryLeaveService.php','resources\js\Pages\Admin\Attendance\Operations.vue')
foreach ($rel in $patchTargets) {
    $source = Join-Path $root $rel
    if (!(Test-Path $source)) { throw "Required application file missing: $source" }
    Copy-Item $source (Join-Path $backupDir ($rel -replace '[\\/]', '__'))
}
$installed = $false
try {
    Copy-Item (Join-Path $package $migrationName) $migrationTarget
    Copy-Item (Join-Path $package 'AttendancePunchCorrectionController.php') $controllerTarget
    & php (Join-Path $package 'patch.php')
    if ($LASTEXITCODE -ne 0) { throw 'Patch failed' }
    foreach ($file in @($migrationTarget,$controllerTarget) + @($patchTargets | Where-Object { $_ -like '*.php' } | ForEach-Object { Join-Path $root $_ })) {
        & php -l $file
        if ($LASTEXITCODE -ne 0) { throw "PHP syntax invalid: $file" }
    }
    & npm run build
    if ($LASTEXITCODE -ne 0) { throw 'Frontend build failed' }
    & php artisan route:list --path=admin/attendance
    if ($LASTEXITCODE -ne 0) { throw 'Route validation failed' }
    $installed = $true
} finally {
    if (!$installed) {
        Write-Warning 'Installation failed; restoring modified source files.'
        foreach ($rel in $patchTargets) { Copy-Item (Join-Path $backupDir ($rel -replace '[\\/]', '__')) (Join-Path $root $rel) -Force }
        Remove-Item $migrationTarget, $controllerTarget -ErrorAction SilentlyContinue
    }
}
Write-Host "SOURCE_INSTALLED=YES; SOURCE_BACKUP=$backupDir" -ForegroundColor Green
Write-Host 'DATABASE_NOT_CHANGED: Take a verified PostgreSQL backup, then run:' -ForegroundColor Yellow
Write-Host "php artisan migrate --path=database/migrations/$migrationName"
Write-Host 'After migration run: php artisan optimize:clear'
