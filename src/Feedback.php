<?php

namespace Ontrackd\Feedback;

/**
 * What this request is: local development, a shared preview, a script-tag install, or nothing.
 *
 * - **local**: `APP_ENV=local`. Blade elements carry their file and line; the widget and its API
 *   come from the sidecar the project's Vite dev server starts (`npm run dev`, with
 *   `ontrackd()` in vite.config.js), reached through this app at `/__feedback` and `/__fb`.
 * - **shared**: a secret is set and `php artisan ontrackd:manifest` has run for these views. Elements
 *   carry opaque ids, explained by the manifest it uploaded; the widget comes from the API.
 * - **tag**: a secret is set but no manifest has been made. Nothing is stamped; the widget comes from
 *   the API and describes elements instead, so comments still work, with less precision.
 * - **off**: anything else, which is how production should look.
 */
final class Feedback
{
    /** In storage/framework/cache, which Laravel's own .gitignore already leaves out of git. */
    public const BUILD_FILE = 'framework/cache/feedback-build.json';

    public static function mode(): string
    {
        if (!config('feedback.enabled', true)) return 'off';
        if (app()->environment('local')) return 'local';
        if (self::projectId() === null) return 'off';
        return self::buildId() !== null ? 'shared' : 'tag';
    }

    public static function secret(): ?string
    {
        $secret = config('feedback.secret');
        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /** `sk_<projectId>.…` carries a legacy `p_…` or Cloud `prj_…` id, as projectIdFromSecret does. */
    public static function projectId(): ?string
    {
        $secret = self::secret();
        if ($secret === null || !str_starts_with($secret, 'sk_')) return null;
        $rest = substr($secret, 3);
        $dot = strpos($rest, '.');
        if ($dot === false || $dot === 0) return null;
        $project = substr($rest, 0, $dot);
        return preg_match('/^(?:p_[a-z0-9]{6,32}|prj_[0-7][0-9a-hjkmnp-tv-z]{25})$/', $project) ? $project : null;
    }

    /** The build `ontrackd:manifest` recorded for the current views, when it has run. */
    public static function buildId(): ?string
    {
        $data = self::buildMetadata();
        return isset($data['buildId']) && is_string($data['buildId']) ? $data['buildId'] : null;
    }

    /** Build and public runtime metadata recorded by `ontrackd:manifest`. */
    private static function buildMetadata(): array
    {
        $file = storage_path(self::BUILD_FILE);
        if (!is_file($file)) return [];
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    public static function apiUrl(): string
    {
        return rtrim((string) config('feedback.api_url', 'https://api.ontrackd.com'), '/');
    }

    /** The script tag for this mode, or '' when there is nothing to load. */
    public static function scriptTag(): string
    {
        $mode = self::mode();
        if ($mode === 'local') {
            return '<script type="module" data-feedback-overlay src="/__feedback/overlay/browser.js"></script>';
        }
        if ($mode === 'off') return '';
        $query = ['p' => self::projectId()];
        if ($mode === 'shared') {
            $build = self::buildMetadata();
            $query['b'] = $build['buildId'];
            $sessionId = $build['sessionId'] ?? null;
            $plan = $build['plan'] ?? null;
            if (is_string($sessionId) && preg_match('/^ses_[0-7][0-9a-hjkmnp-tv-z]{25}$/', $sessionId)
                && ($plan === 'cloud' || $plan === 'standalone')) {
                $query['s'] = $sessionId;
                if ($plan === 'cloud') $query['plan'] = 'cloud';
            }
        }
        $query['a'] = self::apiUrl();
        $src = self::apiUrl() . '/overlay/v1/browser.js?' . http_build_query($query);
        return '<script type="module" async data-feedback-overlay src="' . htmlspecialchars($src, ENT_QUOTES) . '"></script>';
    }

    /** The project-relative path anchors record: `resources/views/home.blade.php`. */
    public static function relativePath(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/') . '/';
        $path = str_replace('\\', '/', $path);
        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
