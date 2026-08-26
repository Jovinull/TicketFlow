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
 * Compiles locales/*.po into the .mo files GLPI actually loads.
 *
 * `msgfmt` from gettext does the same job and is preferred when it is installed; this
 * exists so building the plugin never depends on a system package being present, which
 * matters for CI images and for Windows contributors.
 *
 *     php tools/compile-locales.php
 */

$root = dirname(__DIR__);
$dir  = $root . '/locales';

/**
 * Read a .po file into a msgid => msgstr map, following the GNU conventions:
 * contexts are joined with \x04 and plurals with \0.
 *
 * @return array<string, string>
 */
function parse_po(string $path): array
{
    $entries = [];

    $context = null;
    $msgid = null;
    $msgid_plural = null;
    /** @var array<int, string> */
    $msgstr = [];
    $current = null;

    $flush = static function () use (&$entries, &$context, &$msgid, &$msgid_plural, &$msgstr): void {
        if ($msgid === null) {
            return;
        }

        $key = $msgid;
        if ($msgid_plural !== null) {
            $key .= "\0" . $msgid_plural;
        }
        if ($context !== null) {
            $key = $context . "\x04" . $key;
        }

        ksort($msgstr);
        $value = implode("\0", $msgstr);

        // An empty translation means "not translated"; storing it would shadow the
        // original string with an empty one at runtime.
        if ($value !== '' || $msgid === '') {
            $entries[$key] = $value;
        }

        $context = null;
        $msgid = null;
        $msgid_plural = null;
        $msgstr = [];
    };

    $unescape = (static fn(string $raw): string => stripcslashes($raw));

    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);

        if ($line === '') {
            $flush();
            $current = null;
            continue;
        }

        if (str_starts_with($line, '#')) {
            continue;
        }

        if (preg_match('/^msgctxt\s+"(.*)"$/', $line, $m)) {
            $flush();
            $context = $unescape($m[1]);
            $current = 'ctxt';
            continue;
        }

        if (preg_match('/^msgid\s+"(.*)"$/', $line, $m)) {
            if ($msgid !== null && $msgstr !== []) {
                $flush();
            }
            $msgid = $unescape($m[1]);
            $current = 'id';
            continue;
        }

        if (preg_match('/^msgid_plural\s+"(.*)"$/', $line, $m)) {
            $msgid_plural = $unescape($m[1]);
            $current = 'id_plural';
            continue;
        }

        if (preg_match('/^msgstr\[(\d+)\]\s+"(.*)"$/', $line, $m)) {
            $index = (int) $m[1];
            $msgstr[$index] = $unescape($m[2]);
            $current = 'str' . $index;
            continue;
        }

        if (preg_match('/^msgstr\s+"(.*)"$/', $line, $m)) {
            $msgstr[0] = $unescape($m[1]);
            $current = 'str0';
            continue;
        }

        // Continuation line of the previous keyword.
        if (preg_match('/^"(.*)"$/', $line, $m)) {
            $chunk = $unescape($m[1]);
            match (true) {
                $current === 'ctxt'      => $context .= $chunk,
                $current === 'id'        => $msgid .= $chunk,
                $current === 'id_plural' => $msgid_plural .= $chunk,
                str_starts_with((string) $current, 'str') => $msgstr[(int) substr((string) $current, 3)]
                    = ($msgstr[(int) substr((string) $current, 3)] ?? '') . $chunk,
                default => null,
            };
        }
    }

    $flush();

    return $entries;
}

/**
 * @param array<string, string> $entries
 */
function write_mo(string $path, array $entries): void
{
    ksort($entries, SORT_STRING);

    $count = count($entries);
    $originals = [];
    $translations = [];

    // Header: magic, revision, count, offset of the two tables, hash size, hash offset.
    $header_size = 7 * 4;
    $table_size  = $count * 8;
    $offset = $header_size + 2 * $table_size;

    $original_blob = '';
    foreach (array_keys($entries) as $key) {
        $originals[] = [strlen($key), $offset + strlen($original_blob)];
        $original_blob .= $key . "\0";
    }

    $offset += strlen($original_blob);

    $translation_blob = '';
    foreach ($entries as $value) {
        $translations[] = [strlen($value), $offset + strlen($translation_blob)];
        $translation_blob .= $value . "\0";
    }

    $mo = pack(
        'VVVVVVV',
        0x950412de,          // little-endian magic
        0,                   // revision
        $count,
        $header_size,        // offset of the originals table
        $header_size + $table_size,
        0,                   // hash table size (unused)
        $header_size + 2 * $table_size,
    );

    foreach ($originals as [$length, $position]) {
        $mo .= pack('VV', $length, $position);
    }
    foreach ($translations as [$length, $position]) {
        $mo .= pack('VV', $length, $position);
    }

    $mo .= $original_blob . $translation_blob;

    file_put_contents($path, $mo);
}

$files = glob($dir . '/*.po') ?: [];
if ($files === []) {
    fwrite(STDERR, "No .po file found in {$dir}\n");
    exit(1);
}

foreach ($files as $po) {
    $entries = parse_po($po);
    $mo = preg_replace('/\.po$/', '.mo', $po);
    write_mo($mo, $entries);

    printf("%s -> %s (%d entries)\n", basename($po), basename((string) $mo), count($entries));
}
