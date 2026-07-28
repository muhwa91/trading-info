# trading-info 세팅 스크립트 (Laravel 13 + Vue 3 + TiDB Cloud Serverless)
# ─────────────────────────────────────────────────────────────
# 사용: chiikawa_dev 클론 후 이 폴더에서 PowerShell 로 실행.
#   powershell -ExecutionPolicy Bypass -File .\setup_trading-info.ps1
#
# 사전 준비(SETUP.md 참조 — 런타임은 이 스크립트가 설치하지 않음):
#   - git · composer · node/npm 이 설치돼 PATH 에 있어야 함 (DB 는 TiDB Cloud — 로컬 DB 설치 불요)
#   - PHP 8.4.1+ 필요(Laravel 13 + composer.lock 의 symfony 8.1). PATH 의 php 를 그대로 쓴다.
#       · 2026-07-28 시스템 PATH 의 C:\xampp\php(7.4) 항목을 C:\php84 로 교체 — 이제 PATH 의 php 가 8.4.x.
#       · 다른 머신에서 PATH 에 구버전 php 가 먼저 잡히면 아래 0) 이 C:\php84\php.exe 로 폴백하고,
#         그마저 없거나 8.4.1 미만이면 명확한 안내와 함께 중단한다(구버전으로는 artisan 이 아예 뜨지 않음).
#       · php.ini 에서 extension=curl·openssl·mbstring·pdo_mysql·fileinfo·zip 활성화 필요.
#       · ★ cacert.pem 필수 — https://curl.se/ca/cacert.pem 를 C:\php84\cacert.pem 로 받고 php.ini 에
#         curl.cainfo = "C:\php84\cacert.pem"  /  openssl.cafile = "C:\php84\cacert.pem" 를 설정할 것.
#         (미설정 시 모든 HTTPS 가 "조용히" 실패 → 시세·캔들이 틀린 값으로 채워짐. 아래 0-1)에서 검사)
#   - DB 는 TiDB Cloud Serverless(관리형, MySQL 8 호환·TLS 필수 — ADR-006). 로컬 DB 생성 불요.
#       · 접속정보(호스트·유저·비번)는 backend\.env 의 DB_* 에 넣는다(아래 1) 단계 안내).
#       · CA 인증서 backend\tidb-ca.pem 은 레포에 커밋돼 있어 클론만으로 준비됨(TLS 검증용).
# ─────────────────────────────────────────────────────────────

$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

# 0) PHP 실행 파일 — 이 스크립트에서 PHP 경로를 정하는 유일한 곳(아래는 $php 만 쓴다).
#    ① PATH 의 php 가 8.4.1 이상이면 그것 → ② 아니면 C:\php84\php.exe → ③ 둘 다 아니면 중단.
$phpMin = [version]"8.4.1"

function Get-PhpVersion([string]$exe) {
    # 로컬 스코프에서만 완화 — native stderr 가 예외로 승격돼 정상 php 를 탈락시키지 않게.
    $ErrorActionPreference = "SilentlyContinue"
    $out = $null
    try { $out = & $exe -r "echo PHP_VERSION;" 2>$null } catch { $out = $null }
    if ($out -is [array]) { $out = $out -join "" }
    if ("$out" -match "(\d+\.\d+\.\d+)") { return [version]$Matches[1] }
    return $null
}

$phpCandidates = @()
$phpOnPath = Get-Command php -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
if ($phpOnPath) { $phpCandidates += $phpOnPath.Source }
$phpCandidates += "C:\php84\php.exe"
$phpCandidates = @($phpCandidates | Select-Object -Unique)

$php = ""
$phpDiag = @()
foreach ($cand in $phpCandidates) {
    if (-not (Test-Path $cand)) {
        $phpDiag += "      - $cand : 없음"
        continue
    }
    $ver = Get-PhpVersion $cand
    if ($null -eq $ver) {
        $phpDiag += "      - $cand : 버전 확인 실패"
    } elseif ($ver -lt $phpMin) {
        $phpDiag += "      - $cand : $ver (8.4.1 미만)"
    } else {
        $php = $cand
        break
    }
}
if (-not $php) {
    Write-Host ""
    Write-Host "[!] PHP 8.4.1 이상을 찾지 못했습니다. (Laravel 13 + symfony 8.1 요구 버전)" -ForegroundColor Red
    Write-Host "    검사한 후보:"
    foreach ($line in $phpDiag) { Write-Host $line }
    Write-Host "    조치: PHP 8.4+ 를 설치해 PATH 에 넣거나 C:\php84\php.exe 로 두세요."
    exit 1
}
# composer.bat 은 PATH 의 php 를 부른다 → 구버전(8.4.1 미만)이 먼저 잡히면 platform 오류.
# 이 세션 PATH 앞에 위 php 의 폴더를 끼워 composer 도 같은 PHP 를 쓰게 한다.
$env:Path = (Split-Path $php) + ";" + $env:Path
Write-Host "[PHP] $php  ($(& $php -r 'echo PHP_VERSION;'))" -ForegroundColor DarkGray

