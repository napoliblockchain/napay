<?php

namespace app\components\legal;

use Yii;
use yii\base\ActionFilter;

use app\models\LegalDocuments;
use app\models\UserConsensus;

/**
 * SIP-040: dopo la pubblicazione di una nuova versione delle condizioni, l'utente collegato non usa il backoffice
 * finché non la accetta (logout e pagine legali restano liberi). Nessuna versione pubblicata = nessun blocco
 * (decisione utente B, 2026-11-26). Gli ospiti e le API senza sessione non sono toccati.
 */
final class TermsGate extends ActionFilter
{
    // i consensi (revoca del marketing) restano accessibili anche prima della nuova accettazione (art. 7.3 GDPR)
    private const FREE_ROUTES = ['site/accept-terms', 'site/logout', 'site/error', 'legal/view', 'site/consents', 'site/marketing'];

    public function beforeAction($action)
    {
        if (Yii::$app->user->isGuest || in_array($action->getUniqueId(), self::FREE_ROUTES, true)) return true;
        if (self::pending((int) Yii::$app->user->id) === null) return true;
        Yii::$app->response->redirect(['/site/accept-terms'])->send();
        return false;
    }

    /** Condizioni in vigore ancora da accettare dall'utente, null se nulla da fare. */
    public static function pending(int $userId): ?LegalDocuments
    {
        $current = LegalDocuments::current('terms');
        if ($current === null) return null;
        $accepted = UserConsensus::find()->select('terms_document_id')->where(['user_id' => $userId])->orderBy(['id' => SORT_DESC])->scalar();
        return (int) $accepted === (int) $current->id ? null : $current;
    }

    /** Registra l'accettazione della versione mostrata; rifiutata se nel frattempo ne è uscita un'altra. */
    public static function accept(int $userId, int $documentId): bool
    {
        $current = LegalDocuments::current('terms');
        if ($current === null || (int) $current->id !== $documentId) return false;
        $now = time();
        $row = UserConsensus::find()->where(['user_id' => $userId])->orderBy(['id' => SORT_DESC])->one();
        if ($row === null) {
            // account senza consensi registrati (es. legacy): solo le condizioni, gli altri restano non dati
            $row = new UserConsensus(['user_id' => $userId, 'consenso_statuto' => 0, 'consenso_privacy' => 0, 'consenso_marketing' => 0,
                'timestamp_statuto' => $now, 'timestamp_privacy' => $now, 'timestamp_marketing' => $now]);
        }
        $row->consenso_condizioni = 1;
        $row->timestamp_condizioni = $now;
        $row->terms_document_id = $documentId;
        // stato ed evento insieme; solo le condizioni, mai il marketing (SIP-041)
        return UserConsensus::getDb()->transaction(static function () use ($row, $userId, $documentId): bool {
            if (!$row->save(false)) return false;
            \app\models\ConsentEvents::record($userId, 'terms', 'given', $documentId, 'reaccept');
            return true;
        });
    }
}
