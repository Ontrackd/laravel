<?php

namespace Ontrackd\Feedback;

/**
 * Anchors for Blade templates: every plain HTML element gets the template line it was written on.
 *
 * The same rule as the JavaScript adapters, in PHP because Blade compiles in PHP: an attribute is
 * spliced in straight after the tag name and nothing else moves. Locally the attribute is the
 * location (`data-fb-src="resources/views/home.blade.php:12:5"`); in a shared preview it is an
 * opaque id (`data-fb-id`), computed exactly as the JavaScript side computes it, and explained by a
 * manifest that never reaches the browser.
 *
 * Blade is HTML with its own syntax mixed in, so this is a scanner that knows what to step over
 * rather than a parser: echoes and comments, `@php` and `@verbatim` blocks, raw-text elements like
 * `<script>`, quoted attribute values and directive arguments inside a tag. Component tags
 * (`<x-card>`, `<livewire:counter>`) are not elements: the attribute would become a component
 * prop, so they are left alone and their contents are scanned as usual.
 */
final class BladeAnchors
{
    /** Elements that render nothing a reviewer can click; on `<script>` an attribute can also change meaning. */
    private const NEVER = ['script', 'style', 'meta', 'link', 'title', 'base', 'head', 'html', 'noscript', 'template', 'slot'];

    /** Elements whose content is raw text: no tags inside them are real. */
    private const RAW = ['script', 'style', 'textarea', 'title'];

    public const ID_WIDTH = 12;

    /**
     * @return array{code: string, anchors: list<array{id: string|null, file: string, line: int, column: int, component: string|null}>}
     */
    public static function stamp(string $source, string $file, ?string $secret = null): array
    {
        $length    = strlen($source);
        $inserts   = []; // offset => text
        $anchors   = [];
        $component = self::componentName($file);
        $stamped   = $secret === null ? 'data-fb-src' : 'data-fb-id';

        for ($i = 0; $i < $length;) {
            if (self::at($source, $i, '{{--')) { $i = self::after($source, $i + 4, '--}}'); continue; }
            if (self::at($source, $i, '{!!')) { $i = self::after($source, $i + 3, '!!}'); continue; }
            if (self::at($source, $i, '{{')) { $i = self::after($source, $i + 2, '}}'); continue; }
            if (self::at($source, $i, '<!--')) { $i = self::after($source, $i + 4, '-->'); continue; }
            if (self::at($source, $i, '@verbatim')) { $i = self::after($source, $i + 9, '@endverbatim'); continue; }
            if (self::at($source, $i, '@php') && ($i + 4 >= $length || !ctype_alnum($source[$i + 4]) && $source[$i + 4] !== '_')) {
                $next = $i + 4;
                while ($next < $length && ($source[$next] === ' ' || $source[$next] === "\t")) $next++;
                if ($next < $length && $source[$next] === '(') { $i = self::balanced($source, $next); continue; }
                $i = self::after($source, $i + 4, '@endphp');
                continue;
            }
            if ($source[$i] === '<' && $i + 1 < $length && ctype_alpha($source[$i + 1])) {
                $nameEnd = $i + 1;
                while ($nameEnd < $length && (ctype_alnum($source[$nameEnd]) || in_array($source[$nameEnd], ['-', ':', '.', '_'], true))) $nameEnd++;
                $name    = substr($source, $i + 1, $nameEnd - $i - 1);
                $tagEnd  = self::tagEnd($source, $nameEnd);
                $lower   = strtolower($name);
                $isComponent = str_starts_with($lower, 'x-') || str_contains($name, ':') || str_contains($name, '.');
                $tagText = substr($source, $i, $tagEnd - $i);

                if (!$isComponent && !in_array($lower, self::NEVER, true) && !str_contains($tagText, $stamped . '=')) {
                    [$line, $column] = self::position($source, $i);
                    if ($secret === null) {
                        $inserts[$nameEnd] = ($component ? ' data-fb-component="' . htmlspecialchars($component, ENT_QUOTES) . '"' : '')
                            . ' data-fb-src="' . htmlspecialchars("$file:$line:$column", ENT_QUOTES) . '"';
                        $anchors[] = ['id' => null, 'file' => $file, 'line' => $line, 'column' => $column, 'component' => $component];
                    } else {
                        $id = self::anchorId($secret, $file, $line, $column);
                        $inserts[$nameEnd] = ' data-fb-id="' . $id . '"';
                        $anchors[] = ['id' => $id, 'file' => $file, 'line' => $line, 'column' => $column, 'component' => $component];
                    }
                }

                $i = $tagEnd;
                if (in_array($lower, self::RAW, true) && !str_ends_with(rtrim($tagText), '/>')) {
                    $close = stripos($source, '</' . $lower, $i);
                    $i = $close === false ? $length : $close;
                }
                continue;
            }
            $i++;
        }

        if ($inserts === []) {
            return ['code' => $source, 'anchors' => []];
        }
        krsort($inserts);
        $code = $source;
        foreach ($inserts as $offset => $text) {
            $code = substr($code, 0, $offset) . $text . substr($code, $offset);
        }
        return ['code' => $code, 'anchors' => $anchors];
    }

