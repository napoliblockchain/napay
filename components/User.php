<?php
namespace app\components;

use Yii;
use yii\base\Component;
use app\models\Users;

/**
 * ### Ruoli utente dal README
 * 
 * 1 - Administrator    ROLE_ADMIN       50  => Full control su applicazione
 * 2 - User             ROLE_USER         0  => Full control sui dati di tutti gli esercenti
 * 3 - Merchant         ROLE_MERCHANT    30  => Visualizza tutti i propri negozi/pos/invoices
 */

class User extends Component
{   
    const ROLE_ADMIN = 50;
    const ROLE_MERCHANT = 30;
    const ROLE_USER = 0;
    
    /**
     * Restituisce l'id del Merchant 
     */
    public static function getMerchantId()
    {
        if (Yii::$app->user->isGuest) return 0;
        return identity\AccountEligibility::merchantForManager((int) Yii::$app->user->id) ?? 0;
    }

    /**
     * Restituisce tutti i model degli amministratori
     */
    public static function getAdmins()
    {
        return Users::find()
            ->joinWith(['privilege'])
            ->andWhere(['privileges.codice_ruolo' => 'ROLE_ADMIN'])
            ->all();
    }
    
    /**
     * Verifica se un utente può fare qualcosa in base al level
     * del privilegio
     */
    public static function can($level): bool
    {
        $p = Users::findOne(Yii::$app->user->identity->id);
        if ($p->privilege->level >= $level) return true;
        return false;
    }


    /**
     * Restituisce il livello di privilegio di un utente
     */
    public static function privilegeLevel($id = null)
    {
        if (null === $id) $id = Yii::$app->user->identity->id;

        $p = Users::findOne($id);
        return $p->privilege->level;
    }

    // utente amministratore 
    public static function isAdministrator()
    {
        $p = Users::findOne(Yii::$app->user->identity->id);
        return ($p->privilege->level === 50);
    }

    // utente merchant admin
    public static function isMerchant()
    {
        $p = Users::findOne(Yii::$app->user->identity->id);
        return ($p->privilege->level === 30);
    }

    // utente merchant junior
    public static function isUser()
    {
        $p = Users::findOne(Yii::$app->user->identity->id);
        return ($p->privilege->level === 0);    
    }

}
