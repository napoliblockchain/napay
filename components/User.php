<?php
namespace app\components;

use Yii;
use yii\base\Component;
use app\models\Users;

/**
 * Livelli di privilegio (tabella privileges, gerarchici: un livello più alto può tutto quello dei livelli sotto).
 * Nel codice si usano solo queste costanti o i metodi con nome, mai numeri.
 *
 * - ROLE_USER      0  socio (o operatore POS): nessuna gestione
 * - ROLE_MERCHANT 30  gestore del proprio merchant: negozi, terminali, operatori
 * - ROLE_STAFF    40  gestione utenti (profili intermedi creati in "Profili e privilegi"; nessuno nei dati attuali)
 * - ROLE_ADMIN    50  amministratore della piattaforma
 */
class User extends Component
{
    const ROLE_ADMIN = 50;
    const ROLE_STAFF = 40;
    const ROLE_MERCHANT = 30;
    const ROLE_USER = 0;

    /**
     * Restituisce l'id del Merchant 
     */
    /** Azienda del gestore (livello merchant, esattamente un'azienda), null altrimenti: link "Azienda" verso merchants/view. */
    public static function ownCompanyId(): ?int
    {
        $identity = Yii::$app->user->identity;
        if ($identity === null || (int) ($identity->privilege->level ?? -1) !== self::ROLE_MERCHANT) return null;
        $ids = \app\models\Merchants::find()->select('id')->where(['user_id' => $identity->id])->limit(2)->column();
        return count($ids) === 1 ? (int) $ids[0] : null;
    }

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
    
    /** Livello di privilegio dell'utente collegato o, se indicato, di un altro utente (null se non esiste). */
    public static function privilegeLevel($id = null): ?int
    {
        // utente collegato: identità e privilegio già caricati per la richiesta, nessuna query in più
        $user = $id === null ? Yii::$app->user->identity : Users::findOne($id);
        $level = $user?->privilege?->level;
        return $level === null ? null : (int) $level;
    }

    /** L'utente collegato ha almeno il livello indicato (usare le costanti ROLE_*). */
    public static function hasRoleAtLeast(int $role): bool
    {
        return !Yii::$app->user->isGuest && (self::privilegeLevel() ?? -1) >= $role;
    }

    /** Lista utenti, pannello admin e dettagli utente: dal livello ROLE_STAFF in su. */
    public static function canManageUsers(): bool
    {
        return self::hasRoleAtLeast(self::ROLE_STAFF);
    }

    public static function isAdministrator(): bool
    {
        return !Yii::$app->user->isGuest && self::privilegeLevel() === self::ROLE_ADMIN;
    }

    /** Gestore di merchant (livello esatto ROLE_MERCHANT). */
    public static function isMerchant(): bool
    {
        return !Yii::$app->user->isGuest && self::privilegeLevel() === self::ROLE_MERCHANT;
    }

    /** Socio o operatore POS (livello esatto ROLE_USER). */
    public static function isUser(): bool
    {
        return !Yii::$app->user->isGuest && self::privilegeLevel() === self::ROLE_USER;
    }
}
