<?php

use app\components\db\V2UsersSchema;
use yii\db\Migration;

class m261023_120000_add_activation_code_to_users extends Migration
{
    public function safeUp()
    {
        $table = $this->db->schema->getTableSchema('{{%users}}', true);
        if ($table === null) {
            throw new DomainException('Users table is required before identity schema upgrade.');
        }
        if (!isset($table->columns['activationCode'])) {
            $this->addColumn('{{%users}}', 'activationCode', $this->string(256)->defaultValue(null));
        }
        V2UsersSchema::assertActivationCode($this->db);
    }

    public function safeDown()
    {
        echo "Identity schema rollback requires an approved backup restore.\n";
        return false;
    }
}
