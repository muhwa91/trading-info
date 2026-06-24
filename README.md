# trading_info — 주식 실시간 모니터링 · 원화 통합 포트폴리오 트래커

미국/국내 주식을 실시간 모니터링하고, 보유 종목을 **원화로 통합 평가**(주가손익·환율손익 분리)하는 포트폴리오 트래커. PC 웹 + 모바일 앱(Capacitor).

> ⚠️ **조회·자문 전용** — 주문/매매·실현손익·투자 추천은 하지 않습니다(평가손익만, 면책 표기).

## 📡 데이터 소스
| 소스 | 담당 |
|---|---|
| **토스증권 Open API** (주력) | 국내·미국 현재가, 차트(분/일봉), 환율(USD/KRW), 종목 마스터(이름·타입·통화) |
| **Yahoo Finance** | 지수(나스닥100 선물 `NQ=F` · 코스피 `^KS11`/`^KS200`), 미국 차트 폴백 |

> **2026-06-24, 한국투자증권(KIS) API에서 토스증권 Open API로 전면 전환**하며 KIS 의존을 완전히 제거했습니다.
> 종목명·타입·통화 등 토스가 제공하는 메타데이터는 DB에 저장하지 않고 토스에서 받아 캐싱합니다(DB는 보유·관심·가격이력의 FK 중심축).
> 전환 상세: [`docs/features/toss-api-migration/`](docs/features/toss-api-migration/)

## 🛠 스택
- **백엔드**: Laravel 8 (PHP 7.4~8) + 로컬 MariaDB(`hachiware_1`)
- **프론트**: Vue 3 (순수 JS) + Vite + Tailwind v4 + daisyUI + lightweight-charts
- **실시간**: 순수 PHP Stream Socket WebSocket (포트 8080, 3초 사이클)

## ▶ 실행
| 구성 | 명령 |
|---|---|
| REST API | `cd backend && php artisan serve --port=8000` |
| WebSocket | `cd backend && php artisan agent:serve` (8080) |
| 프론트(HMR) | `cd frontend && npm run dev` (5173) |
| 원클릭 | `run_trading_info.vbs` (바탕화면 아이콘) |

> ⚠️ 백엔드(`StockController`·`WebSocketAgentServer` 등) 수정 후에는 **WS(8080) 재시작 필수** — long-running 프로세스라 재시작 전엔 옛 코드로 실행됩니다.

## ⚙ 환경설정 (`backend/.env`, git 제외)
- `TOSS_API_URL` / `TOSS_CLIENT_ID` / `TOSS_CLIENT_SECRET` — 토스증권 Open API (토스증권 WTS 설정 메뉴에서 발급)
- `DB_*` — 로컬 MariaDB 접속

## 📁 핵심 구조
- `backend/app/Services/Toss/` — 토스 API 게이트웨이(토큰·배치시세·차트·환율·종목마스터)
- `backend/app/Services/Quote/TossQuoteProvider.php` — 포트폴리오 평가가(국내·미국)
- `backend/app/Http/Controllers/StockController.php` — 시세·차트·지수 REST
- `backend/app/Services/MarketSessionService.php` — 장 세션·거래일 판정(토스 캘린더)
- `frontend/src/components/StockChart.vue` — 차트 컴포넌트
- `docs/` — 개발 문서 · 진행상황(`progress.html`)