    /** The same id the JavaScript side computes for this location: see src/core/anchorId.ts. */
    public static function anchorId(string $secret, string $file, int $line, int $column): string
    {
        return substr(hash_hmac('sha256', "$file:$line:$column", $secret), 0, self::ID_WIDTH);
    }

    /** `resources/views/components/pricing-card.blade.php` -> `pricing-card`. In a Blade view the file is the component. */
    public static function componentName(string $file): ?string
    {
        $base = basename($file);
        $name = preg_replace('/\.blade\.php$/', '', $base);
        return $name !== '' ? $name : null;
    }

    private static function at(string $source, int $i, string $needle): bool
    {
        return substr_compare($source, $needle, $i, strlen($needle)) === 0;
    }

    private static function after(string $source, int $from, string $needle): int
    {
        $at = strpos($source, $needle, $from);
        return $at === false ? strlen($source) : $at + strlen($needle);
    }

    /** Past a balanced (…) run starting at `$open`, stepping over quoted strings. */
    private static function balanced(string $source, int $open): int
    {
        $depth = 0;
        $length = strlen($source);
        for ($i = $open; $i < $length; $i++) {
            $char = $source[$i];
            if ($char === '"' || $char === "'") { $i = self::quoteEnd($source, $i); continue; }
            if ($char === '(') $depth++;
            if ($char === ')' && --$depth === 0) return $i + 1;
        }
        return $length;
    }

    private static function quoteEnd(string $source, int $open): int
    {
        $quote = $source[$open];
        $length = strlen($source);
        for ($i = $open + 1; $i < $length; $i++) {
            if ($source[$i] === '\\') { $i++; continue; }
            if ($source[$i] === $quote) return $i;
        }
        return $length;
    }

    /** Past the `>` that ends a tag, stepping over quoted values, echoes and directive arguments. */
    private static function tagEnd(string $source, int $from): int
    {
        $length = strlen($source);
        for ($i = $from; $i < $length; $i++) {
            if (self::at($source, $i, '{{')) { $i = self::after($source, $i + 2, '}}') - 1; continue; }
            if (self::at($source, $i, '{!!')) { $i = self::after($source, $i + 3, '!!}') - 1; continue; }
            $char = $source[$i];
            if ($char === '"' || $char === "'") { $i = self::quoteEnd($source, $i); continue; }
            if ($char === '@' && preg_match('/\G@\w+\s*\(/', $source, $match, 0, $i)) {
                $i = self::balanced($source, $i + strlen($match[0]) - 1) - 1;
                continue;
            }
            if ($char === '>') return $i + 1;
        }
        return $length;
    }

    /** 1-based line and column, columns counted in characters as the JavaScript side counts them. */
    private static function position(string $source, int $offset): array
    {
        $before = substr($source, 0, $offset);
        $line = substr_count($before, "\n") + 1;
        $lineStart = strrpos($before, "\n");
        $prefix = $lineStart === false ? $before : substr($before, $lineStart + 1);
        return [$line, mb_strlen($prefix, 'UTF-8') + 1];
    }
}
