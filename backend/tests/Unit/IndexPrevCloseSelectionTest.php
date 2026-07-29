<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\StockController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * /api/indices 기준가(전일종가) 선택 회귀 테스트 (2026-07-29)
 *
 * 버그: parseYahooFinanceChart 가 null 을 걸러낸 종가 배열의 n-2 를 기준가로 썼다.
 *       평시엔 마지막 원소가 '오늘 진행 중인 봉'이라 맞지만, 오늘 봉이 null 이면
 *       걸러낸 배열의 마지막이 이미 전 거래일이라 한 칸 더 밀려 '전전 거래일'이 잡힌다.
 *       실측: ^KS11 기준가 6755.75(7/27) → -16.17% (실제 -5.98%).
 *
 * 수정: 위치가 아니라 '봉의 날짜'(거래소 현지 = regularMarketTime + gmtoffset)로 당일 봉을 제외.
 *
 * 픽스처는 2026-07-29 Yahoo 실응답에서 발췌.
 */
class IndexPrevCloseSelectionTest extends TestCase
{
    #[Test]
    public function test_kospi_index_uses_last_completed_close_when_today_candle_is_null(): void
    {
        // ^KS11 — 오늘(7/29, KST) 봉이 아직 null 인 상태
        $parsed = $this->parse([
            'gmtoffset' => 32400,              // Asia/Seoul
            'regularMarketTime' => 1785315940, // 2026-07-29 18:05 KST
            'regularMarketPrice' => 5663.24,
            'chartPreviousClose' => 6516.27,
        ], [
            1785110400 => 6755.75,          // 2026-07-27
            1785196800 => 6023.66015625,    // 2026-07-28 ← 마지막 완료 종가
            1785283200 => null,             // 2026-07-29 (미도착)
        ], '코스피 지수');

        $this->assertSame(6023.66, round(5663.24 - $parsed['change'], 2), '기준가가 7/28 종가(6023.66)가 아님');
        $this->assertSame(-360.42, $parsed['change']);
        $this->assertSame(-5.98, $parsed['change_percent']);
    }

    #[Test]
    public function test_nasdaq_futures_uses_previous_close_when_today_candle_is_in_progress(): void
    {
        // NQ=F — 마지막 봉이 오늘(7/29, ET) 진행 중인 봉 (회귀 방지)
        $parsed = $this->parse([
            'gmtoffset' => -14400,             // America/New_York
            'regularMarketTime' => 1785341361, // 2026-07-29 12:09 ET
            'regularMarketPrice' => 27448.25,
            'chartPreviousClose' => 29181.25,
        ], [
            1784865600 => 28282.25,   // 2026-07-24
            1785124800 => 28190.0,    // 2026-07-27
            1785211200 => 27922.0,    // 2026-07-28 ← 마지막 완료 종가
            1785297600 => 27448.25,   // 2026-07-29 (진행 중)
        ], '나스닥100 선물');

        $this->assertSame(27922.0, round(27448.25 - $parsed['change'], 2), '기준가가 7/28 종가(27922.0)가 아님');
        $this->assertSame(-473.75, $parsed['change']);
        $this->assertSame(-1.7, $parsed['change_percent']);
    }

    #[Test]
    public function test_falls_back_to_position_rule_when_timestamps_are_missing(): void
    {
        // timestamp 배열이 없으면 날짜 판정이 불가 → 종전대로 '마지막 봉 = 당일 봉' 위치 규칙
        $result = [
            'meta' => ['gmtoffset' => -14400, 'regularMarketTime' => 1785341361, 'regularMarketPrice' => 27448.25],
            'indicators' => ['quote' => [['close' => [28190.0, 27922.0, 27448.25]]]],
        ];

        $parsed = $this->invokeParser(['chart' => ['result' => [$result]]], '나스닥100 선물');

        $this->assertSame(27922.0, round(27448.25 - $parsed['change'], 2));
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<int, float|null>  $series  timestamp => close
     * @return array<string, mixed>
     */
    private function parse(array $meta, array $series, string $name): array
    {
        return $this->invokeParser([
            'chart' => [
                'result' => [[
                    'meta' => $meta,
                    'timestamp' => array_keys($series),
                    'indicators' => ['quote' => [['close' => array_values($series)]]],
                ]],
            ],
        ], $name);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function invokeParser(array $payload, string $name): array
    {
        // parseYahooFinanceChart 는 $this 의 주입 의존성을 쓰지 않으므로 생성자 없이 호출한다.
        $controller = (new ReflectionClass(StockController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(StockController::class, 'parseYahooFinanceChart');
        $method->setAccessible(true);

        return $method->invoke($controller, $payload, $name);
    }
}
