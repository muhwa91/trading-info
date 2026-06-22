# 포트폴리오 트래커 — trading_info 적응 설계서

> 원본 명세: [portfolio-tracker-buildspec.html](portfolio-tracker-buildspec.html) (BUILD SPEC v1.1, 2026-06-20)
> 이 문서는 원본 스펙을 **기존 trading_info 스택에 맞게 적응**시킨 결정·통합 계획이다.
> 원본 스펙의 **DB 스키마·손익 로직·폴링·세션·차트 규칙은 그대로 유효**하며, 아래 "확정 결정"만 원본과 다르다.

## 1. 적응 결정 (원본 스펙 대비 변경점)
| 항목 | 원본 스펙 | trading_info 적용 | 이유 |
|------|-----------|------------------|------|
| 통합 방식 | (신규 단독 앱) | **기존 모니터링에 기능 추가(증분)** | 여중기 결정 2026-06-20. 기존 KIS 프록시·차트·관심종목 재사용 |
| 프론트 연결 | Inertia + Vue | **기존 Vue SPA + Laravel API 유지** | Inertia는 Capacitor 모바일 패키징과 충돌. 손익 로직은 프론트 구조 무관하게 흡수 가능 |
| 로컬 DB | SQLite | **로컬 MariaDB `hachiware_1`** | 이미 세팅(2026-06-20). 스펙도 "배포 시 MySQL/PG" → 무방 |
| 관심종목/평단가 | DB 테이블 | LocalStorage → **DB로 이관** | 기존엔 브라우저에만 있음. 일회성 가져오기 제공 |

**그대로 채택(변경 없음)**: 8테이블 스키마, `evaluate()` 원화 손익 + 주가/환율손익 분리(`avg_fx_rate`), 세션 기준 라디오(전일/프리/정규), 폴링 5~15초(보유∪관심 합집합·캐시 신선도), 환율 장중 폴링(긴 캐시), 부드러운 봉(버킷 제자리 갱신 + 종가 이징), 분봉 1·3·5·10분/1시간/1일, 읽기 전용(주문 금지)·자문 아님.

## 2. DB 스키마 (MariaDB `hachiware_1`)
원본 스펙 §04 그대로. 8테이블: `stocks` · `accounts` · `portfolio`(`avg_fx_rate` 포함) · `watchlist` · `prices`(session enum) · `exchange_rates` · `dividends`(선택) · `users`.
- 엔진/charset: InnoDB · utf8mb4 (기존 프로젝트 규약). 금액은 종목 **원래 통화** 기준 `decimal` 저장.
- 기존 Laravel 기본 마이그레이션(users·personal_access_tokens 등)을 `hachiware_1`에 먼저 적용 후, 신규 8테이블 추가.
- `users`는 지금 id=1 한 행 고정(로그인은 배포 단계). 모든 테이블 `user_id` 미리 보유 → 배포 시 확장만.

## 3. API 설계 (Inertia 대신 REST — 기존 패턴 계승)
기존 `routes/api.php` 컨벤션(`/stocks/...`, `/news/...`, `/indices`)에 이어서 추가:

| 메서드·경로 | 설명 | 비고 |
|-------------|------|------|
| `GET /api/portfolio/dashboard?session=&tf=` | 대시보드 1콜: 보유 손익 + 관심 시세 + 환율 + 합계 | `evaluate()` 합산 결과 |
| `GET /api/prices?ids=&session=` | 폴링용 최신 시세(부족분만 KIS 호출 후 캐시) | 5~15초 폴링 |
| `GET /api/candles?stock_id=&tf=&from=` | 과거 봉(분봉/일봉) | 차트 초기 `setData()` |
| `GET /api/stocks/search?q=` | **기존 재사용**(StockController) | watchlist/portfolio 종목 연결 |
| `POST/PATCH/DELETE /api/portfolio` | 보유 종목 CRUD(수량·평단·매입환율·source) | 1차 수동입력 |
| `POST/DELETE /api/watchlist` | 즐겨찾기 추가/삭제·정렬 | |
| `GET/POST /api/accounts` | 증권계좌 메타(API 키는 .env, DB 금지) | 단일 사용자 |
| `GET/POST /api/dividends` | (선택) 배당 기록 | STEP 7 |

- **시세·환율 폴링 로직**: 기존 KIS 프록시(`StockController` / KIS 호출부)를 `QuoteProvider`(국내/해외)로 정리해 `prices` 캐시에 매핑(`session`=regular/pre/after). 환율은 전용 소스(한국은행 등) → `exchange_rates` 긴 캐시.
- **KIS 토큰**: 기존 캐시 방식 유지(발급 24h, 매요청 재발급 금지).

## 4. 프론트 통합 (기존 SPA)
- 신규 **포트폴리오 대시보드** 화면: 상단 기준 라디오(전일/프리/정규) + 총자산 요약(평가액·총손익·↳주가손익/환율손익) + 보유 섹션 + 관심 섹션. (스펙 §10 화면안)
- 기존 **lightweight-charts** 재사용 + 평단선 오버레이 + **부드러운 봉**(스펙 §10 코드: 버킷 `onTick` + rAF `animateClose`). 분봉 라디오(1·3·5·10분/1h/1d).
- 폴링: `setInterval` 5~15초 + `visibilitychange`로 백그라운드 정지(스펙 §08). 시장 마감 시 폴링 정지·마지막값 유지.
- **LocalStorage 이관**: 기존 관심종목·평단가를 읽어 `watchlist`/`portfolio`로 일회성 가져오기 버튼 제공(단일 사용자라 단순).

## 5. 제작 순서 (스펙 §12를 trading_info에 매핑)
- **STEP 0** — 기본 마이그레이션을 `hachiware_1`에 적용(`php artisan migrate`). ✅ DB 연결 완료됨
- **STEP 1** — 신규 8테이블 마이그레이션 + KIS 종목파일 `stocks` 시딩 + 보유 입력 폼(수량·평단·**매입환율**)
- **STEP 2** — `QuoteProvider`(기존 KIS 프록시 정리) + 정규/시간외 조회 → `prices` 캐싱
- **STEP 3** — `evaluate()` 원화 손익 + 주가/환율 분리 + 대시보드 요약/보유 섹션
- **STEP 4** — 기준 라디오 + 5~15초 폴링 + 세션 배지
- **STEP 5** — 검색→`watchlist` + 관심 섹션 (LocalStorage 이관 포함)
- **STEP 6** — 차트(평단선 + 부드러운 봉 + 분봉 선택)
- **STEP 7(선택)** — 배당 / WebSocket 틱(기존 `agent:serve` 활용 여지)
- **DEPLOY(나중)** — 로그인(user_id 활용), 배포 DB

## 6. 제약 (스펙 §13 — 그대로 준수)
읽기 전용(주문 금지) · 투자 자문 아님(면책 표기) · 프리마켓 참고용 · 장 마감 시 폴링 정지 · 봉은 표시만 보간(데이터는 실제 OHLC, 가짜 시세 금지) · **KIS 키·계좌정보는 `.env`에만(DB·코드 평문 금지)** · API 장애 대비 수동입력 `source` 안전장치.
> 결정 로그(스펙 §14)는 합의 없이 변경 금지.
