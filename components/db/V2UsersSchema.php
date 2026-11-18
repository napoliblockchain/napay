<?php

namespace app\components\db;

use DomainException;
use yii\db\Connection;
use yii\db\Query;

/** Recognizes the known v2 snapshot, not arbitrary preexisting user tables. */
final class V2UsersSchema
{
    public const HISTORICAL_MIGRATION = 'm231113_093348_create_np_users_table';

    public static function assertHistoricalAlias(Connection $db): void
    {
        if ($db->driverName !== 'mysql' || $db->tablePrefix !== '') {
            throw new DomainException('Historical v2 compatibility requires unprefixed MySQL tables.');
        }
        if ($db->schema->getTableSchema('migration', true) === null
            || !(new Query())->from('migration')->where(['version' => self::HISTORICAL_MIGRATION])->exists($db)) {
            throw new DomainException('Existing users table has no recognized v2 migration history.');
        }

        $table = $db->schema->getTableSchema('users', true);
        $strings = [
            'username' => 255, 'email' => 255, 'password' => 255,
            'first_name' => 256, 'last_name' => 256, 'oauth_provider' => 20,
            'oauth_uid' => 128, 'authKey' => 256, 'accessToken' => 2048, 'picture' => 512,
        ];
        $integers = ['id', 'privilege_id', 'is_merchant', 'is_active'];
        if ($table === null || $table->primaryKey !== ['id']) {
            throw new DomainException('Existing users table has an incompatible primary key.');
        }
        $allowed = array_merge(array_keys($strings), $integers, ['activationCode']);
        if (array_diff(array_keys($table->columns), $allowed)) {
            throw new DomainException('Existing users table contains unrecognized columns.');
        }
        foreach ($strings as $name => $size) {
            $column = $table->columns[$name] ?? null;
            if ($column === null || $column->type !== 'string' || $column->size !== $size || $column->allowNull) {
                throw new DomainException('Existing users table has incompatible string columns.');
            }
        }
        foreach ($integers as $name) {
            $column = $table->columns[$name] ?? null;
            if ($column === null || $column->type !== 'integer' || $column->unsigned
                || $column->allowNull !== ($name === 'is_merchant')) {
                throw new DomainException('Existing users table has incompatible integer columns.');
            }
        }
        if (!$table->columns['id']->autoIncrement) {
            throw new DomainException('Existing users table must preserve auto-increment identity.');
        }
        if (isset($table->columns['activationCode'])) {
            self::assertActivationCode($db);
        }
        if (($table->foreignKeys['fk-user-privilege_id'] ?? null) !== [0 => 'privileges', 'privilege_id' => 'id']) {
            throw new DomainException('Existing users table has an incompatible privileges relation.');
        }
        $schema = $db->createCommand('SELECT DATABASE()')->queryScalar();
        $storage = (new Query())->select(['ENGINE', 'TABLE_COLLATION'])
            ->from('information_schema.TABLES')
            ->where(['TABLE_SCHEMA' => $schema, 'TABLE_NAME' => 'users'])->one($db);
        $constraint = (new Query())->select(['UPDATE_RULE', 'DELETE_RULE'])
            ->from('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where(['CONSTRAINT_SCHEMA' => $schema, 'TABLE_NAME' => 'users', 'CONSTRAINT_NAME' => 'fk-user-privilege_id'])->one($db);
        if (!$storage || $storage['ENGINE'] !== 'InnoDB' || $storage['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci'
            || !$constraint || $constraint['UPDATE_RULE'] !== 'CASCADE' || $constraint['DELETE_RULE'] !== 'CASCADE') {
            throw new DomainException('Existing users table storage or foreign-key rules differ from known v2.');
        }
    }

    public static function assertActivationCode(Connection $db): void
    {
        $column = $db->schema->getTableSchema('{{%users}}', true)->columns['activationCode'] ?? null;
        if ($column === null || $column->type !== 'string' || $column->size !== 256
            || !$column->allowNull || $column->defaultValue !== null) {
            throw new DomainException('Existing activationCode column is incompatible; no automatic conversion allowed.');
        }
    }
}
