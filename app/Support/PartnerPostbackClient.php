<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;

final class PartnerPostbackClient
{
    /**
     * @throws JsonException
     */
    public function send(string $clickId, string $eventName, string $canonicalAmount): PartnerPostbackResult
    {
        if (preg_match('/\A(?:0|[1-9][0-9]*e-?(?:0|[1-9][0-9]*))\z/', $canonicalAmount) !== 1) {
            throw new InvalidArgumentException('Amount must be a canonical decimal number');
        }

        $url = config('postback.partner.url');
        if (! is_string($url) || $url === '') {
            throw new InvalidArgumentException('Partner URL is not configured');
        }

        $clickIdJson = json_encode($clickId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $eventJson = json_encode($eventName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $body = '{"click_id":'.$clickIdJson.',"event":'.$eventJson.',"amount":'.$canonicalAmount.'}';

        try {
            $response = Http::connectTimeout(config('postback.partner.connect_timeout'))
                ->timeout(config('postback.partner.timeout'))
                ->withOptions(['allow_redirects' => false])
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (ConnectionException $exception) {
            return $this->isTimeout($exception)
                ? PartnerPostbackResult::Timeout
                : PartnerPostbackResult::NetworkFailure;
        }

        if ($response->successful()) {
            return PartnerPostbackResult::Sent;
        }

        if ($response->status() === 429 || $response->serverError()) {
            return PartnerPostbackResult::RetryableHttpFailure;
        }

        return PartnerPostbackResult::PermanentFailure;
    }

    private function isTimeout(ConnectionException $exception): bool
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if (preg_match('/\b(?:timeout|timed?\s*out)\b|cURL error 28\b/i', $cause->getMessage()) === 1) {
                return true;
            }
        }

        return false;
    }
}
