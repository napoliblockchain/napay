<?php

namespace app\components\audit;

use Yii;
use app\components\Log;
use yii\base\ActionEvent;
use yii\base\BootstrapInterface;
use yii\base\Controller;
use yii\base\Event;
use yii\db\AfterSaveEvent;
use yii\db\BaseActiveRecord;

/**
 * Registro automatico nel registro attività (come in Aura):
 * - creazione, modifica, cancellazione delle entità principali (solo NOMI dei campi, mai i valori);
 * - esportazioni (Excel, ricevute, PDF).
 * Registrato in config/web.php ('bootstrap'). L'import legacy scrive via PDO: non passa da qui.
 */
final class AuditBootstrap implements BootstrapInterface
{
    /** Entità registrate, con l'etichetta usata nel registro (sottoclassi incluse, es. Members). */
    public const AUDITED_MODELS = [
        \app\models\Users::class => 'Utente',
        \app\models\Auth::class => 'Accesso esterno',
        \app\models\UserConsensus::class => 'Consensi',
        \app\models\Privileges::class => 'Profilo',
        \app\models\Merchants::class => 'Azienda',
        \app\models\Stores::class => 'Negozio',
        \app\models\Storesettings::class => 'Impostazione negozio',
        \app\models\Webhooks::class => 'Webhook',
        \app\models\Settings::class => 'Impostazione',
        \app\models\MemberProfiles::class => 'Profilo socio',
        \app\models\MembershipPayments::class => 'Pagamento quota',
        \app\models\MembershipFees::class => 'Quota',
        \app\models\PaymentMethods::class => 'Metodo di pagamento',
        \app\models\AssociationRoles::class => 'Carica',
        \app\models\AssemblyMinutes::class => 'Verbale',
    ];

    /** Campi tecnici: se cambiano solo questi non è una modifica da registrare. */
    private const IGNORED_ATTRIBUTES = ['authKey', 'accessToken', 'activationCode'];

    /** Action che producono un'uscita di dati. */
    private const EXPORT_ACTION_PATTERN = '/(pdf|excel|print|export|receipt|minute-file)/i';

    public function bootstrap($app): void
    {
        Event::on(BaseActiveRecord::class, BaseActiveRecord::EVENT_AFTER_INSERT, [self::class, 'onInsert']);
        Event::on(BaseActiveRecord::class, BaseActiveRecord::EVENT_AFTER_UPDATE, [self::class, 'onUpdate']);
        Event::on(BaseActiveRecord::class, BaseActiveRecord::EVENT_AFTER_DELETE, [self::class, 'onDelete']);
        Event::on(Controller::class, Controller::EVENT_BEFORE_ACTION, [self::class, 'onBeforeAction']);
    }

    public static function onInsert(Event $event): void
    {
        $label = self::label($event->sender);
        if ($label !== null) {
            self::write(sprintf('Creato %s #%s', $label, self::id($event->sender)));
        }
    }

    public static function onUpdate(AfterSaveEvent $event): void
    {
        $label = self::label($event->sender);
        if ($label === null) {
            return;
        }
        $changed = array_values(array_diff(array_keys($event->changedAttributes), self::IGNORED_ATTRIBUTES));
        // changedAttributes contiene anche i campi "toccati" con lo stesso valore: si tengono solo quelli cambiati
        $changed = array_values(array_filter($changed, static function ($attribute) use ($event) {
            return (string) $event->changedAttributes[$attribute] !== (string) $event->sender->getAttribute($attribute);
        }));
        if ($changed === []) {
            return;
        }
        self::write(sprintf('Modificato %s #%s: %s', $label, self::id($event->sender), implode(', ', $changed)));
    }

    public static function onDelete(Event $event): void
    {
        $label = self::label($event->sender);
        if ($label !== null) {
            self::write(sprintf('Eliminato %s #%s', $label, self::id($event->sender)));
        }
    }

    public static function onBeforeAction(ActionEvent $event): void
    {
        $action = $event->action;
        if (!Yii::$app instanceof \yii\web\Application || !preg_match(self::EXPORT_ACTION_PATTERN, $action->id)) {
            return;
        }
        $params = Yii::$app->request->getQueryParams();
        $details = $params === [] ? '' : ' ' . json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Log::save($action->controller->id, $action->id, 'Richiesta esportazione ' . $action->getUniqueId() . $details, Log::TYPE_EXPORT);
    }

    private static function label(object $model): ?string
    {
        foreach (self::AUDITED_MODELS as $class => $label) {
            if ($model instanceof $class) {
                return $label;
            }
        }

        return null;
    }

    private static function id(BaseActiveRecord $model): string
    {
        $key = $model->getPrimaryKey();

        return is_array($key) ? implode('-', $key) : (string) $key;
    }

    private static function write(string $description): void
    {
        [$controller, $action] = Log::currentRoute();
        Log::save($controller, $action, $description, Log::TYPE_DATA);
    }
}
