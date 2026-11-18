<?php
namespace app\components;

use Yii;
use yii\base\Component;

class Crypt extends Component
{
    /**
     * crypta un testo e lo restituisce in formato base64 per salvarlo in mySql
     * @param $text il testo da criptare
     * @return string base64
     */
    public static function sqlEncrypt($text): string{
        $text = Yii::$app->getSecurity()->encryptByKey($text, Yii::$app->params['secret_hash_key']);
        return \yii\helpers\StringHelper::base64UrlEncode($text);
    }
    /**
     * decrypta un testo e lo restituisce in formato originale
     * @param $text il testo da decriptare
     * @return string 
     */
    public static function sqlDecrypt($text): string
    {
        $text = \yii\helpers\StringHelper::base64UrlDecode($text);
        return Yii::$app->getSecurity()->decryptByKey($text, Yii::$app->params['secret_hash_key']);
    }

    /**
     * crypta un testo
     * @param $text il testo da criptare
     * @return string criptato
     */
    public static function encrypt($text): string
    {
        return strtr(self::enc($text,  hash( 'sha256', self::secretFromSession('secret_key') )), '', '');
    }

    /**
     * decrypta un testo
     * @param $text il testo da decriptare
     * @return string decriptato
     */
    public static function decrypt($text): string
    {
        return strtr(self::dec($text,  hash( 'sha256', self::secretFromSession('secret_key') )), '', '');
    }

    /**
     * Metodi privati della classe
     */
    private static function enc($text, $key) {
        return base64_encode( openssl_encrypt($text, self::secretFromSession('encrypt_method'), $key, 0, substr( hash( 'sha256', self::secretFromSession('secret_iv') ), 0, 16 ) ) );
    }

    private static function dec($text, $key) {
        return openssl_decrypt( base64_decode($text ), self::secretFromSession('encrypt_method'), $key, 0, substr( hash( 'sha256', self::secretFromSession('secret_iv') ), 0, 16 ) );
    }


    /**
     * questa funzione carica il secret_key e secret_iv dalla sessione corrente
     * Se non esiste ne crea una
     * @param $key la chiave da leggere
     * @return string la chiave letta
     */
    private static function secretFromSession($key) 
    {
        $session = Yii::$app->session;

        if (!$session->has('encryptionData')) {
            $data = new \stdClass(); 
            $data->secret_key = \Yii::$app->security->generateRandomString(40);
            $data->secret_iv = \Yii::$app->security->generateRandomString(33);
            $data->encrypt_method = "AES-256-CBC";

            $session->set('encryptionData', $data );
        } else {
            $data = $session->get('encryptionData');
        }

        return $data->$key;
    }
   
}
