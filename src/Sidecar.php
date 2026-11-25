<?php

namespace Ontrackd\Feedback;

/**
 * Finds the sidecar serving this project, the way the package does: it listens on one of a small
 * range of ports, and says which project root it serves at /__fb/health.
 */
final class Sidecar
{
    private const FIRST_PORT = 7331;
    private const PORTS = 12;

    public static function port(): ?int
    {
        $cached = cache()->get('ontrackd-feedback-sidecar-port');
        if (is_int($cached) && self::serves($cached)) return $cached;

        for ($port = self::FIRST_PORT; $port < self::FIRST_PORT + self::PORTS; $port++) {
            if (self::serves($port)) {
                cache()->put('ontrackd-feedback-sidecar-port', $port, 300);
                return $port;
            }
        }
        return null;
    }

    private static function serves(int $port): bool
    {
        $context = stream_context_create(['http' => ['timeout' => 0.4, 'ignore_errors' => true]]);
        $body = @file_get_contents("http://127.0.0.1:$port/__fb/health", false, $context);
        if ($body === false) return false;
        $health = json_decode($body, true);
        $root = is_array($health) ? ($health['projectRoot'] ?? null) : null;
        return is_string($root) && realpath($root) === realpath(base_path());
    }
}
