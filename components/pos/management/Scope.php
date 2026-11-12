<?php
namespace app\components\pos\management;

use Yii;
use app\components\User;
use app\components\identity\AccountEligibility;
use app\models\Users;
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
}
