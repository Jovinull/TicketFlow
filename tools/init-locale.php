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
 * Creates or refreshes a translation catalogue from locales/ticketclock.pot.
 *
 * Existing translations are preserved: run it again after `extract-locales.php` and only
 * the new strings come through empty. `--identity` fills every entry with the source text,
 * which is how the English catalogue is produced.
 *
 *     php tools/init-locale.php fr_FR
 *     php tools/init-locale.php en_GB --identity
 */

$root = dirname(__DIR__);
$args = array_slice($argv, 1);
$identity = in_array('--identity', $args, true);
$args = array_values(array_filter($args, static fn(string $a): bool => !str_starts_with($a, '--')));
$language = $args[0] ?? '';

if (!preg_match('/^[a-z]{2}_[A-Z]{2}$/', $language)) {
    fwrite(STDERR, "Usage: php tools/init-locale.php <lang_CODE> [--identity]\n");
    fwrite(STDERR, "The code must match GLPI's own, e.g. en_GB, fr_FR, pt_BR, es_ES.\n");
    exit(1);
}

$pot_path = $root . '/locales/ticketclock.pot';
$po_path  = $root . '/locales/' . $language . '.po';

$pot = file_get_contents($pot_path);
if ($pot === false) {
    fwrite(STDERR, "Run tools/extract-locales.php first: {$pot_path} is missing.\n");
    exit(1);
}

/**
 * Existing translations, keyed by "context\x04msgid".
 *
 * @return array<string, list<string>>
 */
function read_existing(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }

    $out = [];
    $context = null;
    $msgid = null;
    $plural = false;
    $strings = [];

    $flush = static function () use (&$out, &$context, &$msgid, &$strings): void {
        if ($msgid !== null && $msgid !== '' && $strings !== []) {
            $out[($context ?? '') . "\x04" . $msgid] = array_values($strings);
        }
        $context = null;
        $msgid = null;
        $strings = [];
    };

    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            if ($line === '') {
                $flush();
            }
            continue;
        }
        if (preg_match('/^msgctxt "(.*)"$/', $line, $m)) {
            $flush();
            $context = $m[1];
        } elseif (preg_match('/^msgid "(.*)"$/', $line, $m)) {
            $msgid = $m[1];
            $plural = false;
        } elseif (preg_match('/^msgid_plural "(.*)"$/', $line)) {
            $plural = true;
        } elseif (preg_match('/^msgstr(?:\[(\d+)\])? "(.*)"$/', $line, $m)) {
            $index = $m[1] === '' || !isset($m[1]) ? 0 : (int) $m[1];
            if ($m[2] !== '') {
                $strings[$index] = $m[2];
            }
        }
    }
    $flush();

    return $out;
}

$existing = read_existing($po_path);

$lines = explode("\n", $pot);
$out = [];
$header_done = false;
$context = null;
$msgid = null;
$msgid_plural = null;
$kept = 0;
$empty = 0;

foreach ($lines as $line) {
    if (!$header_done) {
        if (str_starts_with($line, '"Project-Id-Version')) {
            $out[] = $line;
            $out[] = '"Language: ' . $language . '\n"';
            continue;
        }
        if ($line === '' && count($out) > 6) {
            $header_done = true;
        }
        $out[] = $line;
        continue;
    }

    if (preg_match('/^msgctxt "(.*)"$/', $line, $m)) {
        $context = $m[1];
        $out[] = $line;
        continue;
    }
    if (preg_match('/^msgid "(.*)"$/', $line, $m)) {
        $msgid = $m[1];
        $msgid_plural = null;
        $out[] = $line;
        continue;
    }
    if (preg_match('/^msgid_plural "(.*)"$/', $line, $m)) {
        $msgid_plural = $m[1];
        $out[] = $line;
        continue;
    }

    $key = ($context ?? '') . "\x04" . ($msgid ?? '');
    $known = $existing[$key] ?? null;

    if ($line === 'msgstr ""' && $msgid !== null && $msgid !== '') {
        $value = $known[0] ?? ($identity ? $msgid : '');
        $value === '' ? $empty++ : $kept++;
        $out[] = 'msgstr "' . $value . '"';
        $context = null;
        continue;
    }
    if (preg_match('/^msgstr\[(\d+)\] ""$/', $line, $m) && $msgid !== null) {
        $index = (int) $m[1];
        $fallback = $identity ? ($index === 0 ? $msgid : ($msgid_plural ?? $msgid)) : '';
        $value = $known[$index] ?? $fallback;
        $value === '' ? $empty++ : $kept++;
        $out[] = 'msgstr[' . $index . '] "' . $value . '"';
        continue;
    }

    $out[] = $line;
}

file_put_contents($po_path, implode("\n", $out));

printf(
    "%s: %d translated, %d empty -> %s\n",
    $language,
    $kept,
    $empty,
    substr($po_path, strlen($root) + 1),
);
