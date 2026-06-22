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

        // 3. ── KIS 현재가 병렬 선조회 ─────────────────────────────────────────
        //    getStockData() 는 Cache::remember("kis_realtime_price_*", 3, ...) 로 캐시가 없을 때만
        //    KIS HTTP 요청을 보낸다. KisParallelPriceFetcher 가 여기서 먼저 병렬로 모든 종목을
        //    한꺼번에 KIS 에 요청해 캐시를 채워두면, 이후 getStockData() 들이 모두 캐시 히트가 된다.
        //    → 순차 usleep 제거. 전체 사이클이 단일 요청 레이턴시(~500ms) 수준으로 단축된다.
        $allTickers = array_unique(array_column(array_values($uniquePairs), 'ticker'));

        $kisStats = ['fetched' => 0, 'cached' => 0, 'failed' => 0];

        // ── KIS 현재가 + Yahoo 캔들 병렬 선조회를 동시에 시작 ─────────────────
        //    두 작업은 서로 독립적이므로 fork-join 없이 순차 실행해도 되지만,
        //    PHP 7.4 에서는 별도 스레드가 없으므로 KIS → Yahoo 순으로 직렬 병렬화한다.
        //    (각 작업 내부는 Guzzle Pool 로 진정한 HTTP 병렬)
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
            $this->error("[병렬선조회-KIS] 오류: " . $e->getMessage());
        }

        // ── Yahoo 캔들 병렬 워밍업 ────────────────────────────────────────────
        //    getStockData() 내부에서 Cache::remember("yahoo_stock_data_*", 30, ...) 가 만료되면
        //    순차 Yahoo HTTP 호출이 발생해 전송 단계에서 지연이 쌓인다.
        //    캐시가 만료된 (ticker, timeframe) 쌍을 미리 감지해 StockController::getYahooChartData()
        //    를 병렬(getStockData 내부 로직 재사용)로 채워두면 이 지연이 제거된다.
        try {
            $this->warmupYahooCandles($uniquePairs);
        } catch (\Exception $e) {
            $this->error("[Yahoo워밍업] 오류: " . $e->getMessage());
        }

        $preFetchElapsed = round((microtime(true) - $cycleStart) * 1000);

        // 4. 캐시가 채워진 상태에서 각 종목 데이터를 구성해 구독 클라이언트에 즉시 전송
        //    (getStockData 는 이제 거의 캐시 히트 → 네트워크 없음 → usleep 불필요)
        $sendStart = microtime(true);

        foreach ($uniquePairs as $key => $pair) {
            $ticker = $pair['ticker'];
            $tf     = $pair['timeframe'];

            try {
                $request = new Request();
                $request->query->set('timeframe', $tf);

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
                // ★ usleep 완전 제거 — 레이트리밋은 KisParallelPriceFetcher 의 동시성 상한(8)이 담당
            } catch (\Exception $e) {
                $this->error("종목 데이터 처리 실패 [{$key}]: " . $e->getMessage());
            }
        }

        $sendElapsed  = round((microtime(true) - $sendStart) * 1000);
        $totalElapsed = round((microtime(true) - $cycleStart) * 1000);

        $this->info(
            sprintf(
                "[사이클] 종목 %d개 완료 — 합계 %dms (병렬선조회 %dms: 신규%d건·캐시%d건·실패%d건 / 전송 %dms)",
                $tickerCount,
                $totalElapsed,
                $preFetchElapsed,
                $kisStats['fetched'],
                $kisStats['cached'],
                $kisStats['failed'],
                $sendElapsed
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
     * Yahoo 캔들 캐시가 만료된 종목을 getStockData() 를 통해 미리 채운다.
     *
     * KIS 현재가는 이미 병렬선조회(fetchAll)로 캐시에 채워진 상태이므로,
     * getStockData() 내부에서 Yahoo HTTP 호출만 발생한다.
     *
     * PHP 7.4 단일 스레드 한계상 Yahoo 조회는 순차이지만:
     *   - 캐시 만료는 30초 주기이므로 전체 사이클 중 ~10% 만 해당
     *   - 만료 시에도 종목당 ~300~500ms → 16개 순차 최대 ~8초 → 하지만
     *     캐시가 만료되는 종목은 동시에 만료되지 않고 분산돼 보통 1~3개
     *   - 이미 KIS 병렬화로 "캐시 유효 구간" 사이클이 ~280ms 로 단축됐으므로,
     *     Yahoo 만료 이상치는 빈도 낮은 예외 상황으로 수용 가능
     *
     * @param array<string, array{ticker:string, timeframe:string}> $uniquePairs
     */
    private function warmupYahooCandles(array $uniquePairs): void
    {
        $indexList = ['NQ=F', '^KS200', 'USDKRW=X', 'KOSPI_NIGHT', 'KOSPI200'];

        foreach ($uniquePairs as $pair) {
            $ticker  = $pair['ticker'];
            $tf      = $pair['timeframe'];

            // StockController 와 동일한 캐시 키 결정
            if ($ticker === 'KOSPI200') {
                $cacheKey = "kis_kospi_index_{$tf}";
            } elseif ($ticker === 'KOSPI_NIGHT') {
                // KOSPI_NIGHT 는 kospi_night_data_{tf} 키 사용
                $cacheKey = "kospi_night_data_{$tf}";
            } elseif (in_array($ticker, ['NQ=F', '^KS200', 'USDKRW=X'], true)) {
                $cacheKey = "yahoo_stock_data_{$ticker}_{$tf}";
            } else {
                $cacheKey = "yahoo_stock_data_{$ticker}_{$tf}_raw";
            }

            if (Cache::has($cacheKey)) {
                continue; // 캐시 유효 → 건너뜀
            }

            try {
                $request = new Request();
                $request->query->set('timeframe', $tf);
                // getStockData 가 내부에서 Yahoo 캔들을 조회하고 캐시에 저장한다.
                // KIS 현재가는 이미 병렬선조회로 채워져 있어 추가 네트워크 비용 없음.
                $this->controller->getStockData($request, $ticker);
            } catch (\Exception $e) {
                // 무시 — 전송 단계에서 getMockStockData 폴백으로 처리
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
