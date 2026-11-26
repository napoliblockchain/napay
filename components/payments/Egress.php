<?php

namespace app\components\payments;

/**
 * Proxy di uscita verso i gateway di pagamento (PAYMENT_PROXY, es. http://egress:3128).
 * Nella sandbox locale il web non ha internet: passa solo dal proxy con allowlist (docker/egress).
 * Valore assente = connessione diretta; valore non valido = nessuna connessione (fail secure).
 */
final class Egress
{
    /** @return string|false|null URL del proxy, null se non configurato, false se non valido */
    public static function proxy()
    {
        $value = trim((string) getenv('PAYMENT_PROXY'));
        if ($value === '') return null;
        return preg_match('#^http://[a-z0-9][a-z0-9.-]{0,62}:\d{2,5}$#D', $value) ? $value : false;
    }
}
