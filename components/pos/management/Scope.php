<?php
namespace app\components\pos\management;

use Yii;
use app\components\Crypt;
use app\components\User;
use app\components\identity\AccountEligibility;
use app\models\Merchants;
use app\models\Stores;
use app\models\Users;
use yii\db\Query;
use yii\db\QueryInterface;

/**
 * C4 / SIP-023: chi gestisce terminali, operatori e grant, e su quale merchant.
 * Admin = tutti i merchant; gestore = il suo unico merchant (AccountEligibility); altri = nessuno.
 */
final class Scope
{
    private function __construct(public readonly int $actorId, public readonly ?int $merchantId)
    {
    }

    public static function current(): ?self
    {
        if (Yii::$app->user->isGuest) return null;
        $id = (int) Yii::$app->user->id;
        // Session identity may predate a disable committed while a write waited on a lock.
        $account = Users::findOne($id);
        if ($account === null || !$account->validateStatus()) return null;
        if (User::isAdministrator()) return new self($id, null);
        if (!User::isMerchant()) return null;
        $merchant = AccountEligibility::merchantForManager($id);
        return $merchant === null ? null : new self($id, $merchant);
    }

    public function isAdmin(): bool
    {
        return $this->merchantId === null;
    }

    /** Filtro sulla colonna merchant, da applicare prima di conteggi e paginazione. */
    public function apply(QueryInterface $query, string $column): void
    {
        if ($this->merchantId !== null) $query->andWhere([$column => $this->merchantId]);
    }

    public function covers(int $merchantId): bool
    {
        return $this->merchantId === null || $this->merchantId === $merchantId;
    }

    /** Negozi in scope per i selettori: chiave = ID cifrato, etichetta con il merchant per l'admin. */
    public function storeOptions(): array
    {
        $query = (new Query())->select(['s.id', 's.description', 'merchant' => 'm.description'])
            ->from(['s' => Stores::tableName()])
            ->innerJoin(['m' => Merchants::tableName()], 'm.id=s.merchant_id')
            ->orderBy(['m.description' => SORT_ASC, 's.description' => SORT_ASC, 's.id' => SORT_ASC]);
        $this->apply($query, 's.merchant_id');
        $options = [];
        foreach ($query->all() as $row) {
            $options[Crypt::encrypt($row['id'])] = $this->isAdmin() ? $row['merchant'] . ' - ' . $row['description'] : $row['description'];
        }
        return $options;
    }

    /** ID interno del negozio da un ID cifrato, solo se in scope; null altrimenti. */
    public function storeId($store): ?int
    {
        $query = Stores::find()->where(['id' => self::decodeId($store)]);
        $this->apply($query, 'merchant_id');
        $id = $query->select('id')->scalar();
        return $id === false ? null : (int) $id;
    }

    /** ID cifrato (Crypt) dei selettori e dei link: 0 se malformato. */
    public static function decodeId($id): int
    {
        if (!is_string($id) || $id === '' || strlen($id) > 512) return 0;
        try {
            $plain = Crypt::decrypt($id);
        } catch (\Throwable $error) {
            return 0;
        }
        return ctype_digit($plain) ? (int) $plain : 0;
    }
}
