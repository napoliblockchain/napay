<?php
namespace app\components\pos\management;

/** C4: esito negativo con codice sicuro da mostrare; mai dettagli di DB o input. */
final class ManagementException extends \RuntimeException
{
    public const NOT_FOUND = 'not_found';
    public const NOT_ALLOWED = 'not_allowed';
    public const CONFLICT = 'conflict';
    public const INVALID = 'invalid';
    public const FAILED = 'failed';
    public const RATE_LIMITED = 'rate_limited';

    public function userMessage(): string
    {
        return match ($this->getMessage()) {
            self::NOT_ALLOWED => \Yii::t('app', 'Operazione non consentita: merchant o account non idonei.'),
            self::CONFLICT => \Yii::t('app', 'Elemento già presente.'),
            self::INVALID => \Yii::t('app', 'Dati non validi.'),
            self::RATE_LIMITED => \Yii::t('app', 'Troppe richieste: riprova più tardi.'),
            default => \Yii::t('app', 'Operazione non riuscita. Riprova.'),
        };
    }
}
