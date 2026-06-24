<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * ETF 종목 type 보정 커맨드.
 *
 * KRX 상장 ETF는 별도 API 미확보 상태이므로,
 * 종목명에 포함된 대표 운용사 prefix 패턴으로 best-effort 분류.
 *
 * 커버리지:
 *   KODEX, TIGER, KBSTAR, HANARO, SOL, ACE, RISE, KOSEF, ARIRANG, BNK,
 *   히어로즈, 미래에셋TIGER, PLUS ETF 등 국내 주요 ETF 브랜드
 *
 * 한계:
 *   브랜드명 없는 ETF 또는 국가/해외 ETF 일부 누락 가능.
 *   정확한 분류는 한국거래소(KRX) ETF 목록 API(isin.net 등) 활용 권장.
 *   정확도 개선은 TODO 로 남김.
 *
 * 실행:
 *   php artisan stocks:classify-etf [--dry-run]
 */
class ClassifyEtfStocks extends Command
{
    protected $signature   = 'stocks:classify-etf {--dry-run : 실제 업데이트 없이 대상 목록만 출력}';
    protected $description = 'ETF 종목 type 보정 — 종목명 패턴 기반 best-effort (KR 종목 한정)';

    /**
     * 국내 ETF 브랜드/접두사 패턴 목록.
     * 대소문자 무관하게 종목명에 포함되면 ETF 로 분류.
     */
    private const ETF_NAME_PATTERNS = [
        'KODEX',
        'TIGER',
        'KBSTAR',
        'HANARO',
        'SOL ',     // 공백 포함(삼성SOL ETF 구분)
        'ACE ',
        'RISE ',
        'KOSEF',
        'ARIRANG',
        'TREX',
        'FOCUS',
        'PLUS ETF',
        'SMART',
        'BNK',
        'TIMEFOLIO',
        'HI ETF',
        '히어로즈',
        'KTOP',
        'LION',
        '레버리지',
        '인버스',
        'NASDAQ',
        'S&P500',
        'MSCI',
    ];

    public function handle(): int
    {
        // Phase 7 마이그레이션으로 stocks 테이블의 name·type 컬럼이 삭제됨.
        // 종목 타입은 TossStockMaster accessor(getTypeAttribute)가 제공하며,
        // DB 직접 update 방식은 더 이상 유효하지 않다.
        // 이 커맨드는 재설계 전까지 안전하게 종료한다.
        $this->warn('[stocks:classify-etf] 이 커맨드는 Phase 7 이후 비활성화됐습니다.');
        $this->warn('name·type 컬럼이 stocks 테이블에서 제거됐으며, ETF 타입은 TossStockMaster accessor가 제공합니다.');
        $this->info('KRX ETF 목록 API 기반 재설계 전까지 실행하지 마십시오.');

        return self::SUCCESS;
    }
}
