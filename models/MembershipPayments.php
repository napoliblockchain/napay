<?php

namespace app\models;

use Yii;

/**
 * Pagamenti delle quote associative (TIP-020).
 * Scritture solo dall'import legacy finché è attivo (components/legacy/LegacyImporter).
 *
 * @property mixed $id
 * @property mixed $legacy_id
 * @property mixed $user_id
 * @property mixed $fee_id
 * @property mixed $payment_method_id
 * @property mixed $year
 * @property mixed $receipt_number
 * @property mixed $amount
 * @property mixed $registered_on
 * @property mixed $starts_on
 * @property mixed $expires_on
 * @property mixed $btcpay_invoice_id
 * @property mixed $paypal_txn_id
 * @property mixed $status
 */
class MembershipPayments extends \yii\db\ActiveRecord
{
    public static function tableName()
    {
        return 'membership_payments';
    }

    public const STATUS_LABELS = ['paid' => 'Pagata', 'pending' => 'In corso', 'expired' => 'Scaduta', 'invalid' => 'Non valida'];

    public function getUser()
    {
        return $this->hasOne(Users::class, ['id' => 'user_id']);
    }

    public function getFee()
    {
        return $this->hasOne(MembershipFees::class, ['id' => 'fee_id']);
    }

    public function getMethod()
    {
        return $this->hasOne(PaymentMethods::class, ['id' => 'payment_method_id']);
    }

    public function getProfile()
    {
        return $this->hasOne(MemberProfiles::class, ['user_id' => 'user_id']);
    }

    public function statusLabel(): string
    {
        return Yii::t('app', self::STATUS_LABELS[$this->status] ?? $this->status);
    }

    /** Numero ricevuta "progressivo/anno" come nel vecchio sistema */
    public function receiptLabel(): string
    {
        return $this->receipt_number && $this->year ? $this->receipt_number . '/' . $this->year : '-';
    }
}
