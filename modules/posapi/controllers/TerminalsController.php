<?php
namespace app\modules\posapi\controllers;

use Yii;
use app\components\pos\PosApi;
use app\components\pos\TerminalCatalog;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class TerminalsController extends \yii\rest\Controller
{
    public function behaviors()
    {
        return [
            'contentNegotiator'=>['class'=>ContentNegotiator::class,'formats'=>['application/json'=>Response::FORMAT_JSON]],
            'verbs'=>['class'=>VerbFilter::class,'actions'=>['index'=>['GET']]],
        ];
    }

    public function actionIndex()
    {
        try {
            $request = Yii::$app->request;
            $client = PosApi::authenticateClient($request->headers->get('X-POS-Client-Key'));
            if ($client === null) return $this->error(401,'unauthenticated');
            $bearer = $request->headers->get('Authorization');
            if (!is_string($bearer) || !preg_match('/^Bearer (napos_[A-Za-z0-9_-]{43})$/D', $bearer, $match)) return $this->error(401,'invalid_token');
            if (($wait=PosApi::throttle('catalog|client|'.$client,[120,60])) > 0) return $this->limited($wait);
            $session = PosApi::findActiveSession($match[1],$client);
            if ($session === null) return $this->error(401,'invalid_token');
            if (($wait=PosApi::throttle('catalog|session|'.hash('sha256',$match[1]),[30,60])) > 0) return $this->limited($wait);
            $params = $request->getQueryParams();
            if ($request->getRawBody() !== '' || array_diff(array_keys($params),['limit','cursor'])) throw new BadRequestHttpException();
            $limit = $params['limit'] ?? '50';
            if (!is_string($limit) || !preg_match('/^(?:[1-9][0-9]?|100)$/D',$limit)) throw new BadRequestHttpException();
            $cursor = $params['cursor'] ?? null;
            if (array_key_exists('cursor',$params) && !is_string($cursor)) throw new BadRequestHttpException();
            return TerminalCatalog::page((int)$session->user_id,$match[1],$client,(int)$limit,$cursor);
        } catch (BadRequestHttpException $e) {
            return $this->error(400,'validation_failed');
        } catch (\Throwable $e) {
            Yii::error('POS catalog failure: '.get_class($e),__METHOD__);
            return $this->error(503,'backend_unavailable');
        }
    }

    private function limited(int $wait): array
    {
        Yii::$app->response->headers->set('Retry-After',(string)$wait);
        return $this->error(429,'rate_limited');
    }
    private function error(int $status,string $code): array
    {
        $messages=['unauthenticated'=>'Client authentication required.','invalid_token'=>'Operator authentication required.',
            'validation_failed'=>'Invalid request.','rate_limited'=>'Too many attempts. Retry later.','backend_unavailable'=>'Service unavailable.'];
        Yii::$app->response->statusCode=$status;
        return ['error'=>['code'=>$code,'message'=>$messages[$code]]];
    }
}
