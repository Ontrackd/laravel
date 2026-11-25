<?php

namespace Ontrackd\Feedback\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Ontrackd\Feedback\BladeAnchors;
use Ontrackd\Feedback\Feedback;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * `php artisan feedback:manifest`: run where a preview is deployed, after the code is in place.
 *
 * Blade compiles at runtime, so there is no build to hook as the JavaScript adapters do. This is
 * the build step instead: it reads every view, records what each opaque id stands for, writes the
 * manifest the way the package writes one (`.feedback/manifests`, marked pending), and hands it to
 * `feedback push` to upload. It then records the build for the views to be stamped with, and
 * clears the compiled views so they are compiled again with ids.
 *
 * A page also has what Vite built (React, Vue, Svelte under Inertia, say), stamped by the package
 * with ids from the same secret. The widget reads one build per page, the one this command
 * records, so the manifest the last `vite build` wrote is folded into this one: run the Vite build
 * first, then this.
 */
final class ManifestCommand extends Command
{
    protected $signature = 'feedback:manifest {--no-push : Write the manifest without uploading it}';

    protected $description = 'Record what the Blade anchors stand for, for a shared preview, and upload it';

    public function handle(): int
    {
        $secret = Feedback::secret();
        $project = Feedback::projectId();
        if ($secret === null || $project === null) {
            $this->error('FEEDBACK_SECRET is not set, or is not a project secret. Copy it from the project\'s Setup tab.');
            return self::FAILURE;
        }

        $files = [];
        foreach ((array) config('view.paths', [resource_path('views')]) as $directory) {
            if (!is_dir($directory)) continue;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) $files[] = $file->getPathname();
            }
        }
        sort($files);

        $entries = [];
        $hash = hash_init('sha256');
        hash_update($hash, $project);
        foreach ($files as $path) {
            $source = (string) file_get_contents($path);
            $relative = Feedback::relativePath($path);
            hash_update($hash, $relative . "\0" . $source . "\0");
            foreach (BladeAnchors::stamp($source, $relative, $secret)['anchors'] as $anchor) {
                $known = $entries[$anchor['id']] ?? null;
                if ($known && ($known['file'] !== $anchor['file'] || $known['line'] !== $anchor['line'] || $known['column'] !== $anchor['column'])) {
                    $this->error("Two locations share the id {$anchor['id']}: {$known['file']}:{$known['line']} and {$anchor['file']}:{$anchor['line']}. Stopping rather than resolve comments to the wrong line.");
                    return self::FAILURE;
                }
                $entries[$anchor['id']] = $anchor + ['fingerprint' => self::fingerprintAt($source, $anchor['line'])];
            }
        }

        $fingerprint = substr(hash_hmac('sha256', 'feedback:manifest:v1', $secret), 0, 16);
        $commitSha = self::git('rev-parse HEAD');
        $bundle = $this->viteManifest($fingerprint, $commitSha);
        if ($bundle !== null) {
            hash_update($hash, 'vite:' . $bundle['buildId']);
            foreach ($bundle['entries'] as $entry) {
                $known = $entries[$entry['id']] ?? null;
                if ($known && ($known['file'] !== $entry['file'] || $known['line'] !== $entry['line'] || $known['column'] !== $entry['column'])) {
                    $this->error("Two locations share the id {$entry['id']}: {$known['file']}:{$known['line']} and {$entry['file']}:{$entry['line']}. Stopping rather than resolve comments to the wrong line.");
                    return self::FAILURE;
                }
                $entries[$entry['id']] = $entry;
            }
        }

        $buildId = 'blade-' . substr(hash_final($hash), 0, 16);
        $list = array_values($entries);
        usort($list, fn ($a, $b) => [$a['file'], $a['line'], $a['column']] <=> [$b['file'], $b['line'], $b['column']]);

        $manifest = [
            'buildId' => $buildId,
            'commitSha' => $commitSha,
            'branch' => self::git('rev-parse --abbrev-ref HEAD'),
            'idWidth' => BladeAnchors::ID_WIDTH,
            'secret' => $fingerprint,
            'createdAt' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'entries' => $list,
        ];
        if (is_array($bundle['styles'] ?? null)) $manifest['styles'] = $bundle['styles'];

        $directory = base_path('.feedback/manifests');
        if (!is_dir($directory)) mkdir($directory, 0775, true);
        file_put_contents("$directory/$buildId.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents("$directory/$buildId.json.pending", gmdate('c') . " written by feedback:manifest\n");

        $buildFile = storage_path(Feedback::BUILD_FILE);
        if (!is_dir(dirname($buildFile))) mkdir(dirname($buildFile), 0775, true);
        $build = ['buildId' => $buildId];
        if (isset($bundle['plan'], $bundle['sessionId'])) {
            $build['plan'] = $bundle['plan'];
            $build['sessionId'] = $bundle['sessionId'];
        }
        file_put_contents($buildFile, json_encode($build) . "\n");
        Artisan::call('view:clear');

        $this->info(sprintf('feedback: %d anchors in %d views, build %s.', count($list), count($files), $buildId));
        if ($bundle !== null) {
            $this->line(sprintf('  With %d anchors from the Vite build %s.', count($bundle['entries']), $bundle['buildId']));
        }

        if ($this->option('no-push')) {
            $this->line('  Not uploaded. Run `npx feedback push` to send it.');
            return self::SUCCESS;
        }
        $npx = (new ExecutableFinder())->find('npx');
        if ($npx === null) {
            $this->warn('  npx is not available here, so the manifest was not uploaded. Run `npx feedback push` where Node is, from this project.');
            return self::SUCCESS;
        }
        $push = new Process([$npx, '--no-install', 'feedback', 'push'], base_path(), ['FEEDBACK_SECRET' => $secret, 'FEEDBACK_API_URL' => Feedback::apiUrl()]);
        $push->setTimeout(60);
        $push->run(fn ($type, $text) => $this->output->write($text));
        if (!$push->isSuccessful()) {
            // Comments still work: they resolve once the manifest arrives. The preview is not broken.
            $this->warn('  The manifest was not uploaded. Comments still work, and resolve once `npx feedback push` succeeds.');
        }
        return self::SUCCESS;
    }

    /**
     * The manifest the package wrote for the Vite build now in public/build, or null.
     *
     * Its own: written by that build (the package writes it once the bundle is out, so not before
     * the bundle's manifest) and with this secret, or its ids mean something else. An older build's
     * manifest would resolve comments to lines that have moved.
     */
    private function viteManifest(string $fingerprint, ?string $commitSha): ?array
    {
        $built = public_path('build/manifest.json');
        if (!is_file($built)) return null;
        $since = filemtime($built) - 5;
        $newest = null;
        foreach (glob(base_path('.feedback/manifests/*.json')) ?: [] as $path) {
            if (str_starts_with(basename($path), 'blade-') || filemtime($path) < $since) continue;
            $data = json_decode((string) file_get_contents($path), true);
            if (!is_array($data) || !is_array($data['entries'] ?? null) || !is_string($data['buildId'] ?? null)) continue;
            if (($data['secret'] ?? null) !== $fingerprint) continue;
            if (($data['idWidth'] ?? null) !== BladeAnchors::ID_WIDTH) continue;
            if ($newest === null || filemtime($path) > $newest[0]) $newest = [filemtime($path), $data];
        }
        if ($newest === null) {
            $this->warn('  No manifest for this secret from the Vite build in public/build. If its components use @ontrackd/feedback, build with FEEDBACK_SECRET set, then run this again.');
            return null;
        }
        $data = $newest[1];
        if ($commitSha !== null && is_string($data['commitSha'] ?? null) && $data['commitSha'] !== $commitSha) {
            $this->warn("  The Vite build is from {$data['commitSha']}, not {$commitSha}. Leaving it out; build again, then run this again.");
            return null;
        }
        $entries = [];
        foreach ($data['entries'] as $entry) {
            if (!is_array($entry) || !is_string($entry['id'] ?? null) || !is_string($entry['file'] ?? null)
                || !is_int($entry['line'] ?? null) || !is_int($entry['column'] ?? null)) continue;
            $entries[] = $entry;
        }
        $plan = $data['plan'] ?? null;
        $sessionId = $data['sessionId'] ?? null;
        $runtime = (($plan === 'cloud' || $plan === 'standalone') && is_string($sessionId)
            && preg_match('/^ses_[0-7][0-9a-hjkmnp-tv-z]{25}$/', $sessionId))
            ? ['plan' => $plan, 'sessionId' => $sessionId]
            : [];
        return ['buildId' => $data['buildId'], 'entries' => $entries, 'styles' => $data['styles'] ?? null] + $runtime;
    }

    /** The same fingerprint the package computes (src/core/fingerprint.ts): the lines around an anchor, normalised. */
    public static function fingerprintAt(string $code, int $line, int $radius = 3): string
    {
        $lines = explode("\n", $code);
        $from = max(0, $line - 1 - $radius);
        $to = min(count($lines), $line + $radius);
        $window = [];
        for ($i = $from; $i < $to; $i++) {
            $text = trim((string) preg_replace('/\s+/u', ' ', $lines[$i]));
            if ($text !== '') $window[] = $text;
        }
        return substr(hash('sha256', implode("\n", $window)), 0, 16);
    }

    private static function git(string $arguments): ?string
    {
        $process = Process::fromShellCommandline("git $arguments", base_path());
        $process->run();
        $out = trim($process->getOutput());
        return $process->isSuccessful() && $out !== '' ? $out : null;
    }
}
