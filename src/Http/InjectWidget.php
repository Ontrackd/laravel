<?php

namespace Ontrackd\Feedback\Http;

use Closure;
use Illuminate\Http\Request;
use Ontrackd\Feedback\Feedback;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds the widget's script to HTML pages, just before `</head>`. Nothing else is touched: JSON,
 * redirects, downloads and streamed responses pass through as they were.
 */
final class InjectWidget
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!config('feedback.inject', true) || $response->isRedirection() || !method_exists($response, 'getContent')) {
            return $response;
        }
        $type = (string) $response->headers->get('content-type', '');
        if ($type !== '' && !str_contains($type, 'text/html')) return $response;

        $content = $response->getContent();
        if (!is_string($content) || stripos($content, '</head>') === false || str_contains($content, 'overlay/browser.js') || str_contains($content, 'overlay/v1/browser.js')) {
            return $response;
        }
        $tag = Feedback::scriptTag();
        if ($tag === '') return $response;

        $at = strripos($content, '</head>');
        $response->setContent(substr($content, 0, $at) . $tag . "\n" . substr($content, $at));
        return $response;
    }
}
