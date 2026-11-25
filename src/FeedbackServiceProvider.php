<?php

namespace Ontrackd\Feedback;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Ontrackd\Feedback\Console\ManifestCommand;
use Ontrackd\Feedback\Http\InjectWidget;
use Ontrackd\Feedback\Http\SidecarProxy;

final class FeedbackServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/feedback.php', 'feedback');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/feedback.php' => config_path('feedback.php')], 'feedback-config');
        if ($this->app->runningInConsole()) $this->commands([ManifestCommand::class]);

        $mode = Feedback::mode();
        $this->recompileWhenTheModeChanges($mode);

        // Anchors, written into each template before Blade does anything to it, from the raw source and
        // the file it came from. Not a precompiler: Blade runs those after it has already compiled
        // component tags and comments, which moves lines, and anchors were landing several lines
        // below their elements. Strings rendered with Blade::render() have no file, and are left alone.
        if ($mode === 'local' || $mode === 'shared') {
            $secret = $mode === 'shared' ? Feedback::secret() : null;
            Blade::prepareStringsForCompilationUsing(static function (string $value) use ($secret): string {
                $path = Blade::getPath();
                if (!is_string($path) || !str_ends_with($path, '.blade.php')) return $value;
                return BladeAnchors::stamp($value, Feedback::relativePath($path), $secret)['code'];
            });
        }

        // `@feedback` is the name before the rename; it still works.
        foreach (['ontrackd', 'feedback'] as $directive) {
            Blade::directive($directive, static fn () => '<?php echo \\Ontrackd\\Feedback\\Feedback::scriptTag(); ?>');
        }
        $this->app['router']->pushMiddlewareToGroup('web', InjectWidget::class);

        // The widget and its API on this app's own origin, from the sidecar. Outside the web group:
        // no session, no CSRF token, which the widget's own requests do not carry.
        if ($mode === 'local') {
            Route::any('__feedback/{path?}', SidecarProxy::class)->where('path', '.*');
            Route::any('__fb/{path?}', SidecarProxy::class)->where('path', '.*');
        }
    }

    /**
     * Compiled views are cached, and a view compiled in one mode carries that mode's anchors (or
     * none). When the mode changes - the package installed, a secret set, a manifest made - the
     * cache is cleared once, so pages do not go on showing the old anchors.
     */
    private function recompileWhenTheModeChanges(string $mode): void
    {
        $key = $mode . ':' . (Feedback::buildId() ?? '') . ':' . (Feedback::secret() ? substr(hash('sha256', Feedback::secret()), 0, 8) : '');
        $file = storage_path('framework/cache/feedback-mode');
        $previous = is_file($file) ? trim((string) file_get_contents($file)) : null;
        if ($previous === $key) return;
        $compiled = (string) config('view.compiled');
        if ($compiled !== '' && is_dir($compiled)) {
            foreach (glob($compiled . '/*.php') ?: [] as $view) @unlink($view);
        }
        @file_put_contents($file, $key);
    }
}
