<?php

declare(strict_types=1);

namespace App\Services\Outreach;

use App\Models\OutreachMessage;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class SmtpOutreachTransport implements OutreachTransport
{
    public function send(OutreachMessage $message): void
    {
        // Dedicated SMTP transport: never inherit log, failover or global recipient overrides.
        $email = (new Email)->from(new Address($message->from_email, $message->from_name))
            ->to($message->recipient_email)->subject($message->subject)->text($message->body);
        $email->getHeaders()->addIdHeader('Message-ID', $message->message_id);
        $sent = Mail::mailer('outreach')->getSymfonyTransport()->send($email,
            new Envelope(new Address($message->from_email), [new Address($message->recipient_email)]));
        if ($sent === null) {
            throw new RuntimeException('SMTP acceptance could not be confirmed.');
        }
    }
}
