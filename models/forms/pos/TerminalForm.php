<?php
namespace app\models\forms\pos;

use Yii;
use yii\base\Model;

/** C4: soli campi ammessi dal browser per un terminale; merchant, UUID e stato li decide il server. */
class TerminalForm extends Model
{
    /** ID negozio cifrato con Crypt (solo in creazione) */
    public $store;
    public $name;

    public function scenarios()
    {
        return ['create' => ['store', 'name'], 'update' => ['name']];
    }

    public function rules()
    {
        return [
            ['name', 'filter', 'filter' => static fn($v) => is_string($v) ? trim($v) : $v],
            [['store', 'name'], 'required'],
            ['store', 'string', 'max' => 512],
            ['name', 'string', 'max' => 256],
            ['name', 'match', 'pattern' => '/\S/u'],
        ];
    }

    public function attributeLabels()
    {
        return ['store' => Yii::t('app', 'Negozio'), 'name' => Yii::t('app', 'Nome POS')];
    }
}
