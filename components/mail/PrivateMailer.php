<?php

namespace app\components\mail;

/** File transport contains bearer links: owner-only directory and files. */
final class PrivateMailer extends \yii\symfonymailer\Mailer
{
    public function getTransport(): \Symfony\Component\Mailer\Transport\TransportInterface
    {
        $transport = parent::getTransport();
        if ($transport instanceof \Symfony\Component\Mailer\Transport\Smtp\SmtpTransport) {
            $transport->getStream()->setTimeout(10);
        }
        return $transport;
    }

    protected function saveMessage($message)
    {
        $path = \Yii::getAlias($this->fileTransportPath);
        $mask = umask(0077);
        try {
            // Expected filesystem failures become a generic delivery failure upstream.
            if (is_link($path) || (!is_dir($path) && !@mkdir($path, 0700, true))) return false;
            if (!@chmod($path, 0700)) return false;
            $file = $path . '/' . bin2hex(random_bytes(20)) . '.eml';
            $body = $message->toString();
            if (@file_put_contents($file, $body, LOCK_EX) !== strlen($body)) {
                @unlink($file);
                return false;
            }
            return true;
        } finally { umask($mask); }
    }
}
