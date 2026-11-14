<?php
namespace app\components\pos\tts;

use yii\base\InvalidConfigException;

/**
 * SIP-021 fase 3: lettura della chain TTS via JSON-RPC (sola lettura, nessuna chiave). Configurazione da ambiente:
 * TTS_RPC_URL, TTS_CHAIN_ID, TTS_TOKEN_ADDRESS; tutte vuote = funzione spenta, parziali o non valide = errore.
 * Ogni errore di rete o di protocollo diventa TtsChainException senza dettagli.
 */
final class TtsChain
{
    /** keccak256("Transfer(address,address,uint256)") */
    public const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
    /** Intervallo massimo di eth_getLogs accettato da Besu per default. */
    private const LOG_RANGE = 5000;
    private const MAX_RESPONSE = 1048576;
    private bool $verified = false;
    private int $id = 0;

    private function __construct(private readonly string $url, private readonly int $chainId, public readonly string $token)
    {
    }

    public static function fromEnv(): ?self
    {
        $url = (string) env('TTS_RPC_URL', '');
        $chain = (string) env('TTS_CHAIN_ID', '');
        $token = (string) env('TTS_TOKEN_ADDRESS', '');
        if ($url === '' && $chain === '' && $token === '') return null;
        $parts = parse_url($url);
        $address = TtsAddress::normalize($token);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/^[1-9][0-9]{0,9}$/D', $chain) !== 1 || $address === null) {
            throw new InvalidConfigException('invalid_tts_chain_config');
        }
        return new self($url, (int) $chain, $address);
    }

    public function blockNumber(): int
    {
        return self::quantity($this->call('eth_blockNumber', []));
    }

    public function blockTimestamp(int $block): int
    {
        $result = $this->call('eth_getBlockByNumber', [self::hex($block), false]);
        if (!is_array($result)) throw new TtsChainException();
        return self::quantity($result['timestamp'] ?? null);
    }

    /**
     * Eventi Transfer del token verso $to, dal blocco $from all'ultimo, a blocchi di LOG_RANGE.
     * $stop(blocco finale del tratto) = true interrompe dopo il tratto corrente.
     * @return list<array{tx:string, block:int, value:string}> value = 64 esadecimali minuscoli
     */
    public function transfersTo(string $to, int $from, ?callable $stop = null): array
    {
        $latest = $this->blockNumber();
        $out = [];
        for ($start = $from; $start <= $latest; $start += self::LOG_RANGE) {
            $end = min($latest, $start + self::LOG_RANGE - 1);
            $logs = $this->call('eth_getLogs', [['address' => $this->token, 'fromBlock' => self::hex($start), 'toBlock' => self::hex($end),
                'topics' => [self::TRANSFER_TOPIC, null, '0x' . TtsAddress::word($to)]]]);
            if (!is_array($logs)) throw new TtsChainException();
            foreach ($logs as $log) {
                if (!is_array($log) || ($log['removed'] ?? false) !== false || strtolower((string) ($log['address'] ?? '')) !== strtolower($this->token)
                    || !is_string($log['transactionHash'] ?? null) || preg_match('/^0x[0-9a-f]{64}$/D', $log['transactionHash']) !== 1
                    || !is_string($log['data'] ?? null) || preg_match('/^0x[0-9a-f]{64}$/D', $log['data']) !== 1
                    || ($log['topics'][0] ?? null) !== self::TRANSFER_TOPIC || strtolower((string) ($log['topics'][2] ?? '')) !== '0x' . TtsAddress::word($to)) {
                    throw new TtsChainException();
                }
                $out[] = ['tx' => $log['transactionHash'], 'block' => self::quantity($log['blockNumber'] ?? null), 'value' => substr($log['data'], 2)];
            }
            if ($stop !== null && $stop($end)) break;
        }
        return $out;
    }

    /** Dati della chiamata (input) di una transazione minata, esadecimali minuscoli senza 0x. */
    public function transactionInput(string $hash): string
    {
        $tx = $this->call('eth_getTransactionByHash', [$hash]);
        if (!is_array($tx) || !is_string($tx['input'] ?? null) || preg_match('/^0x((?:[0-9a-f]{2})*)$/D', strtolower($tx['input']), $m) !== 1
            || strtolower((string) ($tx['to'] ?? '')) !== strtolower($this->token) || ($tx['blockNumber'] ?? null) === null) {
            throw new TtsChainException();
        }
        return $m[1];
    }

    private function call(string $method, array $params)
    {
        if (!$this->verified) {
            $this->verified = true;
            if ($this->request('eth_chainId', []) !== self::hex($this->chainId)) {
                $this->verified = false;
                throw new TtsChainException();
            }
        }
        return $this->request($method, $params);
    }

    private function request(string $method, array $params)
    {
        $id = ++$this->id;
        $body = json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR);
        $curl = curl_init($this->url);
        $received = '';
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$received): int {
                if (strlen($received) + strlen($chunk) > self::MAX_RESPONSE) return 0;
                $received .= $chunk;
                return strlen($chunk);
            }]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($ok !== true || $status !== 200) throw new TtsChainException();
        try {
            $response = json_decode($received, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new TtsChainException();
        }
        if (!is_array($response) || ($response['id'] ?? null) !== $id || isset($response['error']) || !array_key_exists('result', $response)) throw new TtsChainException();
        return $response['result'];
    }

    private static function hex(int $value): string
    {
        return '0x' . dechex($value);
    }

    private static function quantity($value): int
    {
        if (!is_string($value) || preg_match('/^0x(?:0|[1-9a-f][0-9a-f]{0,14})$/D', $value) !== 1) throw new TtsChainException();
        return hexdec(substr($value, 2));
    }
}
