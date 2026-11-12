<?php
namespace app\components\pos\management;

use Yii;
use app\components\Log;
use app\components\Throttle;
use app\components\sendMail;
use app\components\identity\EmailIdentity;
use app\models\PosOperatorInvitations;
use app\models\PosOperators;
use app\models\Privileges;
use app\models\Users;
use yii\db\Connection;
use yii\validators\EmailValidator;

/**
 * C4 parte D (SIP-021/SIP-023): inviti operatore POS.
 * - Il merchant indica un'email; risposta identica qualunque sia lo stato dell'indirizzo.
 * - Email già usata da un account: solo un avviso a quell'indirizzo, nessun link, nessun collegamento.
 * - Accettazione dal link: nuovo account base dedicato + profilo operatore disabilitato, senza grant.
 * Ordine dei lock comune: merchant -> invito -> (utente/profilo creati). Token solo come SHA-256.
 * Le mail partono dopo il commit: nessuna chiamata esterna dentro la transazione.
 */
final class PosInvitations
{
    public const TTL = 72 * 3600;
    public const EMAIL_MAX = 60;
    /** bcrypt usa al massimo 72 byte: oltre, password diverse coinciderebbero */
    public const PASSWORD_MAX_BYTES = 72;
    /** [massimo, secondi]: inviti e avvisi per merchant, per indirizzo; accettazioni per IP */
    public const LIMIT_MERCHANT = [20, 3600];
    public const LIMIT_EMAIL = [3, 86400];
    public const LIMIT_ACCEPT_IP = [10, 60];

    public static function normalizeEmail($input): ?string
    {
        $email = EmailIdentity::normalize($input);
        if ($email === '' || strlen($email) > self::EMAIL_MAX || !(new EmailValidator())->validate($email)) return null;
        return $email;
    }

    /** Nuovo invito (sostituisce quello attivo per la stessa email nello stesso merchant). */
    public static function invite(Scope $scope, int $merchantId, string $email): void
    {
        // validazione anche qui, non solo nel form: nessuna scrittura con un indirizzo non valido
        $email = self::normalizeEmail($email);
        if ($email === null) throw new ManagementException(ManagementException::INVALID);
        self::throttle($merchantId, $email);
        [$id, $token, $known] = PosManagement::write(function (Connection $db) use ($scope, $merchantId, $email) {
            PosManagement::lockMerchant($db, $scope, $merchantId);
            PosManagement::requireQualified($merchantId);
            self::supersede($db, $merchantId, $email);
            [$invitation, $token] = self::issue(new PosOperatorInvitations(['merchant_id' => $merchantId, 'email' => $email, 'created_by' => $scope->actorId]));
            Log::event(sprintf('Invito operatore POS %d creato (merchant %d)', $invitation->id, $merchantId), Log::TYPE_DATA);
            return [(int) $invitation->id, $token, self::accountExists($email)];
        });
        self::deliver($id, $email, $token, $known);
    }

    /** Nuovo token e nuova scadenza per un invito non consumato né annullato; il vecchio link smette di valere. */
    public static function resend(Scope $scope, int $invitationId): void
    {
        $hint = PosOperatorInvitations::find()->select(['merchant_id', 'email'])->where(['id' => $invitationId])->asArray()->one();
        if ($hint === null) throw new ManagementException(ManagementException::NOT_FOUND);
        self::throttle((int) $hint['merchant_id'], $hint['email']);
        [$email, $token, $known] = PosManagement::write(function (Connection $db) use ($scope, $invitationId, $hint) {
            $invitation = self::lockInvitation($db, $scope, $invitationId, (int) $hint['merchant_id']);
            if ($invitation->consumed_at !== null || $invitation->revoked_at !== null) throw new ManagementException(ManagementException::NOT_ALLOWED);
            [, $token] = self::issue($invitation);
            Log::event(sprintf('Invito operatore POS %d reinviato (merchant %d)', $invitation->id, $invitation->merchant_id), Log::TYPE_DATA);
            return [$invitation->email, $token, self::accountExists($invitation->email)];
        });
        self::deliver($invitationId, $email, $token, $known);
    }

    public static function revoke(Scope $scope, int $invitationId): void
    {
        $merchant = PosOperatorInvitations::find()->select('merchant_id')->where(['id' => $invitationId])->scalar();
        if ($merchant === false) throw new ManagementException(ManagementException::NOT_FOUND);
        PosManagement::write(function (Connection $db) use ($scope, $invitationId, $merchant) {
            $invitation = self::lockInvitation($db, $scope, $invitationId, (int) $merchant);
            if ($invitation->consumed_at !== null || $invitation->revoked_at !== null) throw new ManagementException(ManagementException::NOT_ALLOWED);
            $invitation->revoked_at = time();
            if (!$invitation->save(false, ['revoked_at', 'updated_at'])) throw new ManagementException(ManagementException::FAILED);
            Log::event(sprintf('Invito operatore POS %d annullato (merchant %d)', $invitation->id, $invitation->merchant_id), Log::TYPE_DATA);
        });
    }

