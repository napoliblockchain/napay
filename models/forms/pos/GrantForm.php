<?php
namespace app\models\forms\pos;

use Yii;
use yii\base\Model;

/** C4: nuovo grant; l'operatore arriva dalla URL, il merchant dal profilo, mai dal browser. */
class GrantForm extends Model
{
    /** ID negozio cifrato con Crypt */
    public $store;

    public function rules()
    {
        return [
            ['store', 'required'],
            ['store', 'string', 'max' => 512],
        ];
    }

    public function attributeLabels()
    {
        return ['store' => Yii::t('app', 'Negozio')];
    }
}
