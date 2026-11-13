<?php
namespace app\components\btcpay\provisioning;

use app\components\db\DatabaseSecret;

/** Worker-only private inputs. No generation, persistence, logging or implicit key rotation. */
final class WorkerSecrets
{
    private readonly \SensitiveParameterValue $provisioner;
    private readonly \SensitiveParameterValue $ring;

    private function __construct(#[\SensitiveParameter] string $provisioner, #[\SensitiveParameter] array $ring, public readonly string $current)
    {
        $this->provisioner = new \SensitiveParameterValue($provisioner);
        $this->ring = new \SensitiveParameterValue($ring);
    }

    public static function fromFiles(string $provisionerFile, string $keyringFile): self
    {
        try {
            if ($provisionerFile === $keyringFile) throw new \UnexpectedValueException();
            $provisioner = DatabaseSecret::read($provisionerFile);
            if (preg_match('/^[\x21-\x7e]{32,256}$/D', $provisioner) !== 1) throw new \UnexpectedValueException();
            if (preg_match('#^/run/secrets/[A-Za-z0-9_-]+$#D', $keyringFile) !== 1) throw new \UnexpectedValueException();
            $stat = @lstat($keyringFile);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 077) !== 0
                || $stat['size'] < 1 || $stat['size'] > 8192 || !is_readable($keyringFile)) throw new \UnexpectedValueException();
            $raw = @file_get_contents($keyringFile, false, null, 0, 8193);
            if (!is_string($raw) || strlen($raw) > 8192) throw new \UnexpectedValueException();
            return self::parse($provisioner, $raw);
        } catch (\Throwable $error) {
            // Discard internal traces: JSON/native function arguments can contain the whole keyring.
            throw new \RuntimeException('invalid_btcpay_worker_secrets');
        }
    }

    public function provisionerKey(): string { return $this->provisioner->getValue(); }
    /** @return array<string,string> raw 32-byte KEKs for CredentialEnvelope only */
    public function keyring(): array { return $this->ring->getValue(); }

    private static function parse(#[\SensitiveParameter] string $provisioner, #[\SensitiveParameter] string $raw): self
    {
        $json = json_decode($raw, false, 8, JSON_THROW_ON_ERROR);
        if (!$json instanceof \stdClass || count(get_object_vars($json)) !== 2 || !isset($json->current, $json->keys)
            || !is_string($json->current) || !$json->keys instanceof \stdClass) throw new \UnexpectedValueException();
        $keys = get_object_vars($json->keys);
        if (count($keys) < 1 || count($keys) > 16 || !array_key_exists($json->current, $keys)) throw new \UnexpectedValueException();
        $ring = [];
        foreach ($keys as $version => $encoded) {
            if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', (string) $version) !== 1 || !is_string($encoded)) throw new \UnexpectedValueException();
            $decoded = base64_decode($encoded, true);
            if (!is_string($decoded) || strlen($decoded) !== 32 || base64_encode($decoded) !== $encoded) throw new \UnexpectedValueException();
            $ring[$version] = $decoded;
        }
        return new self($provisioner, $ring, $json->current);
    }
}
