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
 * @property mixed $paypal_order_id
 * @property mixed $status
 */
class MembershipPayments extends \yii\db\ActiveRecord
{
    public function rules()
    {
        return [
            [['user_id', 'fee_id', 'payment_method_id', 'amount', 'registered_on', 'starts_on', 'expires_on', 'status'], 'required'],
            [['user_id'], 'exist', 'targetClass' => Users::class, 'targetAttribute' => 'id'],
            [['fee_id'], 'exist', 'targetClass' => MembershipFees::class, 'targetAttribute' => 'id'],
            [['payment_method_id'], 'exist', 'targetClass' => PaymentMethods::class, 'targetAttribute' => 'id'],
            [['amount'], 'number', 'min' => 0, 'max' => 99999999.99],
            [['registered_on', 'starts_on', 'expires_on'], 'date', 'format' => 'php:Y-m-d'],
            [['expires_on'], 'compare', 'compareAttribute' => 'starts_on', 'operator' => '>=', 'type' => 'string'],
            [['status'], 'in', 'range' => array_keys(self::STATUS_LABELS)],
            [['btcpay_invoice_id', 'paypal_txn_id'], 'filter', 'filter' => static fn($v) => is_string($v) && trim($v) !== '' ? trim($v) : null],
            [['btcpay_invoice_id', 'paypal_txn_id'], 'string', 'max' => 60],
        ];
    }

    public function attributeLabels()
    {
        return [
            'user_id' => Yii::t('app', 'Socio'),
            'fee_id' => Yii::t('app', 'Quota'),
            'payment_method_id' => Yii::t('app', 'Metodo'),
            'amount' => Yii::t('app', 'Importo'),
            'registered_on' => Yii::t('app', 'Data'),
            'starts_on' => Yii::t('app', 'Inizio'),
            'expires_on' => Yii::t('app', 'Scadenza'),
            'status' => Yii::t('app', 'Stato'),
            'paypal_txn_id' => Yii::t('app', 'PayPal ID'),
            'btcpay_invoice_id' => Yii::t('app', 'Transazione'),
        ];
    }

    public static function tableName()
    {
        return 'membership_payments';
    }

    public const STATUS_LABELS = ['paid' => 'Pagata', 'pending' => 'In corso', 'expired' => 'Scaduta', 'invalid' => 'Non valida'];

    /**
     * Salvataggio con numero ricevuta progressivo per anno, assegnato solo ai pagamenti pagati.
     * Lock per anno + transazione: due salvataggi concorrenti non ottengono lo stesso numero.
     * $onlyIfPending: conferma di un pagamento online, che non deve essere già stato confermato.
     */
    public function saveWithReceipt(bool $onlyIfPending = false): bool
    {
        $db = static::getDb();
        $year = (int) substr((string) $this->registered_on, 0, 4);
        $lock = 'napay-receipt-' . $year;
        if ($db->createCommand('SELECT GET_LOCK(:l, 10)', [':l' => $lock])->queryScalar() != 1) return false;
        $transaction = $db->beginTransaction();
        try {
            if ($onlyIfPending && !$this->isNewRecord
                && $db->createCommand('SELECT status FROM membership_payments WHERE id=:id FOR UPDATE', [':id' => $this->id])->queryScalar() !== 'pending') {
                $transaction->rollBack();
                return false;
            }
            if ($this->status === 'paid' && !$this->receipt_number) {
                $this->year = $year;
                $this->receipt_number = 1 + (int) static::find()->where(['year' => $year])->max('receipt_number');
            }
            $ok = $this->save(false);
            $ok ? $transaction->commit() : $transaction->rollBack();
            return $ok;
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        } finally {
            $db->createCommand('SELECT RELEASE_LOCK(:l)', [':l' => $lock])->execute();
        }
    }

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
