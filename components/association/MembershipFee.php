<?php

namespace app\components\association;

use app\models\MemberProfiles;
use app\models\MembershipFees;
use app\models\MembershipPayments;
use app\models\Settings;
use app\models\Users;

/**
 * Quota da pagare online (TIP-020 passo 5b): importo, periodo e quota decisi dal server, mai dal client.
 * Periodo ad anno solare come nel vecchio Napay; si può pagare al massimo l'anno successivo.
 */
final class MembershipFee
{
    /** Impostazioni importate dal vecchio Napay: importo per persona fisica / giuridica */
    private const AMOUNT_SETTINGS = [0 => 'quota_iscrizione_socio', 1 => 'quota_iscrizione_socioGiuridico'];

    /**
     * @return array{payable: bool, reason: ?string, fee: ?MembershipFees, amount: ?string, year: ?int,
     *               starts_on: ?string, expires_on: ?string, paid_until: ?string}
     */
    public static function quote(Users $user, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $paidUntil = MembershipPayments::find()->where(['user_id' => $user->id, 'status' => 'paid'])->max('expires_on');
        $result = ['payable' => false, 'reason' => null, 'fee' => null, 'amount' => null, 'year' => null,
            'starts_on' => null, 'expires_on' => null, 'paid_until' => $paidUntil ?: null];

        $fee = MembershipFees::find()->where(['>=', 'duration', 1])->orderBy('id')->one();
        if ($fee === null) return ['reason' => 'no_fee'] + $result;

        $current = (int) substr($today, 0, 4);
        $year = $paidUntil && $paidUntil >= $today ? (int) substr($paidUntil, 0, 4) + 1 : $current;
        if ($year > $current + 1) return ['reason' => 'already_paid', 'fee' => $fee] + $result;

        $corporate = (int) (MemberProfiles::find()->select('is_corporate')->where(['user_id' => $user->id])->scalar() ?: 0);
        $amount = self::settingAmount(self::AMOUNT_SETTINGS[$corporate === 1 ? 1 : 0]) ?? $fee->amount;
        $amount = number_format((float) $amount, 2, '.', '');
        if ((float) $amount <= 0) return ['reason' => 'no_fee', 'fee' => $fee] + $result;

        return ['payable' => true, 'reason' => null, 'fee' => $fee, 'amount' => $amount, 'year' => $year,
            'starts_on' => $year . '-01-01', 'expires_on' => ($year + (int) $fee->duration - 1) . '-12-31',
            'paid_until' => $paidUntil ?: null];
    }

    private static function settingAmount(string $code): ?string
    {
        $value = Settings::find()->select('value')->where(['code' => $code])->scalar();
        return is_string($value) && preg_match('/^\d{1,6}([.,]\d{1,2})?$/D', trim($value)) ? str_replace(',', '.', trim($value)) : null;
    }
}
