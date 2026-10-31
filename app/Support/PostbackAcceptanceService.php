<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

final class PostbackAcceptanceService implements PostbackAcceptor
{
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
            if ($this->reservation->isAccepted($fingerprint)) {
                return PostbackAcceptanceOutcome::Duplicate;
            }

            $lease = $this->reservation->acquire($fingerprint);
        } catch (Throwable $exception) {
            $this->logFailure('redis_lookup_or_reserve', $exception);

            return $this->acceptInMySql($postback, $fingerprint, false);
        }

        if ($lease === null) {
            // Інший запит саме приймає цей постбек. Рядок deduplication_keys у MySQL
            // серіалізує обидва запити: другий дочекається коміту й отримає duplicate.
            return $this->acceptInMySql($postback, $fingerprint, true);
        }

        try {
            return $this->acceptInMySql($postback, $fingerprint, true);
        } finally {
            try {
                $lease->release();
            } catch (Throwable $exception) {
                $this->logFailure('redis_release', $exception);
            }
        }
    }

    private function acceptInMySql(array $postback, string $fingerprint, bool $cache): PostbackAcceptanceOutcome
    {
        try {
            $outcome = $this->store->accept($postback, $fingerprint);
        } catch (Throwable $exception) {
            $this->logFailure('mysql_accept', $exception);

            return PostbackAcceptanceOutcome::Unavailable;
        }

        if ($cache) {
            $this->cacheCommittedWindow($fingerprint);
        }

        return $outcome;
    }

    private function cacheCommittedWindow(string $fingerprint): void
    {
        try {
            $expirationMilliseconds = $this->store->expirationMilliseconds($fingerprint);
            $this->reservation->rememberAccepted($fingerprint, $expirationMilliseconds);
        } catch (Throwable $exception) {
            $this->logFailure('redis_cache', $exception);
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
