<?php

/**
 * -------------------------------------------------------------------------
 * TicketFlow plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * MIT License
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 Felipe Jovino.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * @link      https://github.com/Jovinull/ticketclock
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

/**
 * Extracts translatable strings into locales/ticketclock.pot.
 *
 * GLPI plugins are normally scanned with xgettext, but xgettext does not understand Twig
 * and is not always installed. This walks both PHP and Twig sources looking for the plugin
 * domain, which is enough for a plugin that never calls the translation functions
 * dynamically.
 *
 *     php tools/extract-locales.php
 */

const DOMAIN = 'ticketclock';

$root = dirname(__DIR__);
$targets = ['src', 'front', 'templates', 'setup.php', 'hook.php'];

/** @var array<string, array{plural: ?string, context: ?string, refs: list<string>}> */
$entries = [];

/**
 * @param list<string> $files
 */
function collect(string $path, array &$files): void
{
    if (is_file($path)) {
        $files[] = $path;
        return;
    }

    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && preg_match('/\.(php|twig)$/', (string) $file->getFilename())) {
            $files[] = $file->getPathname();
        }
    }
}

$files = [];
foreach ($targets as $target) {
    collect($root . '/' . $target, $files);
}
sort($files);

// Single-quoted PHP/Twig string literal, escapes included.
$str = "'((?:[^'\\\\]|\\\\.)*)'";

$patterns = [
    // __('text', 'ticketclock')
    'single' => "/\b__\(\s*{$str}\s*,\s*'" . DOMAIN . "'\s*\)/",
    // _n('one', 'many', $nb, 'ticketclock')
    'plural' => "/\b_n\(\s*{$str}\s*,\s*{$str}\s*,[^,]+,\s*'" . DOMAIN . "'\s*\)/",
    // _x('context', 'text', 'ticketclock') and _sx(...)
    'context' => "/\b_s?x\(\s*{$str}\s*,\s*{$str}\s*,\s*'" . DOMAIN . "'\s*\)/",
];

foreach ($files as $file) {
    $content = file_get_contents($file);
    if ($content === false) {
        continue;
    }

    $relative = substr($file, strlen($root) + 1);

    foreach ($patterns as $kind => $pattern) {
        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === 0) {
            continue;
        }

        foreach ($matches as $match) {
            $line = substr_count(substr($content, 0, $match[0][1]), "\n") + 1;
            $ref  = $relative . ':' . $line;

            [$msgid, $plural, $context] = match ($kind) {
                'single'  => [$match[1][0], null, null],
                'plural'  => [$match[1][0], $match[2][0], null],
                'context' => [$match[2][0], null, $match[1][0]],
            };

            $key = ($context ?? '') . "\x04" . $msgid;

            $entries[$key] ??= ['msgid' => $msgid, 'plural' => $plural, 'context' => $context, 'refs' => []];
            $entries[$key]['plural'] ??= $plural;
            $entries[$key]['refs'][] = $ref;
        }
    }
}

ksort($entries);

$out = <<<POT
    # TicketFlow plugin for GLPI.
    # Copyright (C) 2026 Felipe Jovino.
    # This file is distributed under the same license as the TicketFlow package.
    #
    msgid ""
    msgstr ""
    "Project-Id-Version: TicketFlow 0.1.0\\n"
    "Report-Msgid-Bugs-To: \\n"
    "MIME-Version: 1.0\\n"
    "Content-Type: text/plain; charset=UTF-8\\n"
    "Content-Transfer-Encoding: 8bit\\n"
    "Plural-Forms: nplurals=2; plural=(n > 1);\\n"


    POT;

/**
 * Turn a PHP single-quoted literal into a PO double-quoted one.
 */
function po_escape(string $value): string
{
    $value = str_replace(["\\'", '\\\\'], ["'", '\\'], $value);

    return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
}

foreach ($entries as $entry) {
    foreach ($entry['refs'] as $ref) {
        $out .= "#: {$ref}\n";
    }

    if ($entry['context'] !== null) {
        $out .= 'msgctxt "' . po_escape($entry['context']) . "\"\n";
    }

    $out .= 'msgid "' . po_escape($entry['msgid']) . "\"\n";

    if ($entry['plural'] !== null) {
        $out .= 'msgid_plural "' . po_escape($entry['plural']) . "\"\n";
        $out .= "msgstr[0] \"\"\nmsgstr[1] \"\"\n\n";
    } else {
        $out .= "msgstr \"\"\n\n";
    }
}

$destination = $root . '/locales/' . DOMAIN . '.pot';
file_put_contents($destination, $out);

printf("%d strings written to %s\n", count($entries), substr($destination, strlen($root) + 1));
