<?php
// SIP-033 passo 3: gateway delle fatture POS contro il BTCPay regtest usa-e-getta (e2e), dal container con proxy e CA.
// Uso: php btcpay-pos-gateway.php <appId> <cents>. Stampa solo esiti e campi pubblici della fattura, mai segreti.
if (PHP_SAPI !== 'cli' || count($argv) !== 3) exit(2);
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@app', $root);
use app\components\pos\btc\BtcpayPosGateway;
$gateway = BtcpayPosGateway::fromEnv();
if ($gateway === null) { echo json_encode(['error' => 'gateway_off']), "\n"; exit(1); }
$bytes = random_bytes(16); $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40); $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
$order = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
$created = $gateway->createInvoice($argv[1], (int) $argv[2], $order);
$status = $created['outcome'] === 'created' ? $gateway->status($created['invoice_id']) : null;
$missing = $gateway->createInvoice('NoSuchApp' . bin2hex(random_bytes(4)), 100, $order);
echo json_encode(['order' => $order, 'created' => $created, 'status' => $status, 'missing' => $missing,
    'instance_ok' => $gateway->instance() === getenv('BTCPAY_PROVISION_INSTANCE')]), "\n";
