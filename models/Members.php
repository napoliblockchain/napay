<?php

namespace app\models;

use Yii;
use yii\db\Expression;

/**
 * Utenti visti come soci dell'associazione (TIP-020): profilo, carica e scadenza dell'ultima quota pagata.
 * Stato come nel vecchio Napay: in scadenza entro 45 giorni, scaduti fino a 365 giorni, amministratori esenti.
 *
 * @property string|null $last_expiry
 * @property MemberProfiles|null $profile
 */
class Members extends Users
{
    public const EXPIRING_DAYS = 45;
    public const EXPIRED_DAYS = 365;
    public const STATUS_LABELS = [
        'active' => 'In regola', 'expiring' => 'In scadenza', 'expired' => 'Scaduto',
        'lapsed' => 'Decaduto', 'never' => 'Mai pagato', 'exempt' => 'Esente',
    ];

    public $last_expiry;

    /** Scadenza dell'ultima quota pagata, alias utente "u" */
    public static function lastExpirySql(): Expression
    {
        return new Expression("(SELECT MAX(mp.expires_on) FROM membership_payments mp WHERE mp.user_id=u.id AND mp.status='paid')");
    }

    public function getProfile()
    {
        return $this->hasOne(MemberProfiles::class, ['user_id' => 'id']);
    }

    public function getPayments()
    {
        return $this->hasMany(MembershipPayments::class, ['user_id' => 'id']);
    }

    public function memberStatus(?string $today = null): string
    {
        if ((int) ($this->privilege->level ?? 0) >= 50) return 'exempt';
        if (!$this->last_expiry) return 'never';
        $today = $today ?? date('Y-m-d');
        if ($this->last_expiry < date('Y-m-d', strtotime($today . ' -' . self::EXPIRED_DAYS . ' days'))) return 'lapsed';
        if ($this->last_expiry < $today) return 'expired';
        if ($this->last_expiry <= date('Y-m-d', strtotime($today . ' +' . self::EXPIRING_DAYS . ' days'))) return 'expiring';
        return 'active';
    }

    public function memberStatusLabel(): string
    {
        return Yii::t('app', self::STATUS_LABELS[$this->memberStatus()]);
    }
}
