<?php

namespace app\models;

/**
 * Verbali di assemblea, PDF nel database (TIP-020).
 * Scritture solo dall'import legacy finché è attivo (components/legacy/LegacyImporter).
 *
 * @property mixed $id
 * @property mixed $legacy_id
 * @property mixed $meeting_date
 * @property mixed $description
 * @property mixed $file_name
 * @property mixed $file_sha256
 * @property mixed $file_content
 */
class AssemblyMinutes extends \yii\db\ActiveRecord
{
    public static function tableName()
    {
        return 'assembly_minutes';
    }

    /** Lista senza caricare i PDF */
    public static function listing()
    {
        return self::find()->select(['id', 'legacy_id', 'meeting_date', 'description', 'file_name', 'file_sha256']);
    }
}