    /** Invito valido per la pagina del link (GET): nessuna scrittura. Restituisce [merchant, email] oppure null. */
    public static function preview(string $token): ?array
    {
        if (!self::validToken($token)) return null;
        $row = (new \yii\db\Query())->select(['i.email', 'i.expires_at', 'i.consumed_at', 'i.revoked_at', 'merchant' => 'm.description', 'i.merchant_id'])
            ->from(['i' => PosOperatorInvitations::tableName()])->innerJoin(['m' => '{{%merchants}}'], 'm.id=i.merchant_id')
            ->where(['i.token_sha256' => hash('sha256', $token)])->one();
        if ($row === false || $row['consumed_at'] !== null || $row['revoked_at'] !== null || (int) $row['expires_at'] <= time()
            || !\app\components\identity\AccountEligibility::qualifiedMerchant((int) $row['merchant_id'])) return null;
        return ['merchant' => $row['merchant'], 'email' => $row['email']];
    }

    /**
     * Accettazione: un solo esito positivo per token. Qualunque condizione mancante = INVALID, nessuna scrittura.
     * L'indice UNIQUE sull'email normalizzata (R2) decide le gare con altre registrazioni.
     */
    public static function accept(string $token, string $firstName, string $lastName, string $password, ?string $ip): int
    {
        if (Throttle::hit('pos-invite-accept-ip:' . (string) $ip, self::LIMIT_ACCEPT_IP) > 0) throw new ManagementException(ManagementException::RATE_LIMITED);
        if (!self::validToken($token)) throw new ManagementException(ManagementException::INVALID);
        // confine del servizio: stessi limiti del form, anche per chiamate dirette
        if (strlen($password) < (int) Yii::$app->params['user.passwordMinLength'] || strlen($password) > self::PASSWORD_MAX_BYTES
            || mb_strlen($password) < (int) Yii::$app->params['user.passwordMinLength']
            || !preg_match('/\S/u', $firstName) || !preg_match('/\S/u', $lastName)
            || mb_strlen($firstName) > 256 || mb_strlen($lastName) > 256) throw new ManagementException(ManagementException::INVALID);
        $digest = hash('sha256', $token);
        $hint = PosOperatorInvitations::find()->select('merchant_id')->where(['token_sha256' => $digest])->scalar();
        if ($hint === false) throw new ManagementException(ManagementException::INVALID);
        try {
            return PosManagement::write(function (Connection $db) use ($digest, $hint, $firstName, $lastName, $password) {
                if ($db->createCommand('SELECT id FROM {{%merchants}} WHERE id=:id FOR UPDATE', [':id' => (int) $hint])->queryScalar() === false)
                    throw new ManagementException(ManagementException::INVALID);
                PosManagement::requireQualified((int) $hint);
                $invitation = PosOperatorInvitations::findBySql('SELECT * FROM {{%pos_operator_invitations}} WHERE token_sha256=:t AND merchant_id=:m FOR UPDATE',
                    [':t' => $digest, ':m' => (int) $hint])->one();
                if ($invitation === null || !$invitation->isActive(time()) || self::accountExists($invitation->email))
                    throw new ManagementException(ManagementException::INVALID);
                $user = new Users([
                    'username' => $invitation->email,
                    'email' => $invitation->email,
                    'password' => Yii::$app->security->generatePasswordHash($password),
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'oauth_provider' => 'POS_INVITE',
                    'oauth_uid' => Yii::$app->security->generateRandomString(32),
                    'authKey' => Yii::$app->security->generateRandomString(32),
                    'accessToken' => Yii::$app->security->generateRandomString(64),
                    'picture' => '/bundles/site/images/anonymous.png',
                    'privilege_id' => self::basePrivilege(),
                    'is_merchant' => 0,
                    'is_active' => Users::STATUS_ACTIVE,
                ]);
                if (!$user->save()) throw new ManagementException(ManagementException::INVALID);
                $profile = new PosOperators(['user_id' => $user->id, 'merchant_id' => $invitation->merchant_id, 'is_enabled' => 0]);
                if (!$profile->save()) throw new ManagementException(ManagementException::FAILED);
                $invitation->consumed_at = time();
                $invitation->consumed_user_id = $user->id;
                if (!$invitation->save(false, ['consumed_at', 'consumed_user_id', 'updated_at'])) throw new ManagementException(ManagementException::FAILED);
                Log::event(sprintf('Invito operatore POS %d accettato: utente %d (merchant %d)', $invitation->id, $user->id, $invitation->merchant_id), Log::TYPE_DATA);
                return (int) $user->id;
            });
        } catch (ManagementException $e) {
            // violazione UNIQUE (email registrata nel frattempo) e ogni altro esito: stesso messaggio, nessuna scrittura
            if ($e->getMessage() !== ManagementException::RATE_LIMITED) throw new ManagementException(ManagementException::INVALID);
            throw $e;
        }
    }

