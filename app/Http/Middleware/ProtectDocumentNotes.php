<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ProtectDocumentNotes
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('document_intelligence.notes_enabled', false), 404);

        try {
            // Apply the normal Laravel throttle after the flag check. Route-level
            // ThrottleRequests has higher framework priority and would return 429
            // for a disabled Notes route that must always look absent.
            return app(ThrottleRequests::class)->handle(
                $request, $next, $request->isMethod('GET') ? 60 : 30, 1
            );
        } catch (ValidationException|HttpResponseException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // QueryException messages can include SQL bindings, including the note body.
            // Report only the exception class; never hand this exception to a log sink.
            app(LoggerInterface::class)->error('document note operation failed', [
                'exception_class' => $exception::class,
            ]);

            return response()->json([
                'success' => false, 'message' => 'Unable to process note.', 'errors' => [],
                'error' => ['code' => 'NOTE_OPERATION_FAILED'],
            ], 500);
        }
    }
}
