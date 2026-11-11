<?php
namespace app\components;

use Yii;
use yii\base\Component;
use yii\web\Request;

/**
 * Registro attività (chi ha fatto cosa) nella tabella logs, come in Aura.
 * Tipi: attività, dati (components/audit/AuditBootstrap), sicurezza, esportazioni.
 */
class Log extends Component
{
    public const TYPE_ACTIVITY = 'activity';
    public const TYPE_DATA = 'data';
    public const TYPE_SECURITY = 'security';
    public const TYPE_EXPORT = 'export';

    /**
     * Salva il log dell'applicazione
     * @param string $controller Il Controller
     * @param string $action Azione del controller
     * @param string $description Descrizione operazione
     * @param string $type Tipo di evento (TYPE_*)
     */
    public static function save($controller, $action, $description, string $type = self::TYPE_ACTIVITY)
    {
        $user = (Yii::$app->has('user') && !Yii::$app->user->isGuest) ? Yii::$app->user->identity : null;
        $request = Yii::$app->getRequest();
        $isWeb = $request instanceof Request;

        $entry = [
            'timestamp' => time(),
            'event_type' => $type,
            // azioni da anonimo (registrazione, attivazione): nessun utente, non un id fittizio
            'user_id' => $user->id ?? null,
            // IP da Yii: X-Forwarded-For conta solo se arriva da un proxy fidato (TRUSTED_PROXIES)
            'remote_address' => $isWeb ? mb_substr((string) $request->getUserIP(), 0, 45) : null,
            'browser' => $isWeb ? mb_substr((string) $request->getUserAgent(), 0, 256) : null,
            'controller' => mb_substr((string) $controller, 0, 60),
            'action' => mb_substr((string) $action, 0, 60),
            'description' => (string) $description,
        ];

        try {
            Yii::$app->db->createCommand()->insert('logs', $entry)->execute();
            return true;
        } catch (\Throwable $e) {
            // il registro non deve bloccare l'operazione: si conserva l'evento nel log su file
            Yii::error('Registro attività non salvato su DB: ' . $e->getMessage(), __METHOD__);
            Yii::info(json_encode($entry), 'custom-log');
            return false;
        }
    }

    /**
     * Evento di sicurezza (accesso negato, tentativo anomalo) per la richiesta corrente.
     * Finisce anche nel log tecnico come warning.
     */
    public static function security(string $description): void
    {
        Yii::warning($description, 'security');
        self::event($description, self::TYPE_SECURITY);
    }

    /**
     * Evento registrato sulla rotta della richiesta corrente.
     */
    public static function event(string $description, string $type = self::TYPE_ACTIVITY): void
    {
        [$controller, $action] = self::currentRoute();
        self::save($controller, $action, $description, $type);
    }

    /**
     * Valore digitato dall'utente (es. email) ridotto a una forma sicura da registrare.
     */
    public static function quote(?string $value, int $max = 64): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', trim((string) $value)) ?? '';

        return '«' . mb_substr($value, 0, $max) . '»';
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function currentRoute(): array
    {
        $controller = Yii::$app->controller;

        return [
            $controller->id ?? '',
            $controller->action->id ?? '',
        ];
    }
}
