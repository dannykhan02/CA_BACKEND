<?php

namespace App\Support;

use Sentry\Event;

class NoteTelemetryRedactor
{
    public static function redact(Event $event, mixed $hint = null): Event
    {
        $request = $event->getRequest();
        $url = (string) ($request['url'] ?? '');
        if (preg_match('~/documents/[^/]+/notes(?:/|$|\?)~', $url)) {
            unset($request['data'], $request['query_string']);
            $request['url'] = strtok($url, '?') ?: $url;
            $event->setRequest($request);
        }

        return $event;
    }
}
