<?php

namespace app\controllers;

use Yii;
use app\models\Logs;
use app\models\search\LogsSearch;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\filters\AccessControl;

use app\components\Crypt;
use app\components\User;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use yii\helpers\Json;

/**
 * LogsController implements the CRUD actions for Logs model.
 */
class LogsController extends Controller
{
    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'actions' => [
                            'index',
                            'view',
                            'export',
                        ],
                        'allow' => true,
                        'roles' => ['@'],
                        'matchCallback' => function ($rule, $action) {
                            return User::isAdministrator();
                        },
                    ],
                ]
            ]
        ];
    }

    /**
     * Esporta la selezione in un file excel
     */
    public function actionExport()
    {
        $queryParams = Json::decode($_POST['queryParams']);

        
        $searchModel = new LogsSearch();
        $dataProvider = $searchModel->search($queryParams);
        $dataProvider->sort->defaultOrder = ['id' => SORT_DESC];
        $dataProvider->pagination->pageSize = false;

        $allModels = $dataProvider->getModels();

        // inizializzo la classe Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Attributi da scaricare
        $attributeLabels = [
            'id' => Yii::t('app', 'ID'),
            'timestamp' => Yii::t('app', 'Timestamp'),
            'user_id' => Yii::t('app', 'User ID'),
            'remote_address' => Yii::t('app', 'Remote Address'),
            'browser' => Yii::t('app', 'Browser'),
            'controller' => Yii::t('app', 'Controller'),
            'action' => Yii::t('app', 'Action'),
            'description' => Yii::t('app', 'Description'),
        ];
        

        // create header
        $x = 0;
        foreach ($attributeLabels as $field => $text) {
            $sheet->setCellValue($this->getCellFromColnum($x) . '1', $text);
            $x++;
        }

        // load rows
        // adesso fare il ciclo sui campi dei titoli...
        $row = 2;
        foreach ($allModels as $n => $model) {
            $col = 0;
            foreach ($attributeLabels as $field => $text) {
                $writeText = $model->$field;
                $sheet->setCellValue($this->getCellFromColnum($col) . $row, trim($writeText ?? ''), null);

                $col++;
            }
            $row++;
        }

        // output the file
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $date = date('Y-m-d_H_i_s', time());
        $filename = $date . '--logs.xlsx';
        $response = Yii::$app->getResponse();
        $headers = $response->getHeaders();
        $headers->set('Content-Type', 'application/vnd.ms-excel');
        $headers->set('Content-Disposition', 'attachment;filename="' . $filename . '"');
        $headers->set('Cache-Control: max-age=0');
        ob_start();
        $writer->save("php://output");
        $content = ob_get_contents();
        ob_clean();
        return $content;
    }

    private function getCellFromColnum($colNum)
    {
        return ($colNum < 26 ? chr(65 + $colNum) : chr(65 + floor($colNum / 26) - 1) . chr(65 + ($colNum % 26)));
    }   

    /**
     * Lists all Logs models.
     * @return mixed
     */
    public function actionIndex()
    {
        $searchModel = new LogsSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);
        $dataProvider->sort->defaultOrder = ['id' => SORT_DESC];

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
            'queryParams' => Json::encode(Yii::$app->request->queryParams),
        ]);
    }

    /**
     * Displays a single Logs model.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionView($id)
    {
        return $this->render('view', [
            'model' => $this->findModel(Crypt::decrypt($id)),
        ]);
    }

    
    /**
     * Finds the Logs model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return Logs the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    protected function findModel($id)
    {
        if (($model = Logs::findOne($id)) !== null) {
            return $model;
        }

        throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
    }
}
