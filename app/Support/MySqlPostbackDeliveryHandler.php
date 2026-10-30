<?php

namespace App\Support;

use App\Jobs\SendPostbackToPartner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class MySqlPostbackDeliveryHandler implements PostbackDeliveryHandler
{
    public function __construct(private readonly PartnerPostbackClient $client) {}

    public function deliver(int $eventId): ?int
    {
        $reservation = $this->reserveAttempt($eventId);

        if ($reservation === null) {
            return null;
        }

        if (isset($reservation['wait_seconds'])) {
            return $reservation['wait_seconds'];
        }

        $startedAt = hrtime(true);
        $response = $this->client->send(
            $reservation['click_id'],
            $reservation['event_name'],
            $reservation['amount_canonical'],
        );

        $failure = $this->recordResponse($eventId, $reservation, $response);

        if ($failure !== null) {
            $failure['duration_ms'] = (int) ((hrtime(true) - $startedAt) / 1_000_000);
            Log::warning('Partner postback delivery failed', $failure);
        }

        return null;
    }

    /**
     * @return array{wait_seconds:int}|array{attempt:int,token:string,click_id:string,event_name:string,amount_canonical:string}|null
     */
    private function reserveAttempt(int $eventId): ?array
    {
        return DB::transaction(function () use ($eventId): ?array {
            $event = DB::table('events')->where('id', $eventId)->lockForUpdate()->first();
            if ($event === null || $event->status === 'sent') {
                return null;
            }

            $now = CarbonImmutable::parse($this->mysqlUtcNow(), 'UTC');

            if ($event->delivery_lease_until !== null && $event->delivery_lease_until > $now->format('Y-m-d H:i:s.u')) {
                return ['wait_seconds' => $this->secondsUntil($event->delivery_lease_until, $now)];
            }

            if ($event->status === 'failed') {
                return null;
            }

            if ($event->next_attempt_at > $now->format('Y-m-d H:i:s.u')) {
                return ['wait_seconds' => $this->secondsUntil($event->next_attempt_at, $now)];
            }

            $attempt = (int) $event->attempt_count + 1;
            if ($attempt > 3) {
                DB::table('events')->where('id', $eventId)->update([
                    'status' => 'failed',
                    'last_error' => 'attempt_limit_reached',
                    'delivery_token' => null,
                    'delivery_lease_until' => null,
                ]);

                return null;
            }

            $token = random_bytes(16);
            $leaseUntil = $now->addSeconds((int) config('postback.delivery_lease_seconds'));

            DB::table('events')->where('id', $eventId)->update([
                'attempt_count' => $attempt,
                'delivery_token' => $token,
                'delivery_lease_until' => $leaseUntil->format('Y-m-d H:i:s.u'),
                'next_attempt_at' => $leaseUntil->format('Y-m-d H:i:s.u'),
                'status' => $attempt === 3 ? 'failed' : 'pending',
                'last_error' => $attempt === 3 ? 'delivery_outcome_unknown' : $event->last_error,
            ]);

            return [
                'attempt' => $attempt,
                'token' => $token,
                'click_id' => $event->click_id,
                'event_name' => $event->event_name,
                'amount_canonical' => $event->amount_canonical,
            ];
        }, attempts: 3);
    }

    /**
     * @param  array{attempt:int,token:string,click_id:string,event_name:string,amount_canonical:string}  $reservation
     * @return array<string, mixed>|null
     */
    private function recordResponse(int $eventId, array $reservation, PartnerPostbackResponse $response): ?array
    {
        return DB::transaction(function () use ($eventId, $reservation, $response): ?array {
            $event = DB::table('events')->where('id', $eventId)->lockForUpdate()->first();
            if ($event === null) {
                return null;
            }

            $now = $this->mysqlUtcNow();

            if ($response->result === PartnerPostbackResult::Sent) {
                DB::table('events')->where('id', $eventId)->update([
                    'status' => 'sent',
                    'sent_at' => $now,
                    'last_error' => null,
                    'delivery_token' => null,
                    'delivery_lease_until' => null,
                ]);

                return null;
            }

            if ($event->status === 'sent' || $event->delivery_token !== $reservation['token']) {
                return null;
            }

            $result = $response->result;
            $retryable = in_array($result, [
                PartnerPostbackResult::RetryableHttpFailure,
                PartnerPostbackResult::NetworkFailure,
                PartnerPostbackResult::Timeout,
            ], true);
            $retryDelay = $retryable && $reservation['attempt'] < 3
                ? (int) (config('postback.retry_delays_seconds')[$reservation['attempt'] - 1] ?? 1)
                : null;
            $errorCode = match ($result) {
                PartnerPostbackResult::RetryableHttpFailure,
                PartnerPostbackResult::PermanentFailure => $response->httpStatus === null
                    ? 'http_failure'
                    : 'http_'.$response->httpStatus,
                PartnerPostbackResult::NetworkFailure => 'network_error',
                PartnerPostbackResult::Timeout => 'timeout',
                PartnerPostbackResult::Sent => 'success',
            };

            DB::table('events')->where('id', $eventId)->update([
                'status' => $retryDelay === null ? 'failed' : 'pending',
                'last_error' => $errorCode,
                'next_attempt_at' => $retryDelay === null
                    ? $now
                    : CarbonImmutable::parse($now, 'UTC')->addSeconds($retryDelay)->format('Y-m-d H:i:s.u'),
                'delivery_token' => null,
                'delivery_lease_until' => null,
            ]);

            if ($retryDelay !== null) {
                SendPostbackToPartner::dispatch($eventId)->delay($retryDelay);
            }

            return [
                'event_id' => $eventId,
                'attempt' => $reservation['attempt'],
                'category' => $result === PartnerPostbackResult::RetryableHttpFailure || $result === PartnerPostbackResult::PermanentFailure
                    ? 'http'
                    : $errorCode,
                'http_status' => $response->httpStatus,
                'retry_scheduled' => $retryDelay !== null,
                'retry_delay_seconds' => $retryDelay,
            ];
        }, attempts: 3);
    }

    private function mysqlUtcNow(): string
    {
        $result = DB::selectOne('SELECT UTC_TIMESTAMP(6) AS current_utc_time');

        if (! is_string($result?->current_utc_time ?? null)) {
            throw new \RuntimeException('MySQL did not return its current UTC time');
        }

        return $result->current_utc_time;
    }

    private function secondsUntil(string $timestamp, CarbonImmutable $now): int
    {
        $target = CarbonImmutable::parse($timestamp, 'UTC');
        $microsecondsUntil = ($target->getTimestamp() - $now->getTimestamp()) * 1_000_000
            + (int) $target->format('u')
            - (int) $now->format('u');

        return max(1, intdiv(max(0, $microsecondsUntil) + 999_999, 1_000_000));
    }
}
