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
 * Applies tools/HEADER as the licence block of every PHP file.
 *
 * GLPI's `licence-headers-check` compares the first docblock of each file against
 * tools/HEADER; this writes that block so the check has something to agree with. It is
 * idempotent — running it twice changes nothing.
 *
 *     php tools/apply-headers.php [--check]
 */

$root = dirname(__DIR__);
$check_only = in_array('--check', $argv, true);

$header_lines = file($root . '/tools/HEADER', FILE_IGNORE_NEW_LINES);
if ($header_lines === false) {
    fwrite(STDERR, "tools/HEADER not found\n");
    exit(1);
}

$block = "/**\n";
foreach ($header_lines as $line) {
    $block .= rtrim(' * ' . $line) . "\n";
}
$block .= " */";

$targets = ['src', 'front', 'tests', 'tools'];
$files = [];
foreach (['setup.php', 'hook.php'] as $single) {
    $files[] = $root . '/' . $single;
}
foreach ($targets as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);

$changed = 0;
$missing = [];

foreach ($files as $file) {
    $content = file_get_contents($file);
    if ($content === false || !str_starts_with($content, '<?php')) {
        continue;
    }

    $rest = substr($content, strlen('<?php'));
    // Drop any leading blank lines plus an existing leading docblock.
    $rest = preg_replace('#^\s*/\*\*.*?\*/#s', '', $rest, 1) ?? $rest;
    $rest = ltrim($rest, "\r\n");

    $expected = "<?php\n\n" . $block . "\n\n" . $rest;

    if ($content === $expected) {
        continue;
    }

    $changed++;
    $missing[] = substr($file, strlen($root) + 1);

    if (!$check_only) {
        file_put_contents($file, $expected);
    }
}

if ($check_only) {
    if ($missing !== []) {
        printf("%d file(s) without the expected header:\n  - %s\n", count($missing), implode("\n  - ", $missing));
        exit(1);
    }
    printf("all %d files carry the expected header\n", count($files));
    exit(0);
}

printf("%d of %d file(s) updated\n", $changed, count($files));
