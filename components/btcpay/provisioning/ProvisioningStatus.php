<?php
namespace app\components\btcpay\provisioning;

use Yii;
use yii\db\Query;
use yii\helpers\Html;

/**
 * SIP-022 B5: stato del collegamento BTCPay per il backoffice, in sola lettura con i permessi del web
 * (colonne di stato di mapping e coda, mai credenziali). Unisce lo stato del mapping con l'ultimo lavoro dell'entità
 * in quattro stati leggibili; null se il provisioning non è attivo (BTCPAY_PROVISION_INSTANCE vuota).
 */
final class ProvisioningStatus
{
    public const LINKED = 'linked';
    public const PENDING = 'pending';
    public const CHECKING = 'checking';
    public const ERROR = 'error';

    private const LINK_TABLES = ['merchant_account' => 'btcpay_merchant_accounts', 'store_link' => 'btcpay_store_links', 'pos_link' => 'btcpay_pos_links'];
    private const LINK_COLUMNS = ['merchant_account' => 'merchant_id', 'store_link' => 'store_id', 'pos_link' => 'terminal_id'];

    public static function merchant(int $merchantId): ?string { return self::state('merchant_account', $merchantId); }
    public static function store(int $storeId): ?string { return self::state('store_link', $storeId); }
    public static function terminal(int $terminalId): ?string { return self::state('pos_link', $terminalId); }

    /** Badge Tabler con testo tradotto; stringa vuota se il provisioning non è attivo. */
    public static function badge(?string $state): string
    {
        if ($state === null) return '';
        [$class, $label] = match ($state) {
            self::LINKED => ['bg-green-lt', Yii::t('app', 'Linked to BTCPay')],
            self::CHECKING => ['bg-yellow-lt', Yii::t('app', 'BTCPay check in progress')],
            self::ERROR => ['bg-red-lt', Yii::t('app', 'BTCPay link error: contact the administrator')],
            default => ['bg-azure-lt', Yii::t('app', 'BTCPay link in progress')],
        };
        return Html::tag('span', Html::encode($label), ['class' => 'badge ' . $class, 'data-btcpay-status' => $state]);
    }

    private static function state(string $kind, int $entityId): ?string
    {
        $instance = ProvisioningOutbox::instance();
        if ($instance === null || $entityId < 1) return null;
        $link = (new Query())->select(['state'])->from(self::LINK_TABLES[$kind])
            ->where(['instance' => $instance, self::LINK_COLUMNS[$kind] => $entityId])->scalar();
        $job = (new Query())->select(['state'])->from('btcpay_provisioning_jobs')
            ->where(['instance' => $instance, 'kind' => $kind, 'entity_id' => $entityId])->orderBy(['generation' => SORT_DESC])->limit(1)->scalar();
        if ($link === false && $job === false) return null;
        if (in_array($link, ['failed', 'suspended'], true) || in_array($job, ['failed', 'deferred'], true)) return self::ERROR;
        if ($link === 'reconcile_required' || $job === 'reconcile_required') return self::CHECKING;
        if ($link === 'synced' && ($job === false || $job === 'done')) return self::LINKED;
        return self::PENDING;
    }
}
