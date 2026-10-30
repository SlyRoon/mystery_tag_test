<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EventIndexController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $parameters = $this->parameters($request->query->all());

        if ($parameters === null) {
            return $this->error(422);
        }

        try {
            $query = DB::table('events');

            if ($parameters['status'] !== null) {
                $query->where('status', $parameters['status']);
            }

            $total = $query->count();
            $lastPage = $total === 0 ? 0 : intdiv($total - 1, $parameters['per_page']) + 1;
            $events = [];

            if ($parameters['page'] <= $lastPage) {
                $offset = ($parameters['page'] - 1) * $parameters['per_page'];
                $events = $query
                    ->orderByDesc('accepted_at')
                    ->orderByDesc('id')
                    ->offset($offset)
                    ->limit($parameters['per_page'])
                    ->get([
                        'id',
                        'click_id',
                        'event_name',
                        'amount_canonical',
                        'accepted_at',
                        'status',
                        'attempt_count',
                        'last_error',
                        'sent_at',
                    ])
                    ->map(fn (object $event): array => [
                        'id' => (int) $event->id,
                        'click_id' => $event->click_id,
                        'event' => $event->event_name,
                        'amount' => $event->amount_canonical,
                        'accepted_at' => CarbonImmutable::parse($event->accepted_at, 'UTC')->format('Y-m-d\TH:i:s.u\Z'),
                        'status' => $event->status,
                        'attempt_count' => (int) $event->attempt_count,
                        'last_error' => $event->last_error,
                        'sent_at' => $event->sent_at === null
                            ? null
                            : CarbonImmutable::parse($event->sent_at, 'UTC')->format('Y-m-d\TH:i:s.u\Z'),
                    ])
                    ->all();
            }
        } catch (QueryException) {
            return $this->error(503, 'Service unavailable');
        }

        return response()
            ->json([
                'data' => $events,
                'meta' => [
                    'page' => $parameters['page'],
                    'per_page' => $parameters['per_page'],
                    'total' => $total,
                ],
            ])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{page:int,per_page:int,status:?string}|null
     */
    private function parameters(array $query): ?array
    {
        $page = $this->positiveInteger(array_key_exists('page', $query) ? $query['page'] : '1');
        $perPage = $this->positiveInteger(array_key_exists('per_page', $query) ? $query['per_page'] : '20');
        $hasStatus = array_key_exists('status', $query);
        $status = $hasStatus ? $query['status'] : null;

        if ($page === null || $perPage === null || $perPage > 100) {
            return null;
        }

        if ($hasStatus && (! is_string($status) || ! in_array($status, ['pending', 'sent', 'failed'], true))) {
            return null;
        }

        return ['page' => $page, 'per_page' => $perPage, 'status' => $status];
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) ? $integer : null;
    }

    private function error(int $status, string $message = 'Invalid parameters'): JsonResponse
    {
        return response()
            ->json(['status' => 'error', 'message' => $message], $status)
            ->header('Cache-Control', 'no-store');
    }
}
