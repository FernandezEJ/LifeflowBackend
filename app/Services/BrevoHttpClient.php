<?php

namespace App\Services;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/** Validate the official bridge's response without implementing a replacement mail API. */
class BrevoHttpClient implements HttpClientInterface
{
    public function __construct(private HttpClientInterface $client) {}

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if ($method !== 'POST' || $url !== 'https://api.brevo.com/v3/smtp/email') {
            throw new TransportException('Authentication email endpoint is invalid.');
        }
        $response = $this->client->request($method, $url, array_replace($options, [
            'timeout' => 10, 'max_duration' => 10, 'max_redirects' => 0,
            'verify_peer' => true, 'verify_host' => true,
        ]));
        if ($response->getStatusCode() === 201) {
            $data = $response->toArray(false);
            $id = $data['messageId'] ?? null;
            if (! is_string($id) || trim($id) === '' || strlen($id) > 998 || preg_match('/[\r\n]/', $id)) {
                throw new TransportException('Authentication email acceptance response is invalid.');
            }
        }

        return $response;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        return new static($this->client->withOptions($options));
    }
}
