<?php

declare(strict_types=1);

namespace App\Services\Toss;

/**
 * 앱 내부 심볼 → 토스 API `symbols=` 값 정규화 · 시장 분류 · 지수 skip 판정.
 *
 * 현재 KisParallelPriceFetcher(L75~111)·StockController(L124) 등 3~4곳에 산재한
 * 동일 정규식·skip 로직을 이 클래스로 일원화한다.
 * (이번 Phase 1에서는 신규 작성만 — 기존 호출부 교체는 후속 Phase에서 진행)
 *
 * 규칙 (설계 §2.3, 실측 검증):
 *   미국 티커   : 그대로 통과 (TSLA → TSLA). 콜론 포맷(US:TSLA) 불가.
 *   국내 종목   : .KS/.KQ 접미사 제거 (005930.KS → 005930, 005935.KS → 005935).
 *   신형 영숫자  : 0167A0 그대로 통과. StockController L124의 0167AO→0167A0 오타교정 포함.
 *   지수 skip   : NQ=F, ^KS200, USDKRW=X, KOSPI_NIGHT, KOSPI200 → toTossSymbol() 시 null 반환.
 *
 * 시장 분류 (market()):
 *   KR    — .KS/.KQ 접미사 or /^\d{4}[0-9A-Z]{2}$/ or /^\d+$/
 *   INDEX — skip 목록에 포함
 *   US    — 그 외
 *
 * 참고 파일:
 *   KisParallelPriceFetcher.php L75~111 (skip·분류 정규식 원본)
 *   StockController.php L124 (0167AO→0167A0 오타교정)
 */
class TossSymbolMapper
{
    /**
     * 토스 API 에 보내지 않는 지수·환율 심볼 목록.
     *
     * KisParallelPriceFetcher L75 의 skipList 와 동일.
     * 지수는 Yahoo/KIS 경로로 분기, 환율은 TossFxProvider 가 전담.
     */
    private const INDEX_SKIP_LIST = [
        'NQ=F',
        '^KS200',
        'USDKRW=X',
        'KOSPI_NIGHT',
        'KOSPI200',
    ];

    // ──────────────────────────────────────────────────────────────────
    // 공개 인터페이스
    // ──────────────────────────────────────────────────────────────────

    /**
     * 앱 내부 심볼을 토스 API `symbols=` 값으로 정규화하여 반환한다.
     *
     * 지수/환율 skip 목록이면 null 반환 — 호출자는 null 을 필터링해야 한다.
     *
     * @param  string  $appSymbol  앱 내부 심볼 (예: 005930.KS, TSLA, 0167AO)
     * @return string|null  토스 symbols 값 (지수면 null)
     */
    public function toTossSymbol(string $appSymbol): ?string
    {
        $symbol = $this->normalize($appSymbol);

        if ($this->isIndex($symbol)) {
            return null;
        }

        return $symbol;
    }

    /**
     * 심볼의 시장을 반환한다.
     *
     * @param  string  $appSymbol  앱 내부 심볼
     * @return string  'KR' | 'US' | 'INDEX'
     */
    public function market(string $appSymbol): string
    {
        $symbol = $this->normalize($appSymbol);

        if ($this->isIndex($symbol)) {
            return 'INDEX';
        }

        if ($this->isDomestic($symbol)) {
            return 'KR';
        }

        return 'US';
    }

    /**
     * 해당 심볼이 지수/환율 skip 대상인지 판정한다.
     *
     * skip 대상은 토스 API 에 보내지 않고 Yahoo/KIS 경로를 유지한다.
     *
     * @param  string  $appSymbol  앱 내부 심볼 (정규화 전 입력 가능)
     */
    public function isIndex(string $appSymbol): bool
    {
        // 정규화 후 skip 목록 조회 (대소문자 무관하게 strtoupper 통일)
        $upper = strtoupper(trim($appSymbol));
        return in_array($upper, self::INDEX_SKIP_LIST, true);
    }

    /**
     * isIndex 의 별칭 — 호출 가독성용.
     *
     * @param  string  $appSymbol
     */
    public function shouldSkip(string $appSymbol): bool
    {
        return $this->isIndex($appSymbol);
    }

    /**
     * 복수 심볼을 한 번에 토스 심볼로 변환한다 (null = 지수, 필터링됨).
     *
     * @param  string[]  $appSymbols
     * @return string[]  지수가 제외된 토스 symbols 배열
     */
    public function toTossSymbols(array $appSymbols): array
    {
        $result = [];
        foreach ($appSymbols as $sym) {
            $toss = $this->toTossSymbol($sym);
            if ($toss !== null) {
                $result[] = $toss;
            }
        }
        return $result;
    }

    // ──────────────────────────────────────────────────────────────────
    // 내부 전용
    // ──────────────────────────────────────────────────────────────────

    /**
     * 심볼을 토스 API 형식으로 정규화한다.
     *
     * - StockController L124: 0167AO → 0167A0 오타교정 (O→0)
     * - .KS/.KQ 접미사 제거 (대소문자 모두)
     * - 미국 티커: 그대로 (콜론 포맷 추가 금지)
     * - 전체 대문자화
     */
    private function normalize(string $symbol): string
    {
        $symbol = trim($symbol);

        // 오타교정: 0167AO → 0167A0 (영문 O → 숫자 0)
        // StockController L124 와 동일 규칙
        $symbol = str_replace('0167AO', '0167A0', $symbol);

        // .KS / .KQ 접미사 제거 (대소문자 무관)
        $symbol = (string) preg_replace('/\.(KS|KQ)$/i', '', $symbol);

        return strtoupper($symbol);
    }

    /**
     * 국내 종목 판정.
     *
     * KisParallelPriceFetcher L87~91 의 정규식 3개와 동일.
     *   - /(\.KS|\.KQ)$/i  — 아직 제거 안 된 경우 대비 (normalize 후엔 해당 없음)
     *   - /^\d{4}[0-9A-Z]{2}$/ — 6자리 숫자+영숫자 (예: 0167A0, 005930)
     *   - /^\d+$/            — 순수 숫자 코드
     *
     * normalize() 후 호출되므로 접미사는 이미 제거된 상태.
     *
     * @param  string  $normalizedSymbol  normalize() 결과
     */
    private function isDomestic(string $normalizedSymbol): bool
    {
        // 접미사 잔존 방어 (normalize 이전 입력이 들어왔을 경우)
        if (preg_match('/(\.KS|\.KQ)$/i', $normalizedSymbol)) {
            return true;
        }

        // 6자리 영숫자 코드 (국내 종목 표준 코드)
        if (preg_match('/^\d{4}[0-9A-Z]{2}$/', $normalizedSymbol)) {
            return true;
        }

        // 순수 숫자 코드
        if (preg_match('/^\d+$/', $normalizedSymbol)) {
            return true;
        }

        return false;
    }
}
