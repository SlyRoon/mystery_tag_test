<?php

namespace App\Support;

use App\Jobs\SendPostbackToPartner;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MySqlPostbackAcceptanceStore implements PostbackAcceptanceStore
{
    public function accept(array $postback, string $fingerprint): PostbackAcceptanceOutcome
    {
        return DB::transaction(function () use ($postback, $fingerprint): PostbackAcceptanceOutcome {
            DB::table('deduplication_keys')->insertOrIgnore([
                'fingerprint' => $fingerprint,
            ]);

            $key = DB::table('deduplication_keys')
                ->where('fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if ($key === null) {
                throw new RuntimeException('Deduplication key could not be locked');
            }

            $acceptedAt = $this->mysqlUtcNow();

            if ($key->expires_at !== null && $acceptedAt < $key->expires_at) {
                return PostbackAcceptanceOutcome::Duplicate;
            }

            $event = Event::query()->create([
                'click_id' => $postback['click_id'],
                'event_name' => $postback['event'],
                'amount_canonical' => $postback['amount'],
                'accepted_at' => $acceptedAt,
                'next_attempt_at' => $acceptedAt,
                'status' => 'pending',
                'attempt_count' => 0,
            ]);

            $updated = DB::table('deduplication_keys')
                ->where('fingerprint', $fingerprint)
                ->update([
                    'event_id' => $event->getKey(),
                    'accepted_at' => $acceptedAt,
                    'expires_at' => CarbonImmutable::parse($acceptedAt, 'UTC')->addDay()->format('Y-m-d H:i:s.u'),
                ]);

            if ($updated !== 1) {
                throw new RuntimeException('Deduplication key update failed');
            }

            SendPostbackToPartner::dispatch((int) $event->getKey());

            return PostbackAcceptanceOutcome::Accepted;
        }, attempts: 3);
    }

    public function expirationMilliseconds(string $fingerprint): int
    {
        $row = DB::selectOne(
            "SELECT TIMESTAMPDIFF(MICROSECOND, '1970-01-01 00:00:00', expires_at) AS expires_at_microseconds "
            .'FROM deduplication_keys WHERE fingerprint = ?',
            [$fingerprint],
        );

        if ($row === null || $row->expires_at_microseconds === null) {
            return 0;
        }

        return intdiv((int) $row->expires_at_microseconds, 1000);
    }

    private function mysqlUtcNow(): string
    {
        $result = DB::selectOne('SELECT UTC_TIMESTAMP(6) AS current_utc_time');

        if (! is_string($result?->current_utc_time ?? null)) {
            throw new RuntimeException('MySQL did not return its current UTC time');
        }

        return $result->current_utc_time;
    }
}
