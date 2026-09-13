<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Throwable;

final class SafeExceptionContext
{
    /**
     * Pass server-side identifiers only; never request bodies or exception objects.
     * Exception objects can make a logger serialize HTTP requests, SQL bindings,
     * previous exceptions, or stack arguments containing credentials.
     *
     * @return array<string, mixed>
     */
    public static function for(Throwable $exception, array $identifiers = []): array
    {
        $message = $exception instanceof QueryException
            ? ($exception->getPrevious()?->getMessage() ?? $exception->getMessage())
            : $exception->getMessage();

        // Keep the actual diagnostic summary, never appended SQL, headers,
        // request/response bodies, or multiline provider/database context.
        $message = preg_replace(
            '/(?:\R|\s+\((?:Connection|SQL):|\b(?:DETAIL|CONTEXT|QUERY|STATEMENT):|\b(?:(?:request|response|provider)\s+)?(?:body|payload|headers)\s*[:=]|\{).*$/is',
            ' [details redacted]',
            $message
        ) ?? '[exception details redacted]';

        if ($exception instanceof QueryException) {
            // Driver summaries can quote rejected values even before DETAIL.
            $message = preg_replace('/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'/s', '[redacted]', $message)
                ?? '[database details redacted]';
        }

        // Strip URL userinfo, paths and queries (including signed URLs).
        $message = preg_replace_callback('~(?:https?|postgres(?:ql)?|pgsql|mysql|rediss?)://[^\s`"<>]+~i', static function (array $match): string {
            $host = parse_url($match[0], PHP_URL_HOST);

            return is_string($host) ? parse_url($match[0], PHP_URL_SCHEME).'://'.$host.'/[redacted]' : '[URL redacted]';
        }, $message) ?? '[exception details redacted]';

        // A header/value may contain spaces; redact the rest of that summary
        // rather than risk retaining part of an Authorization or Cookie value.
        $message = preg_replace(
            '/\b(authorization|proxy-authorization|cookie|set-cookie|x-api-key|api[-_ ]?key|access[-_ ]?token|refresh[-_ ]?token|id[-_ ]?token|password|secret)["\']?\s*[:=].*$/i',
            '$1=[redacted]',
            $message
        ) ?? '[exception details redacted]';
        $message = preg_replace('/\b(Bearer|Basic)\s+[^\s,;]+/i', '$1 [redacted]', $message)
            ?? '[exception details redacted]';

        // Payment diagnostics must not retain signatures, reusable payment
        // credentials or customer/card/bank fields echoed on the first line.
        $message = preg_replace(
            '/\b(x-paystack-signature|signature|(?:authorization|access)[-_ ]?code|customer(?:[-_ ]?(?:code|id))?|email|phone|first[-_ ]?name|last[-_ ]?name|card(?:[-_ ]?(?:number|type|holder))?|pan|bin|last4|cvv|cvc|pin|exp[-_ ]?(?:month|year)|bank|account(?:[-_ ]?(?:number|name))?)["\']?\s*[:=].*$/i',
            '$1=[redacted]',
            $message
        ) ?? '[payment details redacted]';
        $message = preg_replace([
            '/\b(?:AUTH|CUS)_[A-Za-z0-9_-]+\b/',
            '/[A-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
            '/(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)/',
        ], '[redacted]', $message) ?? '[payment details redacted]';

        // Also cover unlabelled credentials echoed by a provider/driver.
        foreach ([
            'app.key', 'services.anthropic.api_key', 'services.voyage.api_key',
            'services.google.client_secret', 'services.resend.key',
            'services.paystack.secret_key',
            'database.connections.pgsql.password', 'database.redis.default.password',
            'filesystems.disks.documents.key', 'filesystems.disks.documents.secret',
        ] as $key) {
            $secret = config($key);
            if (is_string($secret) && strlen($secret) >= 4) {
                $message = str_replace($secret, '[redacted]', $message);
            }
        }

        return array_merge($identifiers, [
            'exception_class' => $exception::class,
            'exception_message' => mb_substr($message, 0, 2000),
        ]);
    }
}
