<?php

namespace Ontrackd\Feedback\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Ontrackd\Feedback\Sidecar;

/**
 * `/__feedback/*` and `/__fb/*`, passed to the sidecar: the widget's own files and its API, on this
 * app's origin, as a Vite dev server serves them, with the same guards. Local development only.
 */
final class SidecarProxy
{
    public function __invoke(Request $request)
    {
        $port = Sidecar::port();
        if ($port === null) {
            return response(
                "The feedback sidecar is not running for this project.\n"
                . "Start your Vite dev server (npm run dev) with feedback() in vite.config.js: it starts the sidecar.\n",
                502,
                ['content-type' => 'text/plain; charset=utf-8'],
            );
        }

        $path = ltrim($request->path(), '/');
        $headers = array_filter([
            'content-type' => $request->header('content-type'),
            'accept' => $request->header('accept'),
        ]);
        if ($path === '__fb' || str_starts_with($path, '__fb/')) {
            $refusal = self::guard($request, $path, $port, $headers);
            if ($refusal !== null) return response($refusal, 403, ['content-type' => 'text/plain; charset=utf-8', 'cache-control' => 'no-store']);
        }

        $target = "http://127.0.0.1:$port/" . $path;
        if ($request->getQueryString()) $target .= '?' . $request->getQueryString();

        $forwarded = Http::withHeaders($headers)->withBody($request->getContent(), $request->header('content-type') ?? 'application/octet-stream')
            ->send($request->method(), $target);

        $passed = ['content-type' => $forwarded->header('content-type'), 'cache-control' => $forwarded->header('cache-control') ?: 'no-store'];
        foreach (self::PASSED_HEADERS as $name) $passed[$name] = $forwarded->header($name);
        return response($forwarded->body(), $forwarded->status(), array_filter($passed));
    }

    /**
     * What the sidecar says about a response that must reach the browser as it said it: a file is
     * served private, inert and (a document) as a download.
     */
    private const PASSED_HEADERS = ['content-disposition', 'x-content-type-options', 'content-security-policy', 'cross-origin-resource-policy'];

    /**
     * The checks the package's dev middleware makes, or why the request is refused.
     *
     * Refuses other sites and DNS-rebinding Hosts, and in remote (encrypted) mode attaches the
     * sidecar's per-listener bearer only for the developer's own browser: a loopback connection
     * naming a loopback Host, same-origin. Everyone else gets health and nothing more.
     */
    private static function guard(Request $request, string $path, int $port, array &$headers): ?string
    {
        // No preflight is ever granted: the widget only makes same-origin requests.
        if ($request->isMethod('OPTIONS')) return "Feedback API preflight refused\n";
        $host = strtolower((string) $request->headers->get('host'));
        $hostname = preg_replace('/:\d+$/', '', $host);
        $origin = $request->headers->get('origin');
        $site = $request->headers->get('sec-fetch-site');
        // This machine's own names: loopback, *.localhost, and the app's configured host (Herd and
        // Valet serve apps at http://<app>.test and set APP_URL to it). A DNS-rebinding page
        // arrives under some other name, which is refused like any other.
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $localName = in_array($hostname, ['localhost', '127.0.0.1', '[::1]'], true)
            || str_ends_with($hostname, '.localhost') || ($appHost !== '' && $hostname === $appHost);
        if (!$localName && !filter_var($hostname, FILTER_VALIDATE_IP) && !str_starts_with($hostname, '[')) {
            return "Feedback requests need this app's host, a loopback or an IP Host\n";
        }
        $sameOrigin = is_string($origin) && strtolower((string) preg_replace('#^https?://#i', '', $origin)) === $host;
        if ($site === 'cross-site' || ($origin !== null && !$sameOrigin)) return "Cross-origin feedback request refused\n";

        $access = base_path(".feedback/sidecar-access-$port.json");
        if (!is_file($access)) return null;
        // The developer: a loopback connection under a local name, not relayed by a tunnel or proxy
        // that names another client. Under Sail/Docker the connection is the container gateway,
        // so remote mode does not recognise the developer there (local-only mode is unaffected).
        // Every client-address header is judged by its value, as in requestGuards.ts: a local TLS
        // proxy (nginx: X-Real-IP and X-Forwarded-For) names loopback; another address, `unknown`,
        // an empty value or a Forwarded element without for= is relayed.
        $relayed = false;
        foreach (['x-forwarded-for', 'x-real-ip', 'cf-connecting-ip', 'true-client-ip', 'x-client-ip', 'fly-client-ip', 'forwarded'] as $name) {
            foreach ($request->headers->all($name) as $value) {
                foreach (explode(',', (string) $value) as $element) {
                    $addresses = [$element];
                    if ($name === 'forwarded') {
                        $addresses = [];
                        foreach (explode(';', $element) as $pair) {
                            $pair = trim($pair);
                            if (stripos($pair, 'for=') === 0) $addresses[] = substr($pair, 4);
                        }
                        if ($addresses === []) $addresses = [''];
                    }
                    foreach ($addresses as $address) {
                        if (!self::loopbackAddress($address)) $relayed = true;
                    }
                }
            }
        }
        $developer = $localName && !$relayed
            && in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)
            && ($site === null || $site === 'same-origin' || $site === 'none');
        if (!$developer) return $path === '__fb/health' ? null : "Encrypted feedback is only available from the developer's own browser on this machine\n";
        $saved = json_decode((string) @file_get_contents($access), true);
        if (is_array($saved) && ($saved['port'] ?? null) === $port && is_string($saved['token'] ?? null) && preg_match('/^[a-f0-9]{64}$/', $saved['token'])) {
            $headers['authorization'] = 'Bearer ' . $saved['token'];
        }
        return null;
    }

    /** One client address as a header carries it (quotes, brackets and a port removed), on loopback. */
    private static function loopbackAddress(string $value): bool
    {
        $address = trim($value);
        // Only a matching pair of quotes is removed, as in requestGuards.ts.
        if (preg_match('/^"(.*)"$/', $address, $match)) $address = trim($match[1]);
        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $address, $match)) $address = $match[1];
        elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $address, $match)) $address = $match[1];
        if (str_starts_with($address, '::ffff:')) $address = substr($address, 7);
        return $address === '::1' || (bool) preg_match('/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $address);
    }
}
