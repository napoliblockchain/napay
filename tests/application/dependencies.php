<?php
// Local generated documents only: no database, credentials or external calls.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('YII_DEBUG', false);
define('YII_ENV', 'prod');
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
$checks = 0;
function check($condition, $label) {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks;
}
check(PHP_VERSION_ID >= 80336 && PHP_VERSION_ID < 80400, 'PHP 8.3 runtime');
$app = new yii\console\Application([
    'id' => 'napay-dependency-fixture', 'basePath' => dirname(__DIR__, 2),
    'runtimePath' => sys_get_temp_dir() . '/napay-dependency-runtime',
]);
check(isset($app->extensions['yiisoft/yii2-bootstrap5']['bootstrap']), 'Yii extension installer metadata');
check(Yii::t('yii/bootstrap5', 'Close', [], 'en-US') === 'Close', 'Bootstrap translation bootstrap registered');
foreach (['intl', 'gd', 'zip', 'bcmath', 'gmp', 'sockets', 'pdo_mysql', 'pdo_sqlite'] as $extension) {
    check(extension_loaded($extension), 'Required PHP extension: ' . $extension);
}
foreach ([yii\bootstrap5\ActiveForm::class, yii\symfonymailer\Mailer::class,
    kartik\detail\DetailView::class, kartik\tabs\TabsX::class, kartik\mpdf\Pdf::class,
    hail812\adminlte3\assets\FontAwesomeAsset::class, BTCPayServer\Client\Store::class,
    bizley\migration\controllers\MigrationController::class] as $class) {
    check(class_exists($class), 'Autoload: ' . $class);
}
$sheet = new PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet->getActiveSheet()->setCellValue('A1', 'Napay fixture');
$file = tempnam(sys_get_temp_dir(), 'napay-xlsx-');
try {
    PhpOffice\PhpSpreadsheet\IOFactory::createWriter($sheet, 'Xlsx')->save($file);
    $loaded = PhpOffice\PhpSpreadsheet\IOFactory::load($file);
    check($loaded->getActiveSheet()->getCell('A1')->getValue() === 'Napay fixture', 'XLSX round trip');
    $loaded->disconnectWorksheets();
} finally { unlink($file); $sheet->disconnectWorksheets(); }
$pdf = new Mpdf\Mpdf(['tempDir' => sys_get_temp_dir() . '/napay-mpdf']);
$pdf->WriteHTML('<p>Napay fixture</p>');
check(str_starts_with($pdf->Output('', 'S'), '%PDF-'), 'PDF generated locally');
echo "PASS {$checks} runtime dependency checks\n";
