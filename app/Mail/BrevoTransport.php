<?php

namespace App\Mail;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

/**
 * Sends Laravel's emails (recovery codes, new-account passwords) through Brevo's web API over HTTPS.
 * Render's free plan blocks the SMTP ports 25, 465 and 587, so smtp.gmail.com cannot be reached from there.
 * Used when MAIL_MAILER=brevo; the key is BREVO_API_KEY and the sender is MAIL_FROM_ADDRESS,
 * which must be a sender verified in Brevo.
 */
class BrevoTransport extends AbstractTransport
{
    public function __construct(private string $key)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->key === '') {
            throw new TransportException('BREVO_API_KEY is not set.');
        }

        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $from = $email->getFrom()[0] ?? null;
        if (!$from) {
            throw new TransportException('MAIL_FROM_ADDRESS is not set.');
        }

        $person = function (Address $a): array {
            return $a->getName() !== '' ? ['email' => $a->getAddress(), 'name' => $a->getName()] : ['email' => $a->getAddress()];
        };

        $payload = [
            'sender' => $person($from),
            'to' => array_map($person, $email->getTo()),
            'subject' => (string) $email->getSubject(),
        ];
        if ($email->getCc()) $payload['cc'] = array_map($person, $email->getCc());
        if ($email->getBcc()) $payload['bcc'] = array_map($person, $email->getBcc());
        if ($email->getReplyTo()) $payload['replyTo'] = $person($email->getReplyTo()[0]);

        $html = $email->getHtmlBody();
        $text = $email->getTextBody();
        if ($html !== null) $payload['htmlContent'] = (string) $html;
        if ($text !== null) $payload['textContent'] = (string) $text;
        if (!isset($payload['htmlContent']) && !isset($payload['textContent'])) $payload['textContent'] = ' ';

        $res = Http::withHeaders(['api-key' => $this->key, 'accept' => 'application/json'])
            ->timeout(15)
            ->post('https://api.brevo.com/v3/smtp/email', $payload);

        if ($res->failed()) {
            throw new TransportException('Brevo could not send the email (' . $res->status() . '): ' . $res->body());
        }
    }

    public function __toString(): string
    {
        return 'brevo';
    }
}