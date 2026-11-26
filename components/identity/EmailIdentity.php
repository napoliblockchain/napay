<?php

namespace app\components\identity;

use yii\db\Expression;

/** Same comparison as the database identity key; no provider-specific aliasing. */
final class EmailIdentity
{
    public static function normalize($email): string
    {
        return is_string($email) ? strtolower(trim($email, ' ')) : '';
    }

    public static function condition(string $email): array
    {
        // Let the same database Unicode mapping normalize legacy stored and supplied addresses.
        return ['=', new Expression('LOWER(TRIM([[email]]) COLLATE utf8mb4_0900_bin)'),
            new Expression('LOWER(TRIM(:identity_email) COLLATE utf8mb4_0900_bin)', [':identity_email' => $email])];
    }
}
