<?php

namespace app\components\merchant;

use Yii;
use app\components\btcpay\StoreSettingsPayload;
use app\components\btcpay\provisioning\ProvisioningOutbox;
use yii\db\Connection;
use yii\helpers\Url;

/**
 * SIP-033: logo del merchant sulla pagina di pagamento BTCPay di tutti i suoi negozi (come nel vecchio Napay).
 * - Solo PNG o JPEG veri (firma e tipo rilevato), al massimo 2 MB e 3000x3000 pixel (memoria della decodifica), sempre decodificati e ricodificati
 *   in PNG da GD: niente metadati, niente contenuti nascosti, mai il file originale. Lato massimo 600x200, mai ingrandito.
 * - Salvato nel DB (un logo per merchant), servito da un indirizzo pubblico col suo SHA-256: BTCPay lo mostra al cliente.
 * - Caricato o rimosso: logo dei negozi attivi aggiornato e store_update accodato nella stessa transazione (worker).
 */
final class MerchantLogo
{
    public const MAX_BYTES = 2097152;
    public const MAX_SOURCE_SIDE = 3000;
    public const MIN_SIDE = 16;
    public const MAX_WIDTH = 600;
    public const MAX_HEIGHT = 200;
    private const TYPES = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg'];

    /**
     * @return array{content: string, sha256: string, width: int, height: int}|string PNG normalizzato o codice d'errore
     *         (too_large, not_image, too_small, too_big_dimensions, unreadable)
     */
    public static function normalize(string $path): array|string
    {
        if (!is_file($path) || !is_readable($path)) return 'unreadable';
        $size = filesize($path);
        if ($size === false || $size < 8) return 'not_image';
        if ($size > self::MAX_BYTES) return 'too_large';
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $info = @getimagesize($path);
        if (!is_array($info) || !isset(self::TYPES[$info[2]]) || self::TYPES[$info[2]] !== $mime) return 'not_image';
        [$width, $height] = [(int) $info[0], (int) $info[1]];
        if ($width < self::MIN_SIDE || $height < self::MIN_SIDE) return 'too_small';
        if ($width > self::MAX_SOURCE_SIDE || $height > self::MAX_SOURCE_SIDE) return 'too_big_dimensions';
        $source = $info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
        if ($source === false) return 'not_image';
        try {
            $scale = min(1, self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height);
            $w = max(1, (int) round($width * $scale)); $h = max(1, (int) round($height * $scale));
            $target = imagecreatetruecolor($w, $h);
            if ($target === false) return 'unreadable';
            try {
                imagealphablending($target, false);
                imagesavealpha($target, true);
                imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
                if (!imagecopyresampled($target, $source, 0, 0, 0, 0, $w, $h, $width, $height)) return 'unreadable';
                ob_start();
                $ok = imagepng($target, null, 9);
                $content = (string) ob_get_clean();
            } finally {
                imagedestroy($target);
            }
        } finally {
            imagedestroy($source);
        }
        if (!$ok || strlen($content) < 8 || strlen($content) > 1048576) return 'unreadable';
        return ['content' => $content, 'sha256' => hash('sha256', $content), 'width' => $w, 'height' => $h];
    }

    /** Messaggio per il form (testo tradotto, mai dettagli del file). */
    public static function message(string $code): string
    {
        return match ($code) {
            'too_large' => Yii::t('app', 'The logo is too large (maximum 2 MB).'),
            'too_small' => Yii::t('app', 'The logo is too small (at least 16x16 pixels).'),
            'too_big_dimensions' => Yii::t('app', 'The logo is too big (at most 3000x3000 pixels).'),
            'missing' => Yii::t('app', 'Choose a PNG or JPEG image.'),
            'not_image' => Yii::t('app', 'The logo must be a PNG or JPEG image.'),
            default => Yii::t('app', 'Upload failed. Try again.'),
        };
    }

    /** Indirizzo pubblico del logo (assoluto: lo apre il browser del cliente dalla pagina BTCPay). */
    public static function url(string $sha256): string
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1) throw new \InvalidArgumentException('invalid_logo');
        return Url::to(['/merchant-logo/view', 'id' => $sha256], true);
    }

    /** SHA-256 del logo caricato dal merchant, null se non c'è. */
    public static function current(Connection $db, int $merchantId): ?string
    {
        $sha = $db->createCommand('SELECT sha256 FROM {{%merchant_logos}} WHERE merchant_id=:m', [':m' => $merchantId])->queryScalar();
        return is_string($sha) ? $sha : null;
    }

    /** Logo dei negozi: quello del merchant se caricato, altrimenti quello di Napay. */
    public static function storeLogo(Connection $db, int $merchantId): string
    {
        $sha = self::current($db, $merchantId);
        return $sha !== null ? self::url($sha) : Url::to('@web' . StoreSettingsPayload::DEFAULT_LOGO, true);
    }

    /** Salva il logo normalizzato (sostituisce il precedente) e lo propaga ai negozi. Transazione del chiamante. */
    public static function save(Connection $db, int $merchantId, array $logo, int $actorId): int
    {
        self::transaction($db);
        $db->createCommand()->upsert('{{%merchant_logos}}', [
            'merchant_id' => $merchantId, 'sha256' => $logo['sha256'], 'content' => $logo['content'], 'width' => $logo['width'], 'height' => $logo['height'],
            'updated_by' => $actorId, 'updated_at' => time(),
        ], ['sha256' => $logo['sha256'], 'content' => $logo['content'], 'width' => $logo['width'], 'height' => $logo['height'], 'updated_by' => $actorId, 'updated_at' => time()])->execute();
        return self::propagate($db, $merchantId, self::url($logo['sha256']), $actorId);
    }

    /** Rimuove il logo del merchant: i negozi tornano al logo di Napay. Transazione del chiamante. */
    public static function remove(Connection $db, int $merchantId, int $actorId): int
    {
        self::transaction($db);
        $db->createCommand()->delete('{{%merchant_logos}}', ['merchant_id' => $merchantId])->execute();
        return self::propagate($db, $merchantId, Url::to('@web' . StoreSettingsPayload::DEFAULT_LOGO, true), $actorId);
    }

    /** Logo dei negozi attivi del merchant (esclusi i negozi v1, gestiti dal ponte) e store_update accodati: quanti negozi. */
    private static function propagate(Connection $db, int $merchantId, string $url, int $actorId): int
    {
        $stores = $db->createCommand('SELECT s.id FROM {{%stores}} s JOIN {{%storesettings}} ss ON ss.store_id = s.id'
            . ' WHERE s.merchant_id=:m AND s.archived_at IS NULL AND (ss.bps_storeid IS NULL OR ss.bps_storeid = \'\') ORDER BY s.id FOR UPDATE', [':m' => $merchantId])->queryColumn();
        foreach ($stores as $storeId) {
            $db->createCommand()->update('{{%storesettings}}', ['customLogo' => $url], ['store_id' => (int) $storeId])->execute();
            ProvisioningOutbox::enqueueStoreSettings($db, (int) $storeId, $merchantId, $actorId);
        }
        return count($stores);
    }

    private static function transaction(Connection $db): void
    {
        if ($db->getTransaction() === null) throw new \LogicException('transaction_required');
    }
}
