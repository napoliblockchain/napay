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
            self::NOT_ALLOWED => \Yii::t('app', 'Operation not allowed: merchant or account not eligible.'),
            self::CONFLICT => \Yii::t('app', 'Item already exists.'),
            self::INVALID => \Yii::t('app', 'Invalid data.'),
            self::RATE_LIMITED => \Yii::t('app', 'Too many requests: retry later.'),
            default => \Yii::t('app', 'Operation failed. Please retry.'),
        };
    }
}
