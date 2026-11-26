<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;
use yii\db\IntegrityException;
use yii\helpers\HtmlPurifier;
use yii\helpers\Markdown;

/**
 * SIP-040: documento legale versionato (informativa privacy, condizioni di utilizzo, cookie policy).
 * Bozze modificabili ed eliminabili; versioni pubblicate immutabili. In vigore = versione pubblicata più alta del tipo.
 * Testo in Markdown, mostrato solo dopo il filtro HTML con allowlist (nessun HTML, script o stile dall'admin).
 *
 * @property int $id
 * @property string $type privacy | terms | cookies
 * @property int $version
 * @property string $title
 * @property string $body
 * @property string $status draft | published
 * @property int|null $created_by
 * @property int $created_at
 * @property int $updated_at
 * @property int|null $published_by
 * @property int|null $published_at
 */
class LegalDocuments extends ActiveRecord
{
    public const TYPES = ['privacy', 'terms', 'cookies'];
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';
    /** segnaposto dei modelli standard: un testo che lo contiene non si pubblica */
    public const PLACEHOLDER = '[DA APPROVARE';
    public const MAX_BODY = 200000;
    private const DRAFT_ATTEMPTS = 3;
    private const DRAFT_LOCK_SECONDS = 5;

    public static function tableName()
    {
        return '{{%legal_documents}}';
    }

    public function rules()
    {
        return [
            [['title', 'body'], 'required'],
            [['title'], 'string', 'max' => 200],
            [['body'], 'string', 'max' => self::MAX_BODY],
            [['title', 'body'], 'filter', 'filter' => static fn($v) => is_string($v) ? trim(str_replace("\r\n", "\n", $v)) : $v],
        ];
    }

    public function attributeLabels()
    {
        return ['title' => Yii::t('app', 'Title'), 'body' => Yii::t('app', 'Text')];
    }

