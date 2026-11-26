<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;
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

    /** Le versioni pubblicate non cambiano più: solo le bozze si salvano. */
    public function beforeSave($insert)
    {
        if (!$insert && $this->isPublished()) return false;
        if (!self::validType($this->type) || !in_array($this->status, [self::DRAFT, self::PUBLISHED], true)) return false;
        $now = time();
        if ($insert) $this->created_at = $now;
        $this->updated_at = max($now, (int) $this->created_at);
        return parent::beforeSave($insert);
    }

    public function beforeDelete()
    {
        return !$this->isPublished() && parent::beforeDelete();
    }

    /**
     * Nuova bozza del tipo: numero di versione successivo all'ultimo (bozze comprese), testo dato.
     * Una sola bozza aperta per tipo: se esiste, la restituisce invariata.
     */
    public static function newDraft(string $type, string $title, string $body, ?int $userId): self
    {
        if (!self::validType($type)) throw new \InvalidArgumentException('invalid_type');
        $open = self::findOne(['type' => $type, 'status' => self::DRAFT]);
        if ($open !== null) return $open;
        $next = (int) self::find()->where(['type' => $type])->max('version') + 1;
        $draft = new self(['type' => $type, 'version' => $next, 'title' => $title, 'body' => $body, 'status' => self::DRAFT, 'created_by' => $userId]);
        if (!$draft->save()) throw new \RuntimeException('draft_not_saved');
        return $draft;
    }

    /**
     * Pubblica una bozza: rifiutata se contiene ancora segnaposto dei modelli o testo vuoto.
     * L'UPDATE è condizionato sullo stato bozza: due pubblicazioni concorrenti non producono due versioni in vigore.
     */
    public function publish(int $userId): bool
    {
        if ($this->isPublished() || trim($this->body) === '' || str_contains($this->body, self::PLACEHOLDER) || str_contains($this->title, self::PLACEHOLDER)) {
            return false;
        }
        $now = time();
        $rows = self::updateAll(['status' => self::PUBLISHED, 'published_by' => $userId, 'published_at' => $now, 'updated_at' => $now],
            ['id' => $this->id, 'status' => self::DRAFT]);
        if ($rows !== 1) return false;
        $this->refresh();
        return true;
    }
}
