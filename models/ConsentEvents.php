<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * SIP-040 A2: evento di consenso, in sola aggiunta (prova art. 7.1 GDPR). Mai modificato né cancellato dal codice;
 * l'utente web ha solo SELECT e INSERT. Utente eliminato -> user_id NULL (anonimizzazione).
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $item statute | privacy | terms | marketing
 * @property string $action given | refused | withdrawn
 * @property int|null $document_id
 * @property string $source signup | profile | reaccept | backfill
 * @property int $created_at
 */
class ConsentEvents extends ActiveRecord
{
    public const ITEMS = ['statute', 'privacy', 'terms', 'marketing'];
    public const ACTIONS = ['given', 'refused', 'withdrawn'];
    public const SOURCES = ['signup', 'profile', 'reaccept', 'backfill'];

    public static function tableName()
    {
        return '{{%consent_events}}';
    }

    /** Registra un evento; da chiamare nella stessa transazione del cambio di stato in user_consensus. */
    public static function record(int $userId, string $item, string $action, ?int $documentId, string $source): void
    {
        if (!in_array($item, self::ITEMS, true) || !in_array($action, self::ACTIONS, true) || !in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException('invalid_consent_event');
        }
        $event = new self(['user_id' => $userId, 'item' => $item, 'action' => $action, 'document_id' => $documentId, 'source' => $source, 'created_at' => time()]);
        if (!$event->save(false)) throw new \RuntimeException('consent_event_not_saved');
    }

    /** Storico di un utente, dal più recente. */
    public static function history(int $userId): array
    {
        return self::find()->where(['user_id' => $userId])->orderBy(['id' => SORT_DESC])->limit(200)->all();
    }

    public function getDocument()
    {
        return $this->hasOne(LegalDocuments::class, ['id' => 'document_id']);
    }

    /** Sola aggiunta: nessuna modifica. */
    public function beforeSave($insert)
    {
        return $insert && parent::beforeSave($insert);
    }

    /** Sola aggiunta: nessuna cancellazione. */
    public function beforeDelete()
    {
        return false;
    }

    public static function itemLabel(string $item): string
    {
        return match ($item) {
            'statute' => Yii::t('app', 'Statute'),
            'privacy' => Yii::t('app', 'Privacy notice'),
            'terms' => Yii::t('app', 'Terms of use'),
            'marketing' => Yii::t('app', 'Marketing communications'),
            default => $item,
        };
    }

    public static function actionLabel(string $item, string $action): string
    {
        if ($item === 'privacy' && $action === 'given') return Yii::t('app', 'Read');
        return match ($action) {
            'given' => $item === 'marketing' ? Yii::t('app', 'Consent given') : Yii::t('app', 'Accepted'),
            'refused' => Yii::t('app', 'Not given'),
            'withdrawn' => Yii::t('app', 'Withdrawn'),
            default => $action,
        };
    }

    public static function sourceLabel(string $source): string
    {
        return match ($source) {
            'signup' => Yii::t('app', 'Registration'),
            'profile' => Yii::t('app', 'Profile'),
            'reaccept' => Yii::t('app', 'Updated terms'),
            'backfill' => Yii::t('app', 'Recorded before the history'),
            default => $source,
        };
    }
}
