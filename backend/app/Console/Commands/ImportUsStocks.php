<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * KIS 해외주식 종목마스터를 다운로드·파싱하여 storage/app/us_stocks.json 을 (재)생성한다.
 *
 * 출처(공개 파일, 인증 불필요):
 *   https://new.real.download.dws.co.kr/common/master/{nas|nys|ams}mst.cod.zip
 *
 * .cod 파일 포맷:
 *   - CP949 인코딩, 탭(\t) 구분자
 *   - 컬럼 순서(0-indexed):
 *       0: 국가코드(US)
 *       1: 거래소ID(22=나스닥 등)
 *       2: 거래소코드(NAS|NYS|AMS)
 *       3: 거래소명(나스닥 등)
 *       4: 심볼(Symbol)
 *       5: 실시간심볼(NASAACB 등)
 *       6: 한글명
 *       7: 영문명
 *       8: 증권유형(2=주식, 3=ETF 등)
 *       ...이후 컬럼은 사용 안 함
 *
 * 실행:
 *   php artisan stocks:import-us
 *   php artisan stocks:import-us --dry-run    (다운로드·파싱만, 파일 저장 안 함)
 */
class ImportUsStocks extends Command
{
    protected $signature = 'stocks:import-us
                            {--dry-run : 파일 저장 없이 파싱 결과만 출력}
                            {--markets=nas,nys,ams : 처리할 거래소 코드(쉼표 구분)}';

    protected $description = 'KIS 해외주식 마스터(NASDAQ/NYSE/AMEX)를 받아 us_stocks.json 생성 (한글명 검색용)';

    /** 거래소 코드 → 표시 이름 */
    private const EXCHANGE_LABELS = [
        'nas' => 'NAS',
        'nys' => 'NYS',
        'ams' => 'AMS',
    ];

    /** ZIP 다운로드 URL 패턴 */
    private const DOWNLOAD_URL = 'https://new.real.download.dws.co.kr/common/master/%smst.cod.zip';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $marketsRaw = (string) $this->option('markets');
        $markets = array_filter(array_map('trim', explode(',', strtolower($marketsRaw))));

        // 지원하지 않는 거래소 코드 걸러내기
        $validMarkets = array_filter($markets, fn (string $m) => isset(self::EXCHANGE_LABELS[$m]));
        if (empty($validMarkets)) {
            $this->error('유효한 거래소 코드가 없습니다. nas|nys|ams 중 하나 이상 지정하세요.');
            return self::FAILURE;
        }

        $this->info('KIS 해외주식 마스터 다운로드 시작: ' . implode(', ', $validMarkets));

        $allStocks = [];
        $stats = [];

        foreach ($validMarkets as $market) {
            $exchangeCode = self::EXCHANGE_LABELS[$market];
            $this->line("  [{$exchangeCode}] 다운로드 중...");

            try {
                $stocks = $this->downloadAndParse($market, $exchangeCode);
                $allStocks = array_merge($allStocks, $stocks);
                $stats[$exchangeCode] = count($stocks);
                $this->info("  [{$exchangeCode}] 파싱 완료: " . count($stocks) . "건");
            } catch (\Throwable $e) {
                $this->error("  [{$exchangeCode}] 오류: " . $e->getMessage());
                // 한 거래소 실패해도 나머지 계속 진행
            }
        }

        if (empty($allStocks)) {
            $this->error('파싱된 종목이 없습니다. 네트워크 또는 파일 포맷을 확인하세요.');
            return self::FAILURE;
        }

        // 심볼 기준 중복 제거 (먼저 나온 거래소 우선)
        $unique = [];
        $seen = [];
        foreach ($allStocks as $stock) {
            $symbol = $stock['symbol'];
            if (! isset($seen[$symbol])) {
                $seen[$symbol] = true;
                $unique[] = $stock;
            }
        }

        $total = count($unique);
        $this->info("전체 종목(중복 제거): {$total}건");

