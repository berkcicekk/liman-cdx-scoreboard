$ErrorActionPreference = 'Stop'
$projectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$manifest = Get-Content -LiteralPath (Join-Path $projectRoot 'db.json') -Raw | ConvertFrom-Json
if ($manifest.name -cnotmatch '^[A-Za-z]+$') { throw 'Invalid Liman extension name.' }
if ($manifest.version -notmatch '^\d+\.\d+\.\d+$') { throw 'Invalid version.' }
$required = @('db.json','views/functions.php','views/index.blade.php','bin/worker.php','database/migrations.sql','database/migrations/001_locked_shields.sql','public/vendor/chart.umd.min.js','deploy.sh','scripts/probe_worker.php','scripts/smoke_test.php','scripts/smoke_test.sh')
foreach ($entry in $required) {
    if (-not (Test-Path -LiteralPath (Join-Path $projectRoot $entry))) { throw "Missing file: $entry" }
}
$functions = Get-Content -LiteralPath (Join-Path $projectRoot 'views/functions.php') -Raw
if ((Get-FileHash -LiteralPath (Join-Path $projectRoot 'database/migrations.sql')).Hash -ne (Get-FileHash -LiteralPath (Join-Path $projectRoot 'database/migrations/001_locked_shields.sql')).Hash) {
    throw 'database/migrations.sql and the versioned migration must stay synchronized.'
}
foreach ($fn in $manifest.functions) {
    if ($functions -notmatch ('function\s+' + [regex]::Escape($fn.name) + '\s*\(')) { throw "Missing endpoint: $($fn.name)" }
}
$outputDir = Join-Path $projectRoot 'dist'
New-Item -ItemType Directory -Force -Path $outputDir | Out-Null
$zipPath = Join-Path $outputDir ("Cenk-$($manifest.version).zip")
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$stream = [System.IO.File]::Open($zipPath, [System.IO.FileMode]::Create)
$zip = New-Object System.IO.Compression.ZipArchive($stream, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    $paths = @('app','bin','database','deploy','docs','lang','public','views','scripts')
    $files = foreach ($path in $paths) { Get-ChildItem -LiteralPath (Join-Path $projectRoot $path) -Recurse -File }
    $files += Get-Item -LiteralPath (Join-Path $projectRoot 'config/locked-shields.example.php')
    $files += Get-Item -LiteralPath (Join-Path $projectRoot 'config/deploy.example.php')
    foreach ($name in @('db.json','README.md','LICENSE','THIRD_PARTY.md','deploy.sh','DEPLOYMENT.md','MAKINE_HAZIRLAYAN_EKIP_TEKNIK_REHBERI.md')) { $files += Get-Item -LiteralPath (Join-Path $projectRoot $name) }
    foreach ($file in $files) {
        $entryName = $file.FullName.Substring($projectRoot.Length + 1).Replace('\','/')
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $file.FullName, $entryName, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
    }
} finally { $zip.Dispose(); $stream.Dispose() }
$verify = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
try {
    $names = @($verify.Entries | ForEach-Object FullName)
    foreach ($entry in $required) { if ($entry -notin $names) { throw "Missing ZIP entry: $entry" } }
    if ($names | Where-Object { $_ -match '^(tests|node_modules)/|config/local.php' }) { throw 'Development files leaked into package.' }
    Write-Output "Packaged $($names.Count) files: $zipPath"
} finally { $verify.Dispose() }
Get-FileHash -LiteralPath $zipPath -Algorithm SHA256 | Format-List
