<?php

namespace app\models\search;

use yii\base\Model;
use yii\data\ActiveDataProvider;
use app\models\Storesettings;

/**
 * StoresettingsSearch represents the model behind the search form of `app\models\Storesettings`.
 */
class StoresettingsSearch extends Storesettings
{
    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['id', 'store_id', 'invoiceExpiration', 'displayExpirationTimer', 'monitoringExpiration', 'paymentTolerance', 'anyoneCanCreateInvoice', 'requiresRefundEmail', 'lightningAmountInSatoshi', 'lightningPrivateRouteHints', 'onChainWithLnInvoiceFallback', 'redirectAutomatically', 'showRecommendedFee', 'recommendedFeeBlockTarget', 'payJoinEnabled', 'lazyPaymentMethods', 'isCustomScript'], 'integer'],
            [['bps_storeid', 'website', 'defaultCurrency', 'speedPolicy', 'lightningDescriptionTemplate', 'checkoutType', 'receipt', 'defaultLang', 'customLogo', 'customCSS', 'htmlTitle', 'networkFeeMode', 'defaultPaymentMethod', 'paymentMethodCriteria', 'preferredSource', 'effectiveScript', 'derivationScheme', 'label', 'accountKeyPath'], 'safe'],
            [['spread'], 'number'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function scenarios()
    {
        // bypass scenarios() implementation in the parent class
        return Model::scenarios();
    }

    /**
     * Creates data provider instance with search query applied
     *
     * @param array $params
     *
     * @return ActiveDataProvider
     */
    public function search($params)
    {
        $query = Storesettings::find();

        // add conditions that should always apply here

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
        ]);

        $this->load($params);

        if (!$this->validate()) {
            // uncomment the following line if you do not want to return any records when validation fails
            // $query->where('0=1');
            return $dataProvider;
        }

        // grid filtering conditions
        $query->andFilterWhere([
            'id' => $this->id,
            'store_id' => $this->store_id,
            'invoiceExpiration' => $this->invoiceExpiration,
            'displayExpirationTimer' => $this->displayExpirationTimer,
            'monitoringExpiration' => $this->monitoringExpiration,
            'paymentTolerance' => $this->paymentTolerance,
            'anyoneCanCreateInvoice' => $this->anyoneCanCreateInvoice,
            'requiresRefundEmail' => $this->requiresRefundEmail,
            'lightningAmountInSatoshi' => $this->lightningAmountInSatoshi,
            'lightningPrivateRouteHints' => $this->lightningPrivateRouteHints,
            'onChainWithLnInvoiceFallback' => $this->onChainWithLnInvoiceFallback,
            'redirectAutomatically' => $this->redirectAutomatically,
            'showRecommendedFee' => $this->showRecommendedFee,
            'recommendedFeeBlockTarget' => $this->recommendedFeeBlockTarget,
            'payJoinEnabled' => $this->payJoinEnabled,
            'lazyPaymentMethods' => $this->lazyPaymentMethods,
            // 'spread' => $this->spread,
            'isCustomScript' => $this->isCustomScript,
        ]);

        $query->andFilterWhere(['like', 'bps_storeid', $this->bps_storeid])
            ->andFilterWhere(['like', 'website', $this->website])
            ->andFilterWhere(['like', 'defaultCurrency', $this->defaultCurrency])
            ->andFilterWhere(['like', 'speedPolicy', $this->speedPolicy])
            ->andFilterWhere(['like', 'lightningDescriptionTemplate', $this->lightningDescriptionTemplate])
            ->andFilterWhere(['like', 'checkoutType', $this->checkoutType])
            ->andFilterWhere(['like', 'receipt', $this->receipt])
            ->andFilterWhere(['like', 'defaultLang', $this->defaultLang])
            ->andFilterWhere(['like', 'customLogo', $this->customLogo])
            ->andFilterWhere(['like', 'customCSS', $this->customCSS])
            ->andFilterWhere(['like', 'htmlTitle', $this->htmlTitle])
            ->andFilterWhere(['like', 'spread', $this->spread]) // ricerco spread come stringa e non come numero
            ->andFilterWhere(['like', 'networkFeeMode', $this->networkFeeMode])
            ->andFilterWhere(['like', 'defaultPaymentMethod', $this->defaultPaymentMethod])
            ->andFilterWhere(['like', 'paymentMethodCriteria', $this->paymentMethodCriteria])
            ->andFilterWhere(['like', 'preferredSource', $this->preferredSource])
            ->andFilterWhere(['like', 'effectiveScript', $this->effectiveScript])
            ->andFilterWhere(['like', 'derivationScheme', $this->derivationScheme])
            ->andFilterWhere(['like', 'label', $this->label])
            ->andFilterWhere(['like', 'accountKeyPath', $this->accountKeyPath]);

        return $dataProvider;
    }
}
