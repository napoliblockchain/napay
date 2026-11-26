<?php
namespace app\components\pos\tts;

/** SIP-021 fase 3: chain non raggiungibile o risposta non valida; nessun dettaglio di rete o RPC. */
final class TtsChainException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('chain_unavailable');
    }
}
