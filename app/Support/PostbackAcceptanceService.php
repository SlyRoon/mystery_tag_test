<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

final class PostbackAcceptanceService implements PostbackAcceptor
{
    private const CONTENTION_WAIT_MICROSECONDS = 100_000;

    public function __construct(
        private readonly RedisPostbackReservation $reservation,
        private readonly PostbackAcceptanceStore $store,
    ) {}

    public function accept(array $postback): PostbackAcceptanceOutcome
    {
        $fingerprint = PostbackFingerprint::from(
            $postback['click_id'],
            $postback['event'],
            $postback['amount'],
        );

        try {
            $lease = $this->reservation->acquire($fingerprint);
        } catch (Throwable $exception) {
            $this->logFailure('redis_reserve', $exception);

            return PostbackAcceptanceOutcome::Unavailable;
        }

        if ($lease === null) {
            usleep(self::CONTENTION_WAIT_MICROSECONDS);

            try {
                return $this->store->isDuplicate($fingerprint)
                    ? PostbackAcceptanceOutcome::Duplicate
                    : PostbackAcceptanceOutcome::Unavailable;
            } catch (Throwable $exception) {
                $this->logFailure('duplicate_lookup', $exception);

                return PostbackAcceptanceOutcome::Unavailable;
            }
        }

        try {
            return $this->store->accept($postback, $fingerprint);
        } catch (Throwable $exception) {
            $this->logFailure('mysql_accept', $exception);

            return PostbackAcceptanceOutcome::Unavailable;
        } finally {
            try {
                $lease->release();
            } catch (Throwable $exception) {
                $this->logFailure('redis_release', $exception);
            }
        }
    }

    private function logFailure(string $stage, Throwable $exception): void
    {
        Log::warning('Postback acceptance dependency failed', [
            'stage' => $stage,
            'exception' => $exception::class,
        ]);
    }
}
