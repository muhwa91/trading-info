<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\StockController;
use App\Services\KisParallelPriceFetcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class WebSocketAgentServer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'agent:serve {--port=8080}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start the WebSocket real-time stock agent server';

    private $clients = [];
    private $clientStates = [];
    private $controller;
    private $parallelFetcher;
    private $noClientsTime = null;
    private $hasConnected = false;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->controller     = app(StockController::class);
        $this->parallelFetcher = app(KisParallelPriceFetcher::class);
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $port = $this->option('port');
        $address = "0.0.0.0:$port";
        
        $server = stream_socket_server("tcp://$address", $errno, $errstr);
        if (!$server) {
            $this->error("Failed to start server: $errstr ($errno)");
            return 1;
        }

        $this->info("WebSocket Agent Server running on ws://127.0.0.1:$port");
        
        // Disable blocking on server socket
        stream_set_blocking($server, 0);

        $lastPushTime = 0;

        while (true) {
            $read = $this->clients;
            $read[] = $server;
            $write = null;
            $except = null;

            // Wait up to 1 second for socket activity
            $numChanged = @stream_select($read, $write, $except, 1, 0);

            if ($numChanged === false) {
                $this->error("Select error occurred.");
                break;
            }

            if ($numChanged > 0) {
                // If server socket has activity, it means new client connection
                if (in_array($server, $read)) {
                    $newClient = @stream_socket_accept($server);
                    if ($newClient) {
                        stream_set_blocking($newClient, 0);
                        $id = (int)$newClient;
                        $this->clients[$id] = $newClient;
                        $this->clientStates[$id] = [
                            'handshake' => false,
                            'subscriptions' => [],
                            'timeframes' => []
                        ];
                        $this->info("New client connected: ID $id");
                    }
                    // Remove server from read array to process client messages
                    $key = array_search($server, $read);
                    unset($read[$key]);
                }

                // Process client sockets
                foreach ($read as $clientSocket) {
                    $id = (int)$clientSocket;
                    $data = fread($clientSocket, 2048);

                    if ($data === false || strlen($data) === 0) {
                        $this->disconnect($clientSocket);
                        continue;
                    }

                    if (!$this->clientStates[$id]['handshake']) {
                        $this->performHandshake($clientSocket, $data);
                    } else {
                        $this->handleMessage($clientSocket, $data);
                    }
                }
            }

            // Periodic data push (every 3 seconds)
            $now = microtime(true);
            if ($now - $lastPushTime >= 3.0) {
                $this->pushRealtimeData();
                $lastPushTime = $now;
            }

            // Check client count for auto-shutdown
            $activeCount = 0;
            foreach ($this->clientStates as $state) {
                if (isset($state['handshake']) && $state['handshake']) {
                    $activeCount++;
                }
            }



            // Sleep briefly to save CPU cycles
            usleep(10000); // 10ms
        }

        fclose($server);
        return 0;
    }

    private function disconnect($socket)
    {
        $id = (int)$socket;
        if (isset($this->clients[$id])) {
            @fclose($socket);
            unset($this->clients[$id]);
            unset($this->clientStates[$id]);
            $this->info("Client $id disconnected");
        }
    }

    private function performHandshake($socket, $rawHeaders)
    {
        $id = (int)$socket;
        $headers = [];
        $lines = explode("\r\n", $rawHeaders);
        
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }

        if (isset($headers['sec-websocket-key'])) {
            $key = $headers['sec-websocket-key'];
            $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            
            $response = "HTTP/1.1 101 Switching Protocols\r\n" .
                        "Upgrade: websocket\r\n" .
                        "Connection: Upgrade\r\n" .
                        "Sec-WebSocket-Accept: $accept\r\n\r\n";
            
            @fwrite($socket, $response);
            $this->clientStates[$id]['handshake'] = true;
            $this->hasConnected = true;
            $this->info("Handshake completed for client $id");
        } else {
            $this->disconnect($socket);
        }
    }

    private function handleMessage($socket, $rawPayload)
    {
        $decodedFrame = $this->decode($rawPayload);
        if (!$decodedFrame) return;

        $id = (int)$socket;

        if ($decodedFrame['type'] === 'close') {
            $this->disconnect($socket);
            return;
        }

        if ($decodedFrame['type'] === 'ping') {
            // Reply with Pong
            $pongFrame = pack('CC', 0x80 | 10, 0); // FIN = 1, Opcode = 10 (Pong)
            @fwrite($socket, $pongFrame);
            return;
        }

        if ($decodedFrame['type'] === 'text') {
            $message = json_decode($decodedFrame['data'], true);
            if (!$message) return;

            if (isset($message['type']) && $message['type'] === 'subscribe') {
                $tickers = $message['tickers'] ?? [];
                $timeframes = $message['timeframes'] ?? [];

                $this->clientStates[$id]['subscriptions'] = $tickers;
                $this->clientStates[$id]['timeframes'] = $timeframes;

                $tickerList = is_array($tickers) ? array_map(function($t) {
                    return is_array($t) ? json_encode($t) : (string)$t;
                }, $tickers) : [];
                $this->info("Client $id subscribed to: " . implode(', ', $tickerList));
                
                // Immediately push data to newly subscribed client
                $this->pushDataToClient($socket);
            }
        }
    }

    private function pushRealtimeData()
    {
        // 1. 활성 클라이언트 수집
        $activeClients = [];
        foreach ($this->clients as $id => $socket) {
            if ($this->clientStates[$id]['handshake'] && !empty($this->clientStates[$id]['subscriptions'])) {
                $activeClients[] = $socket;
            }
        }

        if (empty($activeClients)) return;

        // 2. 모든 클라이언트의 고유 구독 종목 수집
        $uniquePairs = [];
        foreach ($this->clients as $id => $socket) {
            if (!$this->clientStates[$id]['handshake']) continue;

            $subs = $this->clientStates[$id]['subscriptions'];
            $tfs  = $this->clientStates[$id]['timeframes'];

            foreach ($subs as $ticker) {
                if (!is_string($ticker) && !is_numeric($ticker)) {
                    continue;
                }
                $tf  = $tfs[$ticker] ?? '1d';
                $key = "{$ticker}:{$tf}";
                $uniquePairs[$key] = ['ticker' => $ticker, 'timeframe' => $tf];
            }
        }

        $cycleStart  = microtime(true);
        $tickerCount = count($uniquePairs);

        // ── stale-while-revalidate 전략 ──────────────────────────────────────
        //
        // 최종 순서:
        //   stale-restore(즉시) → 전송(Yahoo 네트워크 없음) → KIS 갱신 → Yahoo 갱신
        //
        // stale-restore 를 전송 앞에 두는 이유:
        //   Yahoo 캔들 캐시(TTL=90초)가 만료됐을 때 _last 백업키에서 이전 값을
        //   5초 TTL 로 임시 재주입해, 전송 루프가 캐시 히트를 보장받도록 한다.
        //   네트워크가 전혀 없으므로 수 ms 이내 완료.
        //
        // Yahoo 갱신을 전송 후에 두는 이유:
        //   전송은 stale 값(또는 살아있는 캐시)으로 즉시 완료.
        //   Yahoo HTTP(5~6초)는 전송·KIS 후에 실행돼 이번 사이클 전송에 영향 없음.
        //   _freshness 보조 키(TTL=90초)로 이미 갱신된 종목은 재호출 스킵.
        //
        // KIS 갱신을 전송 후에 두는 이유:
        //   전송은 "마지막으로 캐시에 저장된 KIS 가격"으로 즉시 완료.
        //   KIS fetch 가 느려도(최대 2.5초 하드 타임아웃) 이번 사이클 전송에 영향 없음.
        //   TTL 8초 → 다음 2~3 사이클은 캐시 히트.

        // 3. ── stale-restore: Yahoo 캐시 만료 종목에 _last 백업 재주입 (즉시, 네트워크 없음) ──
        $this->restoreStaleYahooCache($uniquePairs);
        $staleRestoreElapsed = round((microtime(true) - $cycleStart) * 1000);

        // 4. ── 캐시 우선 즉시 전송 ─────────────────────────────────────────────
        //    Yahoo 캐시가 살아있거나 stale-restore 로 재주입됐으므로 Yahoo 네트워크 없음.
        //    KIS 캐시도 TTL 8초로 여전히 유효한 경우가 대부분 → 네트워크 없음.
        $sendStart = microtime(true);

        foreach ($uniquePairs as $key => $pair) {
            $ticker = $pair['ticker'];
            $tf     = $pair['timeframe'];

            try {
                $request = new Request();
                $request->query->set('timeframe', $tf);
                // WS 전송 경로: stale 캐시 허용(동기 KIS fetch 금지 → 케이던스 40~50ms 유지)
                $request->attributes->set('ws_allow_stale', true);

                $response = $this->controller->getStockData($request, $ticker);
                $data     = json_decode($response->getContent(), true);

                // 이 종목을 구독한 클라이언트에게만 즉시 전송
                foreach ($activeClients as $socket) {
                    $cid   = (int)$socket;
                    $csubs = $this->clientStates[$cid]['subscriptions'];
                    $ctfs  = $this->clientStates[$cid]['timeframes'];

                    $subscribed = false;
                    foreach ($csubs as $s) {
                        if ((string)$s === (string)$ticker) { $subscribed = true; break; }
                    }
                    if ($subscribed && ($ctfs[$ticker] ?? '1d') === $tf) {
                        $payload = json_encode([
                            'type'   => 'update',
                            'stocks' => [$ticker => $data],
                        ]);
                        @fwrite($socket, $this->encode($payload));
                    }
                }
            } catch (\Exception $e) {
                $this->error("종목 데이터 처리 실패 [{$key}]: " . $e->getMessage());
            }
        }

        $sendElapsed = round((microtime(true) - $sendStart) * 1000);

        // 5. ── 전송 후 KIS 현재가 갱신 ──────────────────────────────────────────
        //    전송 완료 후 KIS 를 병렬 조회해 캐시를 갱신한다.
        //    → 다음 사이클(3초 후) 전송 시 최신 가격이 반영된다.
        //    → 이 fetch 가 타임아웃(2.5초)으로 실패해도 TTL 8초 내 기존 캐시가 유효.
        $allTickers = array_unique(array_column(array_values($uniquePairs), 'ticker'));
        $kisStats   = ['fetched' => 0, 'cached' => 0, 'failed' => 0];

        try {
            $apiUrl    = env('KIS_API_URL', 'https://openapivts.koreainvestment.com:29443');
            $appKey    = env('KIS_APP_KEY', '');
            $appSecret = env('KIS_APP_SECRET', '');
            $token     = Cache::get('kis_access_token', '');

            if ($token !== '' && $appKey !== '' && $appKey !== 'your_app_key_here') {
                $kisStats = $this->parallelFetcher->fetchAll(
                    $allTickers,
                    $apiUrl,
                    $appKey,
                    $appSecret,
                    $token
                );
            }
        } catch (\Exception $e) {
            $this->error("[KIS갱신] 오류: " . $e->getMessage());
        }

        $kisElapsed = round((microtime(true) - $sendStart) * 1000) - $sendElapsed;

        // 6. ── Yahoo 캔들 갱신 (전송+KIS 후, _last 백업 저장) ──────────────────
        //    _freshness 보조키(90초)가 살아있으면 스킵, 만료 종목만 Yahoo HTTP 호출.
        //    완료 후 결과를 Cache::forever("{$cacheKey}_last") 에 저장해 다음 stale-restore 에 활용.
        $yahooRefreshStart = microtime(true);
        try {
            $this->refreshYahooCache($uniquePairs);
        } catch (\Exception $e) {
            $this->error("[Yahoo갱신] 오류: " . $e->getMessage());
        }
        $yahooRefreshElapsed = round((microtime(true) - $yahooRefreshStart) * 1000);

        $totalElapsed = round((microtime(true) - $cycleStart) * 1000);

        $this->info(
            sprintf(
                "[사이클] 종목 %d개 — stale복원 %dms / 전송 %dms / KIS %dms / Yahoo갱신 %dms / 합계 %dms",
                $tickerCount,
                $staleRestoreElapsed,
                $sendElapsed,
                $kisElapsed,
                $yahooRefreshElapsed,
                $totalElapsed
            )
        );
    }

    private function pushDataToClient($socket)
    {
        $id = (int)$socket;
        if (!$this->clientStates[$id]['handshake']) return;

        $subs = $this->clientStates[$id]['subscriptions'];
        $tfs  = $this->clientStates[$id]['timeframes'];

        if (empty($subs)) return;

        // 유효 종목 목록 추림
        $validTickers = [];
        foreach ($subs as $ticker) {
            if (is_string($ticker) || is_numeric($ticker)) {
                $validTickers[] = (string)$ticker;
            }
        }

        // ── 병렬 선조회: KIS 현재가를 모두 캐시에 채운 뒤 순차 전송 ──────────
        try {
            $apiUrl    = env('KIS_API_URL', 'https://openapivts.koreainvestment.com:29443');
            $appKey    = env('KIS_APP_KEY', '');
            $appSecret = env('KIS_APP_SECRET', '');
            $token     = Cache::get('kis_access_token', '');

            if ($token !== '' && $appKey !== '' && $appKey !== 'your_app_key_here') {
                $this->parallelFetcher->fetchAll($validTickers, $apiUrl, $appKey, $appSecret, $token);
            }
        } catch (\Exception $e) {
            $this->error("[pushDataToClient 병렬선조회] 오류: " . $e->getMessage());
        }

        foreach ($validTickers as $ticker) {
            $tf = $tfs[$ticker] ?? '1d';
            try {
                $request = new Request();
                $request->query->set('timeframe', $tf);
                // WS 전송 경로: stale 캐시 허용(동기 KIS fetch 금지 → 케이던스 유지)
                $request->attributes->set('ws_allow_stale', true);

                $response = $this->controller->getStockData($request, $ticker);
                $data     = json_decode($response->getContent(), true);

                // 한 종목씩 받는 즉시 전송 — 전체 루프 완료를 기다리지 않아 카드가 순차로 바로 채워진다.
                $payload = json_encode([
                    'type'   => 'update',
                    'stocks' => [$ticker => $data],
                ]);
                @fwrite($socket, $this->encode($payload));

                // ★ usleep 완전 제거 — 레이트리밋은 KisParallelPriceFetcher 동시성 상한이 담당
            } catch (\Exception $e) {
                $this->error("즉시 전송 실패 [{$ticker} {$tf}]: " . $e->getMessage());
            }
        }
    }

    private function decode($payload)
    {
        if (strlen($payload) < 2) return null;
        
        $firstByte = ord($payload[0]);
        $opcode = $firstByte & 0x0F;
        
        if ($opcode === 8) {
            return ['type' => 'close'];
        }
        
        if ($opcode === 9) {
            return ['type' => 'ping'];
        }
        
        if ($opcode !== 1) {
            return null;
        }

        $secondByte = ord($payload[1]);
        $masked = ($secondByte & 0x80) !== 0;
        $length = $secondByte & 0x7F;

        $offset = 2;
        if ($length === 126) {
            if (strlen($payload) < 4) return null;
            $length = unpack('n', substr($payload, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($payload) < 10) return null;
            $length = unpack('J', substr($payload, 2, 8))[1];
            $offset = 10;
        }

        if ($masked) {
            if (strlen($payload) < $offset + 4) return null;
            $mask = substr($payload, $offset, 4);
            $offset += 4;
            $data = substr($payload, $offset, $length);
            
            $decoded = '';
            for ($i = 0; $i < strlen($data); $i++) {
                $decoded .= $data[$i] ^ $mask[$i % 4];
            }
            return ['type' => 'text', 'data' => $decoded];
        } else {
            $data = substr($payload, $offset, $length);
            return ['type' => 'text', 'data' => $data];
        }
    }

    private function encode($text)
    {
        $b1 = 0x80 | 1; // FIN = 1, Opcode = 1 (Text)
        $length = strlen($text);

        if ($length <= 125) {
            $header = pack('CC', $b1, $length);
        } elseif ($length > 125 && $length < 65536) {
            $header = pack('CCn', $b1, 126, $length);
        } else {
            $header = pack('CCJ', $b1, 127, $length);
        }

        return $header . $text;
    }

    /**
     * @deprecated stale-while-revalidate 패턴으로 대체됨 (2026-06-22).
     *             restoreStaleYahooCache() + refreshYahooCache() 를 사용하라.
     *             이 메서드는 전송 전 Yahoo HTTP 를 블로킹 호출해 사이클을 stall 시킨다.
     *
     * @param array<string, array{ticker:string, timeframe:string}> $uniquePairs
     */
    private function warmupYahooCandles(array $uniquePairs): void
    {
        // deprecated: pushRealtimeData() 에서 더 이상 호출하지 않는다.
        // 아래 로직은 참조용으로 보존하며 삭제하지 않는다.
        foreach ($uniquePairs as $pair) {
            $ticker = $pair['ticker'];
            $tf     = $pair['timeframe'];

            if ($ticker === 'KOSPI200') {
                $cacheKey = "kis_kospi_index_{$tf}";
            } elseif ($ticker === 'KOSPI_NIGHT') {
                $cacheKey = "kospi_night_data_{$tf}";
            } elseif (in_array($ticker, ['NQ=F', '^KS200', 'USDKRW=X'], true)) {
                $cacheKey = "yahoo_stock_data_{$ticker}_{$tf}";
            } else {
                $cacheKey = "yahoo_stock_data_{$ticker}_{$tf}_raw";
            }

            if (Cache::has($cacheKey)) {
                continue;
            }

            try {
                $request = new Request();
                $request->query->set('timeframe', $tf);
                $this->controller->getStockData($request, $ticker);
            } catch (\Exception $e) {
                // 무시
            }
        }
    }

    /**
     * stale-while-revalidate: 전송 직전 호출.
     *
     * Yahoo 캔들 캐시키가 만료된 종목에 대해 {key}_last 백업값을
     * 5초 TTL 로 원본 캐시키에 재주입한다.
     * 이후 전송 루프의 getStockData() → Cache::remember() 가 이 재주입값을 히트해
     * Yahoo HTTP 를 호출하지 않는다.
     *
     * - _last 도 없는 경우(cold-start): 아무것도 하지 않는다.
     *   → refreshYahooCache() 가 전송 후 Yahoo HTTP 로 채운다.
     * - 네트워크 호출 없음, 수 ms 이내 완료.
     *
     * @param array<string, array{ticker:string, timeframe:string}> $uniquePairs
     */
    private function restoreStaleYahooCache(array $uniquePairs): void
    {
        foreach ($uniquePairs as $pair) {
            $ticker = $pair['ticker'];
            $tf     = $pair['timeframe'];

            if ($ticker === 'KOSPI200') {
                $cacheKey = "kis_kospi_index_{$tf}";
            } elseif ($ticker === 'KOSPI_NIGHT') {
                $cacheKey = "kospi_night_data_{$tf}";
            } elseif (in_array($ticker, ['NQ=F', '^KS200', 'USDKRW=X'], true)) {
                $cacheKey = "yahoo_stock_data_{$ticker}_{$tf}";
            } else {
                $cacheKey = "yahoo_stock_data_{$ticker}_{$tf}_raw";
            }

            // 원본 캐시가 살아있으면 복원 불필요
            if (Cache::has($cacheKey)) {
                continue;
            }

            // _last 백업이 있으면 5초 TTL 로 단기 재주입 — 전송이 캐시 히트로 사용
            $lastVal = Cache::get("{$cacheKey}_last");
            if ($lastVal !== null) {
                Cache::put($cacheKey, $lastVal, 5);
            }
            // _last 도 없으면 cold-start → refreshYahooCache() 가 처리
        }
    }

    /**
     * stale-while-revalidate: 전송+KIS 후 호출.
     *
     * _freshness 보조키가 만료된 종목에 대해 Yahoo HTTP 를 실행한다.
     * 성공 시:
     *   - getStockData() 내부 Cache::remember($cacheKey, TTL, ...) 가 원본 캐시를 채운다.
     *   - Cache::forever("{$cacheKey}_last") 에 동일 값을 영구 저장(다음 stale-restore 용).
     *   - Cache::put("{$cacheKey}_freshness", 1, $freshnessTtl) 으로 신선도 마커를 세운다.
     *
     * freshness TTL:
     *   - 지수/환율(NQ=F·^KS200·USDKRW=X·KOSPI200·KOSPI_NIGHT): 15초
     *     → Yahoo 가 ~30초마다 갱신하므로 15초 TTL 로 빠르게 재갱신해 차트를 자주 전진시킨다.
     *   - 개별주식: 90초 (봉 갱신 빈도가 낮고 KIS 현재가 오버레이가 별도 담당)
     *
     * _freshness 가 살아있는 종목은 스킵 → 중복 호출 방지.
     * 5초 재주입으로 원본 캐시가 살아있어도, _freshness 없으면 갱신 대상으로 처리.
     *
     * @param array<string, array{ticker:string, timeframe:string}> $uniquePairs
     */
    private function refreshYahooCache(array $uniquePairs): void
    {
        // 지수/환율 ticker 목록 — 캐시 TTL 이 15초이므로 freshness 도 동일하게 맞춘다.
        $indexTickers = ['NQ=F', '^KS200', 'USDKRW=X', 'KOSPI200', 'KOSPI_NIGHT'];

        foreach ($uniquePairs as $pair) {
            $ticker = $pair['ticker'];
            $tf     = $pair['timeframe'];

            if ($ticker === 'KOSPI200') {
                $cacheKey = "kis_kospi_index_{$tf}";
            } elseif ($ticker === 'KOSPI_NIGHT') {
                $cacheKey = "kospi_night_data_{$tf}";
            } elseif (in_array($ticker, ['NQ=F', '^KS200', 'USDKRW=X'], true)) {
                $cacheKey = "yahoo_stock_data_{$ticker}_{$tf}";
            } else {
                $cacheKey = "yahoo_stock_data_{$ticker}_{$tf}_raw";
            }

            // freshness TTL: 지수/환율 15초, 개별주식 90초
            $freshnessTtl = in_array($ticker, $indexTickers, true) ? 15 : 90;

            // _freshness 마커가 살아있으면 TTL 이내 이미 갱신됨 → 스킵
            $freshnessKey = "{$cacheKey}_freshness";
            if (Cache::has($freshnessKey)) {
                continue;
            }

            // freshness 만료 or cold-start → Yahoo HTTP fetch
            try {
                $request = new Request();
                $request->query->set('timeframe', $tf);
                // getStockData() 내부의 Cache::remember($cacheKey, TTL, ...) 가
                // 원본 캐시를 채운다(5초 재주입 값은 이미 expire됐거나 덮어씌워진다).
                $this->controller->getStockData($request, $ticker);

                // getStockData() 호출 후 원본 캐시에 저장된 값을 _last 에 영구 백업
                $cachedVal = Cache::get($cacheKey);
                if ($cachedVal !== null) {
                    Cache::forever("{$cacheKey}_last", $cachedVal);
                    Cache::put($freshnessKey, 1, $freshnessTtl);
                }
            } catch (\Exception $e) {
                $this->error("[Yahoo갱신] [{$ticker} {$tf}] " . $e->getMessage());
            }
        }
    }

    private function shutdownAllServers()
    {
        $this->info("Shutting down API server (Port 8000)...");
        $this->killProcessByPort(8000);

        $this->info("Shutting down Vite Frontend (Port 5173)...");
        $this->killProcessByPort(5173);

        $this->info("Shutting down WebSocket Agent itself...");
        exit(0);
    }

    private function killProcessByPort($port)
    {
        $output = [];
        exec("netstat -aon | findstr :$port", $output);
        foreach ($output as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 5) {
                $pid = $parts[4];
                if (is_numeric($pid) && $pid > 0) {
                    exec("taskkill /F /PID $pid");
                }
            }
        }
    }
}
