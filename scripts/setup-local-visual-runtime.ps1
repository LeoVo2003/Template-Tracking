[CmdletBinding()]
param(
    [string]$RunnerRoot = "C:\actions-runner\actions-runner"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Get-DirectorySizeBytes {
    param([Parameter(Mandatory = $true)][string]$Path)

    if (-not (Test-Path -LiteralPath $Path)) {
        return [int64] 0
    }

    return [int64] ((Get-ChildItem -LiteralPath $Path -File -Recurse -Force -ErrorAction SilentlyContinue |
        Measure-Object -Property Length -Sum).Sum)
}

if (-not (Test-Path -LiteralPath $RunnerRoot -PathType Container)) {
    throw "Runner root not found: $RunnerRoot"
}

$nodeCommand = Get-Command node -ErrorAction SilentlyContinue
$npmCommand = Get-Command npm -ErrorAction SilentlyContinue
if (-not $nodeCommand -or -not $npmCommand) {
    throw "Node.js and npm are required. Install one persistent Node.js 20+ runtime for this Windows user, then rerun this script."
}

$nodeVersion = (& node --version).Trim()
$npmVersion = (& npm --version).Trim()
$nodeMajor = [int] (($nodeVersion -replace '^v', '').Split('.')[0])
if ($nodeMajor -lt 20) {
    throw "Node.js 20 or newer is required. Found: $nodeVersion"
}

$runnerConfigPath = Join-Path $RunnerRoot '.runner'
$workFolder = '_work'
if (Test-Path -LiteralPath $runnerConfigPath) {
    $runnerConfig = Get-Content -Raw -LiteralPath $runnerConfigPath | ConvertFrom-Json
    if ($runnerConfig.workFolder) {
        $workFolder = [string] $runnerConfig.workFolder
    }
}

$runtimeRoot = if ([System.IO.Path]::IsPathRooted($workFolder)) {
    [System.IO.Path]::GetFullPath($workFolder)
} else {
    [System.IO.Path]::GetFullPath((Join-Path $RunnerRoot $workFolder))
}
$browserRoot = Join-Path $runtimeRoot '.mac-visual-browsers'
$nodeModulesRoot = Join-Path $runtimeRoot 'node_modules'

New-Item -ItemType Directory -Path $runtimeRoot -Force | Out-Null
Push-Location $runtimeRoot
try {
    if (-not (Test-Path -LiteralPath (Join-Path $runtimeRoot 'package.json'))) {
        & npm init -y | Out-Null
        if ($LASTEXITCODE -ne 0) {
            throw "npm init failed with exit code $LASTEXITCODE."
        }
    }

    $requiredPackages = @{
        playwright = '1.57.0'
        sharp      = '0.34.5'
    }
    $needInstall = $false
    foreach ($packageName in $requiredPackages.Keys) {
        $packageJsonPath = Join-Path $nodeModulesRoot "$packageName\package.json"
        if (-not (Test-Path -LiteralPath $packageJsonPath)) {
            $needInstall = $true
            continue
        }
        $installedPackage = Get-Content -Raw -LiteralPath $packageJsonPath | ConvertFrom-Json
        if ([string] $installedPackage.version -ne $requiredPackages[$packageName]) {
            $needInstall = $true
        }
    }

    if ($needInstall) {
        & npm install --no-save --package-lock=false playwright@1.57.0 sharp@0.34.5
        if ($LASTEXITCODE -ne 0) {
            throw "Persistent Visual Tone dependency installation failed with exit code $LASTEXITCODE."
        }
    }

    $env:PLAYWRIGHT_BROWSERS_PATH = $browserRoot
    $playwrightCli = Join-Path $nodeModulesRoot 'playwright\cli.js'
    if (-not (Test-Path -LiteralPath $playwrightCli)) {
        throw "Playwright CLI not found after installation: $playwrightCli"
    }

    $installHelp = (& node $playwrightCli install --help 2>&1 | Out-String)
    if ($installHelp -match '--only-shell') {
        & node $playwrightCli install chromium --only-shell
    } else {
        Write-Warning 'Playwright does not advertise --only-shell; installing the Chromium fallback once.'
        & node $playwrightCli install chromium
    }
    if ($LASTEXITCODE -ne 0) {
        throw "Persistent Chromium installation failed with exit code $LASTEXITCODE."
    }

    & node -e "import('playwright').then(async ({chromium})=>{const browser=await chromium.launch({headless:true});console.log('browser ok');await browser.close();}).catch((error)=>{console.error(error);process.exit(1);})"
    if ($LASTEXITCODE -ne 0) {
        throw "Headless Chromium verification failed with exit code $LASTEXITCODE."
    }
    & node -e "import('sharp').then(()=>console.log('sharp ok')).catch((error)=>{console.error(error);process.exit(1);})"
    if ($LASTEXITCODE -ne 0) {
        throw "Sharp verification failed with exit code $LASTEXITCODE."
    }

    $packageBytes = Get-DirectorySizeBytes -Path $nodeModulesRoot
    $browserBytes = Get-DirectorySizeBytes -Path $browserRoot
    Write-Host ''
    Write-Host 'MAC Visual local runtime ready.'
    Write-Host "Runner: $RunnerRoot"
    Write-Host "Runtime: $runtimeRoot"
    Write-Host "Node modules: $nodeModulesRoot"
    Write-Host "Browsers: $browserRoot"
    Write-Host "Node: $nodeVersion"
    Write-Host "npm: $npmVersion"
    Write-Host "Packages bytes: $packageBytes"
    Write-Host "Browser bytes: $browserBytes"
    Write-Host 'No credentials or GitHub registration token are stored in this runtime.'
    Write-Host 'Keep the runner manual: start it with .\run.cmd. Do not enable untrusted pull-request workflows on this self-hosted runner.'
} finally {
    Pop-Location
}