# 0-0) php_path.txt 기록 — 바탕화면 아이콘(run_trading-info*.vbs)은 이 파일로 php 를 찾는다.
#      머신마다 다른 값이라 gitignore 대상. vbs 의 OpenTextFile(...).ReadLine 은 ANSI 로 읽으므로
#      BOM 없이 ASCII 로 쓴다(경로는 ASCII 뿐). 내용이 같으면 다시 쓰지 않는다.
$phpPathFile = Join-Path $PSScriptRoot "php_path.txt"
$phpPathOld = ""
if (Test-Path $phpPathFile) {
    $firstLine = Get-Content $phpPathFile -TotalCount 1
    if ($firstLine) { $phpPathOld = $firstLine.Trim() }
}
if ($phpPathOld -ne $php) {
    [IO.File]::WriteAllText($phpPathFile, $php + "`r`n", [Text.Encoding]::ASCII)
    Write-Host "[PHP] php_path.txt 갱신 (바탕화면 아이콘용)" -ForegroundColor DarkGray
}

# 0-1) CA 인증서 확인 — 미설정이면 모든 HTTPS 가 예외 없이 "조용히" 실패한다.
#      (증상: 시세 API 가 값을 못 받아 기준가·캔들이 틀린 값으로 채워짐. 테스트는 전부 hermetic 이라 못 잡음.)
#      composer install·migrate 로 시간을 쓰기 전에 여기서 먼저 막는다.
$cainfo = (& $php -r "echo ini_get('curl.cainfo');")
$cafile = (& $php -r "echo ini_get('openssl.cafile');")
if (-not $cainfo -or -not $cafile) {
    Write-Host ""
    Write-Host "[!] php.ini 에 CA 인증서가 설정되지 않았습니다 (curl.cainfo='$cainfo' / openssl.cafile='$cafile')." -ForegroundColor Red
    Write-Host "    이대로 두면 모든 HTTPS 요청이 조용히 실패해 시세가 틀린 값으로 채워집니다."
    Write-Host "    조치: https://curl.se/ca/cacert.pem 를 받아 PHP 폴더에 두고 php.ini 에 아래 2줄을 추가한 뒤 다시 실행하세요."
    Write-Host "        curl.cainfo = `"$(Split-Path $php)\cacert.pem`""
    Write-Host "        openssl.cafile = `"$(Split-Path $php)\cacert.pem`""
    exit 1
}

# 1) .env 확인 — 없으면 example 복사 후 비밀값 입력 안내 + 종료
if (-not (Test-Path "backend\.env")) {
    Copy-Item "backend\.env.example" "backend\.env"
    Write-Host ""
    Write-Host "[!] backend\.env 를 생성했습니다. 아래 3개 값을 채운 뒤 다시 실행하세요:" -ForegroundColor Yellow
    Write-Host "      TOSS_CLIENT_ID      = (토스증권 WTS 설정에서 발급)"
    Write-Host "      TOSS_CLIENT_SECRET  = (〃)"
    Write-Host "      DB_HOST/DB_USERNAME/DB_PASSWORD = (TiDB Cloud 접속정보 — 콘솔 Connect 에서 확인)"
    Write-Host "    (DB명 hachiware · DB_CONNECTION=mysql · TLS 필수(tidb-ca.pem) · TOSS_API_URL 은 이미 채워져 있음)"
    exit 1
}

# 2) backend: 의존성 → 앱키 → 마이그레이션+시드
Write-Host "[1/3] backend: composer install ..." -ForegroundColor Cyan
Set-Location "backend"
composer install --no-interaction --prefer-dist

if (-not (Select-String -Path ".env" -Pattern "^APP_KEY=base64" -Quiet)) {
    Write-Host "      php artisan key:generate" -ForegroundColor DarkCyan
    & $php artisan key:generate
}

Write-Host "[2/3] DB 마이그레이션 + 시드(계정·종목마스터만) ..." -ForegroundColor Cyan
& $php artisan migrate --seed --force

# 3) frontend: 의존성
Write-Host "[3/3] frontend: npm install ..." -ForegroundColor Cyan
Set-Location "..\frontend"
npm install
Set-Location ".."

Write-Host ""
Write-Host "[완료] trading-info 세팅 끝." -ForegroundColor Green
Write-Host "  실행: run_trading-info.vbs  (또는 start_trading-info.ps1)"
Write-Host "  ※ 보유종목·관심종목은 개인 데이터라 시드되지 않습니다 — 화면에서 새로 입력하세요."
