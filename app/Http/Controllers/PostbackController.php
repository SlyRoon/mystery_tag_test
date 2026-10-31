<?php

namespace App\Http\Controllers;

use App\Support\PostbackAcceptanceOutcome;
use App\Support\PostbackAcceptor;
use App\Support\PostbackQueryParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LengthException;

class PostbackController extends Controller
{
    public function __invoke(Request $request, PostbackQueryParser $parser, PostbackAcceptor $acceptor): JsonResponse
    {
        if (! $request->isMethod('GET')) {
            return $this->error('Method not allowed', 405)->header('Allow', 'GET');
        }

        try {
            $postback = $parser->parse((string) $request->server->get('QUERY_STRING', ''));
        } catch (LengthException) {
            return $this->error('Query string too long', 414);
        } catch (InvalidArgumentException) {
            return $this->error('Invalid parameters', 422);
        }

        return match ($acceptor->accept($postback)) {
            PostbackAcceptanceOutcome::Accepted => $this->result('accepted', 'Event received', 200),
            PostbackAcceptanceOutcome::Duplicate => $this->result('duplicate', 'Event already received', 200),
            PostbackAcceptanceOutcome::Unavailable => $this->error('Service unavailable', 503),
        };
    }

    private function result(string $status, string $message, int $httpStatus): JsonResponse
    {
        return response()
            ->json(['status' => $status, 'message' => $message], $httpStatus)
            ->header('Cache-Control', 'no-store');
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()
            ->json(['status' => 'error', 'message' => $message], $status)
            ->header('Cache-Control', 'no-store');
    }
}
