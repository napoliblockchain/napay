<?php
// Flash come toast (widgets/Alert -> jambtc/yii2-toastr-widget). Test puro: sessione in /tmp, nessun DB o rete.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
$checks = 0;
function check($condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    ++$checks; echo 'PASS: ' . $label . "\n";
}
session_save_path(sys_get_temp_dir());
new yii\web\Application(['id' => 'flash-toast', 'basePath' => $root, 'vendorPath' => $root . '/vendor',
    'aliases' => ['@npm' => $root . '/vendor/npm-asset', '@bower' => $root . '/vendor/bower-asset'],
    'components' => ['request' => ['cookieValidationKey' => bin2hex(random_bytes(16))], 'assetManager' => ['basePath' => sys_get_temp_dir(), 'baseUrl' => '/assets']]]);
$session = Yii::$app->session;
$render = static function (): yii\web\View {
    $view = new yii\web\View();
    Yii::$app->set('view', $view);
    $output = app\widgets\Alert::widget();
    check($output === '', 'Widget prints nothing inline (no fixed div)');
    return $view;
};
$ready = static fn(yii\web\View $view): string => implode("\n", $view->js[yii\web\View::POS_READY] ?? []);
$attack = '<img src=x onerror=alert(1)> "quoted" </script>';
$session->setFlash('success', 'Operazione completata: è così');
$session->setFlash('danger', $attack);
$session->setFlash('warning', ['Primo avviso', 'Secondo avviso']);
$session->setFlash('custom', 'non mostrato');
$view = $render();
$js = $ready($view);
check(isset($view->assetBundles[app\assets\ToastrTablerAsset::class]) && isset($view->assetBundles['jambtc\ToastrWidget\assets\ToastrAsset']), 'Readable toast colours under Tabler loaded with the messages');
check(str_contains($js, 'toastr.success("Operazione completata: è così");'), 'Success flash shown as toast, UTF-8 text readable in the page');
check(str_contains($js, '"positionClass":"toast-bottom-right"') && str_contains($js, '"timeOut":"15000"'), 'Toastr options: bottom-right, 15 s');
check(substr_count($js, 'toastr.warning(') === 2, 'Every message of a flash list shown');
check(str_contains($js, 'toastr.error(') && !str_contains($js, 'toastr.danger('), 'danger flash shown as toastr error');
preg_match('/toastr\.error\(("(?:[^"\\\\]|\\\\.)*")\);/', $js, $m);
check(json_decode($m[1]) === htmlspecialchars($attack, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'Message HTML-encoded before toastr (escapeHtml is off in toastr)');
check(!str_contains($js, '<img') && !str_contains($js, '</script>'), 'No raw markup or script close in the inline script');
check(isset($view->assetBundles[jambtc\ToastrWidget\assets\ToastrAsset::class]), 'Toastr asset registered');
check(!$session->hasFlash('success') && !$session->hasFlash('danger') && !$session->hasFlash('warning'), 'Shown flashes consumed');
check($session->hasFlash('custom'), 'Unknown flash types untouched');
$secondView = $render();
check($ready($secondView) === '' && !isset($secondView->assetBundles[jambtc\ToastrWidget\assets\ToastrAsset::class]), 'Second render (layout after view): nothing twice, no script or asset without messages');
$session->setFlash('info', ['', ['nested'], 'Valido']);
$js = $ready($render());
check(substr_count($js, 'toastr.info(') === 1 && str_contains($js, 'toastr.info("Valido");'), 'Empty and non-scalar messages skipped');
echo "PASS $checks flash toast checks\n";
