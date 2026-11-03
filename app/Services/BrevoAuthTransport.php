<?php

namespace App\Services;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class BrevoAuthTransport implements TransportInterface
{
    private TransportInterface $transport;

    public function __construct(?HttpClientInterface $client = null)
    {
        if (! AuthMailConfiguration::brevoConfigured()) {
            throw new TransportException('Authentication email transport is not configured.');
        }
        $this->transport = (new BrevoTransportFactory(client: new BrevoHttpClient($client ?? HttpClient::create())))
            ->create(new Dsn('brevo+api', 'default', config('services.brevo.key')));
    }

    /** Never propagate provider bodies, HTTP debug, or a raw previous exception. No retry/failover. */
    public function send(#[\SensitiveParameter] RawMessage $message, #[\SensitiveParameter] ?Envelope $envelope = null): ?SentMessage
    {
        try {
            $accepted = $this->transport->send($message, $envelope);
            if ($accepted === null) {
                throw new TransportException('Authentication email was not accepted.');
            }
            $result = new SentMessage($accepted->getOriginalMessage(), $accepted->getEnvelope());
            $result->setMessageId($accepted->getMessageId());

            return $result;
        } catch (Throwable) {
            throw new TransportException('Authentication email submission failed; delivery is unconfirmed.');
        }
    }

    public function __toString(): string
    {
        return 'brevo+api://api.brevo.com';
    }
}
