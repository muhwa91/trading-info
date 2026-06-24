<?php

declare(strict_types=1);

namespace App\Services\Toss;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 토스증권 Open API 환율 프로바이더.
 *
 * 엔드포인트: GET /api/v1/exchange-rate?baseCurrency=USD&quoteCurrency=KRW
 *
 * 실측 검증된 응답 구조:
 *   {
 *     "result": {
 *       "baseCurrency": "USD",
 *       "quoteCurrency": "KRW",
 *       "rate": "1548.82",
 *       "midRate": "1548.32",
 *       "rateChangeType": "DOWN",
 *       "validFrom": "...",
 *       "validUntil": "..."
 *     }
 *   }
 *
 * rate 는 문자열로 전달되므로 float 파싱 후 반환.
 * 실패·빈 응답 시 null (graceful 폴백).
 *
 * 보안:
 *   토큰·시크릿은 TossApiClient 가 관리. 이 클래스는 파싱만 책임.
 *   응답 rate 값만 로그에 기록 (민감 정보 없음).
 */
class TossFxProvider
{
    private const ENDPOINT = '/api/v1/exchange-rate';

    private TossApiClient $client;

    public function __construct(TossApiClient $client)
    {
        $this->client = $client;
    }

    /**
     * 토스 Open API 에서 USD→KRW 환율을 조회한다.
     *
     * 성공: 파싱된 rate float 반환 (예: 1548.82)
     * 실패 / 빈 응답 / rate <= 0: null 반환
     *
     * @return array{rate:float,recorded_at:string,source:string}|null
     */
    public function fetchUsdKrw(): ?array
    {
        try {
            $data = $this->client->get(self::ENDPOINT, [
                'baseCurrency'  => 'USD',
                'quoteCurrency' => 'KRW',
            ]);

            if (empty($data)) {
                Log::warning('[TossFxProvider] 응답이 비어있음');
                return null;
            }

            $result = $data['result'] ?? null;
            if (!is_array($result)) {
                Log::warning('[TossFxProvider] result 키 없음', ['keys' => array_keys($data)]);
                return null;
            }

            $rateRaw = isset($result['rate']) ? trim((string) $result['rate']) : '';
            if ($rateRaw === '') {
                Log::warning('[TossFxProvider] rate 필드 없음 또는 빈 값');
                return null;
            }

            $rate = (float) $rateRaw;
            if ($rate <= 0.0) {
                Log::warning('[TossFxProvider] rate 값 이상', ['rate_raw' => $rateRaw]);
                return null;
            }

            Log::info('[TossFxProvider] 환율 취득 성공', ['rate' => $rate]);

            return [
                'rate'        => round($rate, 4),
                'recorded_at' => Carbon::now()->toDateTimeString(),
                'source'      => 'Toss_ExchangeRate',
            ];
        } catch (\Throwable $e) {
            Log::error('[TossFxProvider] 환율 조회 예외: ' . $e->getMessage());
            return null;
        }
    }
}
