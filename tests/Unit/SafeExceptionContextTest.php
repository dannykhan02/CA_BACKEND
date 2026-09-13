<?php

namespace Tests\Unit;

use App\Support\SafeExceptionContext;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SafeExceptionContextTest extends TestCase
{
    public function test_http_exception_keeps_status_without_request_headers_or_response_body(): void
    {
        $request = new Request('POST', 'https://user:secret-password@provider.example/path-token?key=query-secret', [
            'Authorization' => 'Bearer header-secret',
        ], 'private request body');
        $exception = GuzzleRequestException::create($request, new Response(401, [], '{"secret":"private response body"}'));

        $context = SafeExceptionContext::for($exception);

        $this->assertSame($exception::class, $context['exception_class']);
        $this->assertStringContainsString('401 Unauthorized', $context['exception_message']);
        $this->assertStringContainsString('provider.example/[redacted]', $context['exception_message']);
        foreach (['secret-password', 'path-token', 'query-secret', 'header-secret', 'private request body', 'private response body'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($context));
        }
        $this->assertSame(['exception_class', 'exception_message'], array_keys($context));
    }

    public function test_query_exception_retains_driver_reason_without_sql_bindings_or_detail(): void
    {
        $exception = new QueryException('pgsql', 'UPDATE users SET password = ?', ['private-bound-password'], new \PDOException(
            'SQLSTATE[23505]: Unique violation: duplicate key violates unique constraint "users_email_unique"'
            ."\nDETAIL: Key (email)=(private@example.com) already exists."
        ));

        $context = SafeExceptionContext::for($exception);

        $this->assertSame(QueryException::class, $context['exception_class']);
        $this->assertStringContainsString('SQLSTATE[23505]: Unique violation: duplicate key', $context['exception_message']);
        foreach (['UPDATE users', 'private-bound-password', 'private@example.com'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($context));
        }
    }

    public function test_rejected_database_value_on_first_line_is_redacted(): void
    {
        $exception = new QueryException('pgsql', 'SELECT ?::uuid', ['private-value'], new \PDOException(
            'SQLSTATE[22P02]: Invalid text representation: invalid input syntax for type uuid: "private-value"'
        ));

        $message = SafeExceptionContext::for($exception)['exception_message'];
        $this->assertStringContainsString('SQLSTATE[22P02]: Invalid text representation', $message);
        $this->assertStringNotContainsString('private-value', $message);
    }

    #[DataProvider('sensitiveMessages')]
    public function test_sensitive_message_fields_are_redacted(string $message, string $secret): void
    {
        $context = SafeExceptionContext::for(new \RuntimeException($message));
        $this->assertStringContainsString('Failure 503', $context['exception_message']);
        $this->assertStringNotContainsString($secret, json_encode($context));
        $this->assertStringContainsString('[redacted]', $context['exception_message']);
    }

    public static function sensitiveMessages(): array
    {
        return [
            'authorization' => ['Failure 503; Authorization: Bearer private-token', 'private-token'],
            'api key' => ['Failure 503; x-api-key: private-key', 'private-key'],
            'password' => ['Failure 503; password="private-password"', 'private-password'],
            'cookie' => ['Failure 503; Cookie: session=private-cookie; another=value', 'private-cookie'],
            'basic' => ['Failure 503; Basic cHJpdmF0ZTpwYXNzd29yZA==', 'cHJpdmF0ZTpwYXNzd29yZA=='],
            'database URL' => ['Failure 503; postgresql://user:private-password@database.example/db', 'private-password'],
        ];
    }

    public function test_unlabelled_configured_secret_is_removed_and_message_is_bounded(): void
    {
        config(['services.anthropic.api_key' => 'opaque-configured-credential']);
        $context = SafeExceptionContext::for(new \RuntimeException(
            'Rejected opaque-configured-credential '.str_repeat('x', 4000)
        ));

        $this->assertStringStartsWith('Rejected [redacted]', $context['exception_message']);
        $this->assertStringNotContainsString('opaque-configured-credential', json_encode($context));
        $this->assertLessThanOrEqual(2000, mb_strlen($context['exception_message']));
    }
}