        if ($isDryRun) {
            $this->warn('[DRY-RUN] 파일 저장을 건너뜁니다.');
            // 미리보기 5건
            foreach (array_slice($unique, 0, 5) as $s) {
                $this->line("  [{$s['symbol']}] {$s['koName']} / {$s['enName']} ({$s['exchange']})");
            }
            return self::SUCCESS;
        }

        // storage/app/us_stocks.json 저장
        $json = json_encode($unique, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        Storage::disk('local')->put('us_stocks.json', $json);

        $path = storage_path('app/us_stocks.json');
        $this->info("저장 완료: {$path}");
        foreach ($stats as $excd => $cnt) {
            $this->line("  {$excd}: {$cnt}건");
        }
        $this->info("합계: {$total}건 (중복 제거 후)");

        return self::SUCCESS;
    }

    /**
     * 거래소 마스터 ZIP 다운로드 → CP949→UTF-8 변환 → 파싱하여 배열 반환.
     *
     * @return array<int, array{symbol: string, koName: string, enName: string, exchange: string}>
     * @throws \RuntimeException
     */
    private function downloadAndParse(string $market, string $exchangeCode): array
    {
        $url = sprintf(self::DOWNLOAD_URL, $market);
        $tmpZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "{$market}mst_tmp.zip";

        // 다운로드
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 60,
                'user_agent' => 'Mozilla/5.0 (compatible; Laravel/10)',
            ],
        ]);
        $bytes = @file_get_contents($url, false, $ctx);

        if ($bytes === false || strlen($bytes) < 100) {
            throw new \RuntimeException("다운로드 실패: {$url}");
        }

        file_put_contents($tmpZip, $bytes);

        // ZIP 압축 해제
        $zip = new \ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            @unlink($tmpZip);
            throw new \RuntimeException("ZIP 오픈 실패: {$tmpZip}");
        }

        $codContent = $zip->getFromIndex(0);
        $zip->close();
        @unlink($tmpZip);

        if ($codContent === false) {
            throw new \RuntimeException("ZIP 내부 파일 읽기 실패");
        }

        // CP949 → UTF-8
        $utf8 = iconv('cp949', 'utf-8//IGNORE', $codContent);
        if ($utf8 === false) {
            throw new \RuntimeException("인코딩 변환 실패 (cp949→utf-8)");
        }

        return $this->parseLines($utf8, $exchangeCode);
    }

    /**
     * UTF-8 변환된 .cod 텍스트를 파싱하여 종목 배열로 반환.
     *
     * 컬럼(탭 구분):
     *   [0] 국가코드  [1] 거래소ID  [2] 거래소코드  [3] 거래소명
     *   [4] 심볼      [5] 실시간심볼  [6] 한글명  [7] 영문명
     *   [8] 증권유형  ...
     *
     * @return array<int, array{symbol: string, koName: string, enName: string, exchange: string}>
     */
    private function parseLines(string $text, string $exchangeCode): array
    {
        $stocks = [];
        // 개행 문자 정규화 (Windows \r\n → \n)
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);
        $lines = explode("\n", $text);

        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }

            $cols = explode("\t", $line);
            if (count($cols) < 8) {
                continue;
            }

            $symbol = trim($cols[4]);
            $koName = trim($cols[6]);
            $enName = trim($cols[7]);

            // 심볼 없거나 너무 길면(워런트·유닛 등 제외) 건너뜀
            if ($symbol === '' || mb_strlen($symbol, 'UTF-8') > 10) {
                continue;
            }

            // 한글명·영문명 둘 다 없으면 건너뜀
            if ($koName === '' && $enName === '') {
                continue;
            }

            // 영문명이 없으면 한글명으로 대체
            if ($enName === '') {
                $enName = $koName;
            }

            // 한글명이 없으면(영문만 있는 ETF 등) 영문명으로 대체
            if ($koName === '') {
                $koName = $enName;
            }

            $stocks[] = [
                'symbol'   => $symbol,
                'koName'   => $koName,
                'enName'   => $enName,
                'exchange' => $exchangeCode,
            ];
        }

        return $stocks;
    }
}
