<?php

namespace app\controllers;

use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\helpers\ArrayHelper;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

use app\components\Log;
use app\components\User;
use app\models\LegalDocuments;
use app\models\Settings;

/**
 * SIP-040: gestione dei documenti legali (solo admin). Bozze da testo standard (legal/templates, dati del titolare da
 * impostazioni gdpr_*), modifica, anteprima con il rendering pubblico, pubblicazione (rifiutata con segnaposto) ed
 * eliminazione delle sole bozze. Le versioni pubblicate non si modificano.
 */
class LegalDocumentsController extends Controller
{
    private const GDPR_CODES = ['gdpr_titolare', 'gdpr_vat', 'gdpr_address', 'gdpr_cap', 'gdpr_city', 'gdpr_country', 'gdpr_telefono',
        'gdpr_email', 'gdpr_dpo_denomination', 'gdpr_dpo_email', 'gdpr_dpo_telefono'];

    public function behaviors()
    {
        return [
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['index' => ['GET'], 'draft' => ['POST'], 'update' => ['GET', 'POST'],
                'preview' => ['GET'], 'publish' => ['POST'], 'delete' => ['POST']]],
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => ['@'], 'matchCallback' => static fn() => User::isAdministrator()]],
            ],
        ];
    }

    public function actionIndex()
    {
        $rows = LegalDocuments::find()->orderBy(['type' => SORT_ASC, 'version' => SORT_DESC])->all();
        return $this->render('index', ['documents' => ArrayHelper::index($rows, null, 'type')]);
    }

    /** Nuova bozza dal testo standard del tipo (o la bozza aperta, se esiste già). */
    public function actionDraft()
    {
        $type = Yii::$app->request->post('type');
        if (!LegalDocuments::validType($type)) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        try {
            $draft = LegalDocuments::newDraft($type, LegalDocuments::typeLabel($type), $this->template($type), (int) Yii::$app->user->id);
        } catch (\RuntimeException $error) {
            Yii::$app->session->setFlash('warning', Yii::t('app', 'The draft could not be created now. Try again.'));
            return $this->redirect(['index']);
        }
        Log::event(sprintf('Legal document draft %s v%d', $type, (int) $draft->version), Log::TYPE_DATA);
        return $this->redirect(['update', 'id' => (int) $draft->id]);
    }

    public function actionUpdate($id)
    {
        $document = $this->find($id);
        if ($document->isPublished()) return $this->redirect(['preview', 'id' => (int) $document->id]);
        if ($document->load(Yii::$app->request->post()) && $document->save()) {
            Yii::$app->session->setFlash('success', Yii::t('app', 'Draft saved.'));
            return $this->redirect(['update', 'id' => (int) $document->id]);
        }
        return $this->render('update', ['document' => $document]);
    }

    public function actionPreview($id)
    {
        return $this->render('preview', ['document' => $this->find($id)]);
    }

    public function actionPublish($id)
    {
        $document = $this->find($id);
        $snapshot = Yii::$app->request->post('snapshot');
        if (!is_string($snapshot) || !preg_match('/^[0-9a-f]{64}$/D', $snapshot) || !$document->publish((int) Yii::$app->user->id, $snapshot)) {
            Yii::$app->session->setFlash('danger', Yii::t('app', 'Not published: the text is empty, still contains parts to approve ({marker}) or was changed meanwhile. Check it again.', ['marker' => LegalDocuments::PLACEHOLDER . ']']));
            return $this->redirect(['update', 'id' => (int) $document->id]);
        }
        Log::event(sprintf('Legal document published %s v%d', $document->type, (int) $document->version), Log::TYPE_DATA);
        Yii::$app->session->setFlash('success', Yii::t('app', 'Version published.'));
        return $this->redirect(['index']);
    }

    public function actionDelete($id)
    {
        $document = $this->find($id);
        if ($document->delete() === false) {
            Yii::$app->session->setFlash('danger', Yii::t('app', 'A published version cannot be deleted.'));
        } else {
            Log::event(sprintf('Legal document draft deleted %s v%d', $document->type, (int) $document->version), Log::TYPE_DATA);
        }
        return $this->redirect(['index']);
    }

    private function find($id): LegalDocuments
    {
        $document = is_scalar($id) && preg_match('/^[1-9][0-9]{0,9}$/D', (string) $id) ? LegalDocuments::findOne((int) $id) : null;
        if ($document === null) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        return $document;
    }

    /** Testo standard con i dati del titolare: segnaposto {{gdpr_*}} mancanti restano da completare. */
    private function template(string $type): string
    {
        $file = Yii::getAlias('@app/legal/templates/' . $type . '.md');
        $text = is_file($file) ? (string) file_get_contents($file) : '';
        $values = ArrayHelper::map(Settings::find()->where(['code' => self::GDPR_CODES])->all(), 'code', 'value');
        return preg_replace_callback('/\{\{(gdpr_[a-z_]+)\}\}/', static function ($m) use ($values) {
            $value = trim((string) ($values[$m[1]] ?? ''));
            return $value !== '' ? str_replace(["\n", "\r"], ' ', $value) : LegalDocuments::PLACEHOLDER . ': ' . $m[1] . ']';
        }, $text);
    }
}
