# trading_info 세팅 스크립트 (Laravel 8 + Vue 3 + MariaDB)
# ─────────────────────────────────────────────────────────────
# 사용: chiikawa_dev 클론 후 이 폴더에서 PowerShell 로 실행.
#   powershell -ExecutionPolicy Bypass -File .\setup_trading_info.ps1
#
# 사전 준비(SETUP.md 참조 — 런타임은 이 스크립트가 설치하지 않음):
#   - git · php · composer · node/npm · MariaDB 가 설치돼 PATH 에 있어야 함
#   - MariaDB 에 DB·유저 생성:  (root 로 1회)
#       CREATE DATABASE hachiware_1 CHARACTER SET utf8mb4;
#       CREATE USER 'chiikawa'@'127.0.0.1' IDENTIFIED BY '<원하는비번>';
#       GRANT ALL ON hachiware_1.* TO 'chiikawa'@'127.0.0.1';  FLUSH PRIVILEGES;
# ─────────────────────────────────────────────────────────────

$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

# 1) .env 확인 — 없으면 example 복사 후 비밀값 입력 안내 + 종료
if (-not (Test-Path "backend\.env")) {
    Copy-Item "backend\.env.example" "backend\.env"
    Write-Host ""
    Write-Host "[!] backend\.env 를 생성했습니다. 아래 3개 값을 채운 뒤 다시 실행하세요:" -ForegroundColor Yellow
    Write-Host "      TOSS_CLIENT_ID      = (토스증권 WTS 설정에서 발급)"
    Write-Host "      TOSS_CLIENT_SECRET  = (〃)"
    Write-Host "      DB_PASSWORD         = (MariaDB chiikawa 유저 비번)"
    Write-Host "    (DB명 hachiware_1 · 유저 chiikawa · TOSS_API_URL 은 이미 채워져 있음)"
    exit 1
}

# 2) backend: 의존성 → 앱키 → 마이그레이션+시드
Write-Host "[1/3] backend: composer install ..." -ForegroundColor Cyan
Set-Location "backend"
composer install --no-interaction --prefer-dist

if (-not (Select-String -Path ".env" -Pattern "^APP_KEY=base64" -Quiet)) {
    Write-Host "      php artisan key:generate" -ForegroundColor DarkCyan
    php artisan key:generate
}

Write-Host "[2/3] DB 마이그레이션 + 시드(계정·종목마스터만) ..." -ForegroundColor Cyan
php artisan migrate --seed --force

# 3) frontend: 의존성
Write-Host "[3/3] frontend: npm install ..." -ForegroundColor Cyan
Set-Location "..\frontend"
npm install

Set-Location ".."
Write-Host ""
Write-Host "[완료] trading_info 세팅 끝." -ForegroundColor Green
Write-Host "  실행: run_trading_info.vbs  (또는 start_trading_info.bat / php artisan serve · agent:serve · npm run dev)"
Write-Host "  ※ 보유종목·관심종목은 개인 데이터라 시드되지 않습니다 — 화면에서 새로 입력하세요."