    /** Stato mostrato al merchant. */
    public static function status(array $row, int $now): string
    {
        if ($row['consumed_at'] !== null) return Yii::t('app', 'Accettato');
        if ($row['revoked_at'] !== null) return Yii::t('app', 'Annullato');
        if ((int) $row['expires_at'] <= $now) return Yii::t('app', 'Scaduto');
        if ($row['sent_at'] === null && (int) $row['send_failures'] > 0) return Yii::t('app', 'Invio non riuscito');
        return Yii::t('app', 'In attesa');
    }

    private static function throttle(int $merchantId, string $email): void
    {
        // anche gli avvisi a indirizzi già registrati contano: nessuno spam verso terzi
        if (Throttle::hit('pos-invite-merchant:' . $merchantId, self::LIMIT_MERCHANT) > 0
            || Throttle::hit('pos-invite-email:' . hash('sha256', $email), self::LIMIT_EMAIL) > 0) {
            throw new ManagementException(ManagementException::RATE_LIMITED);
        }
    }

    private static function supersede(Connection $db, int $merchantId, string $email): void
    {
        $db->createCommand()->update(PosOperatorInvitations::tableName(), ['revoked_at' => time(), 'updated_at' => time()],
            ['and', ['merchant_id' => $merchantId, 'email' => $email, 'consumed_at' => null, 'revoked_at' => null]])->execute();
    }

    /** Nuovo token casuale: in DB solo il digest; il token in chiaro esiste solo fino all'invio. */
    private static function issue(PosOperatorInvitations $invitation): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $invitation->token_sha256 = hash('sha256', $token);
        $invitation->expires_at = time() + self::TTL;
        $invitation->sent_at = null;
        if (!$invitation->save(false)) throw new ManagementException(ManagementException::FAILED);
        return [$invitation, $token];
    }

    private static function lockInvitation(Connection $db, Scope $scope, int $invitationId, int $merchantId): PosOperatorInvitations
    {
        PosManagement::lockMerchant($db, $scope, $merchantId);
        $invitation = PosOperatorInvitations::findBySql('SELECT * FROM {{%pos_operator_invitations}} WHERE id=:i AND merchant_id=:m FOR UPDATE',
            [':i' => $invitationId, ':m' => $merchantId])->one();
        if ($invitation === null) throw new ManagementException(ManagementException::NOT_FOUND);
        return $invitation;
    }

    /**
     * Invio dopo il commit. Tutta la preparazione sta nel try: un errore di configurazione diventa
     * "invio non riuscito", identico per indirizzi nuovi e già registrati. Esito registrato solo se
     * il token consegnato è ancora quello dell'invito (un reinvio nel frattempo non viene alterato).
     */
    private static function deliver(int $invitationId, string $email, string $token, bool $known): void
    {
        try {
            $views = $known
                ? ['html' => 'posOperatorInvitationNotice', 'text' => 'posOperatorInvitationNotice-text']
                : ['html' => 'posOperatorInvitation', 'text' => 'posOperatorInvitation-text'];
            $params = $known ? [] : ['link' => sendMail::applicationUrl('pos-invitation/accept', ['token' => $token]), 'hours' => self::TTL / 3600];
            $sent = Yii::$app->mailer->compose($views, $params)
                ->setFrom([Yii::$app->params['adminEmail'] => Yii::$app->name])
                ->setTo($email)
                ->setSubject(Yii::$app->name . ' - ' . Yii::t('app', 'Invito operatore POS'))
                ->send();
        } catch (\Throwable $error) {
            $sent = false;
        }
        $columns = $sent ? ['sent_at' => time()] : ['send_failures' => new \yii\db\Expression('[[send_failures]] + 1')];
        PosOperatorInvitations::updateAll($columns + ['updated_at' => time()], ['id' => $invitationId, 'token_sha256' => hash('sha256', $token)]);
        if (!$sent) Yii::warning('POS invitation mail delivery failed.', __METHOD__);
    }

    private static function accountExists(string $email): bool
    {
        return Users::find()->where(EmailIdentity::condition($email))->exists();
    }

    private static function basePrivilege(): int
    {
        $ids = Privileges::find()->select('id')->where(['level' => \app\components\User::ROLE_USER, 'codice_ruolo' => 'ROLE_USER'])->limit(2)->column();
        if (count($ids) !== 1) throw new ManagementException(ManagementException::FAILED);
        return (int) $ids[0];
    }

    private static function validToken($token): bool
    {
        return is_string($token) && preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) === 1;
    }
}
