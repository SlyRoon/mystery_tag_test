<?php

namespace App\Http\Controllers;

use App\Support\PostbackQueryParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LengthException;

class PostbackController extends Controller
{
    public function __invoke(Request $request, PostbackQueryParser $parser): JsonResponse
    {
        try {
            $parser->parse((string) $request->server->get('QUERY_STRING', ''));
        } catch (LengthException) {
            return $this->error('Query string too long', 414);
        } catch (InvalidArgumentException) {
            return $this->error('Invalid parameters', 422);
        }

        // APP-06 will replace this response after durable event and queue writes are available.
        return $this->error('Service unavailable', 503);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()
            ->json(['status' => 'error', 'message' => $message], $status)
            ->header('Cache-Control', 'no-store');
    }
}