    /** Etichetta del tipo per le pagine e il backoffice. */
    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'privacy' => Yii::t('app', 'Privacy notice'),
            'terms' => Yii::t('app', 'Terms of use'),
            'cookies' => Yii::t('app', 'Cookie policy'),
            default => $type,
        };
    }

    public static function validType($type): bool
    {
        return is_string($type) && in_array($type, self::TYPES, true);
    }

    /** Versione pubblicata in vigore del tipo, null se mai pubblicata. */
    public static function current(string $type): ?self
    {
        return self::find()->where(['type' => $type, 'status' => self::PUBLISHED])->orderBy(['version' => SORT_DESC])->limit(1)->one();
    }

    /** Versione pubblicata richiesta (mai una bozza). */
    public static function published(string $type, int $version): ?self
    {
        return self::findOne(['type' => $type, 'version' => $version, 'status' => self::PUBLISHED]);
    }

    public function isPublished(): bool
    {
        return $this->getOldAttribute('status') === self::PUBLISHED;
    }

    /** Markdown -> HTML filtrato: solo titoli, paragrafi, elenchi, enfasi, tabelle e link http(s)/mailto. */
    public static function render(string $markdown): string
    {
        $html = Markdown::process($markdown, 'gfm');
        return HtmlPurifier::process($html, static function ($config) {
            $config->set('HTML.Allowed', 'h2,h3,h4,p,br,ul,ol,li,strong,em,blockquote,code,table,thead,tbody,tr,th,td,a[href]');
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
            $config->set('HTML.TargetBlank', true);
            $config->set('HTML.TargetNoopener', true);
            $config->set('HTML.TargetNoreferrer', true);
            $config->set('Cache.DefinitionImpl', null);
        });
    }

    /** Modifiche e cancellazioni in transazione: il controllo dello stato avviene sul record bloccato. */
    public function transactions()
    {
        return [self::SCENARIO_DEFAULT => self::OP_UPDATE | self::OP_DELETE];
    }

    /** Stato attuale nel database con il record bloccato (null se non esiste più). */
    private function lockedStatus(): ?string
    {
        $status = self::getDb()->createCommand('SELECT status FROM ' . self::tableName() . ' WHERE id=:id FOR UPDATE', [':id' => (int) $this->id])->queryScalar();
        return $status === false ? null : (string) $status;
    }

    /** Impronta del testo mostrato all'admin: si pubblica solo se il database contiene esattamente questo testo. */
    public static function snapshot(string $title, string $body): string
    {
        return hash('sha256', $title . "\0" . $body);
    }

    /** Le versioni pubblicate non cambiano più: solo le bozze si salvano, verificato sullo stato reale nel database. */
    public function beforeSave($insert)
    {
        if (!$insert && ($this->isPublished() || $this->lockedStatus() !== self::DRAFT)) return false;
        if (!self::validType($this->type) || !in_array($this->status, [self::DRAFT, self::PUBLISHED], true)) return false;
        $now = time();
        if ($insert) $this->created_at = $now;
        $this->updated_at = max($now, (int) $this->created_at);
        return parent::beforeSave($insert);
    }

    public function beforeDelete()
    {
        return !$this->isPublished() && $this->lockedStatus() === self::DRAFT && parent::beforeDelete();
    }

    /**
     * Nuova bozza del tipo: numero di versione successivo all'ultimo (bozze comprese), testo dato.
     * Una sola bozza aperta per tipo: se esiste, la restituisce invariata.
     */
    public static function newDraft(string $type, string $title, string $body, ?int $userId): self
    {
        if (!self::validType($type)) throw new \InvalidArgumentException('invalid_type');
        $db = self::getDb();
        // lock con nome per tipo, valido anche senza righe (RV3): le richieste concorrenti si mettono in fila
        $lock = 'napay-legal-draft-' . $type;
        for ($attempt = 1; $attempt <= self::DRAFT_ATTEMPTS; $attempt++) {
            if ((int) $db->createCommand('SELECT GET_LOCK(:l, :t)', [':l' => $lock, ':t' => self::DRAFT_LOCK_SECONDS])->queryScalar() !== 1) {
                throw new \RuntimeException('draft_busy');
            }
            try {
                return $db->transaction(static function () use ($type, $title, $body, $userId): self {
                    $open = self::findOne(['type' => $type, 'status' => self::DRAFT]);
                    if ($open !== null) return $open;
                    $draft = new static(['type' => $type, 'version' => static::nextVersion($type), 'title' => $title, 'body' => $body,
                        'status' => self::DRAFT, 'created_by' => $userId]);
                    if (!$draft->save()) throw new \RuntimeException('draft_not_saved');
                    return $draft;
                });
            } catch (IntegrityException $error) {
                // versione già presa: si usa la bozza aperta, mai un errore 500
                $open = self::findOne(['type' => $type, 'status' => self::DRAFT]);
                if ($open !== null) return $open;
                throw new \RuntimeException('draft_busy');
            } catch (\yii\db\Exception $error) {
                // solo deadlock e lock timeout si ritentano, in una nuova transazione; gli altri errori non si mascherano
                if (!in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213], true)) throw $error;
                if ($attempt === self::DRAFT_ATTEMPTS) throw new \RuntimeException('draft_busy');
            } finally {
                $db->createCommand('SELECT RELEASE_LOCK(:l)', [':l' => $lock])->queryScalar();
            }
        }
        throw new \RuntimeException('draft_busy');
    }

    /** Numero della prossima versione del tipo (bozze comprese). */
    protected static function nextVersion(string $type): int
    {
        return (int) self::find()->where(['type' => $type])->max('version') + 1;
    }

    /**
     * Pubblica una bozza solo se il testo nel database è quello mostrato all'admin ($snapshot) e non contiene segnaposto né
     * è vuoto. Controlli e cambio di stato sul record bloccato, nella stessa transazione: una modifica o una pubblicazione
     * concorrente fa rifiutare la richiesta (l'admin ricontrolla), mai pubblicare un testo diverso da quello visto.
     */
    public function publish(int $userId, string $snapshot): bool
    {
        $db = self::getDb();
        $done = $db->transaction(function () use ($db, $userId, $snapshot): bool {
            $row = $db->createCommand('SELECT status, title, body FROM ' . self::tableName() . ' WHERE id=:id FOR UPDATE', [':id' => (int) $this->id])->queryOne();
            if ($row === false || $row['status'] !== self::DRAFT || !hash_equals(self::snapshot((string) $row['title'], (string) $row['body']), $snapshot)) return false;
            if (trim((string) $row['body']) === '' || str_contains((string) $row['body'], self::PLACEHOLDER) || str_contains((string) $row['title'], self::PLACEHOLDER)) return false;
            $now = time();
            return $db->createCommand()->update(self::tableName(), ['status' => self::PUBLISHED, 'published_by' => $userId, 'published_at' => $now, 'updated_at' => $now],
                ['id' => (int) $this->id, 'status' => self::DRAFT])->execute() === 1;
        });
        if ($done) $this->refresh();
        return $done;
    }
}
