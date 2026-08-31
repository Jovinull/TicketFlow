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

namespace GlpiPlugin\Ticketclock\Engine;

use RuntimeException;

/**
 * Fills `{{placeholder}}` slots in a rule's message.
 *
 * Deliberately not a template engine: no expressions, no logic, no eval, no user-supplied
 * callables. Just a whitelist of names mapped to already-computed values, each escaped for
 * the HTML context a followup body lives in.
 *
 * Unknown placeholders are left untouched on purpose. Silently blanking them would hide
 * typos; leaving `{{tickte.name}}` visible in the timeline makes the mistake obvious the
 * first time the rule runs — and a dry run shows it before anything is written.
 */
final class MessageRenderer
{
    private const PATTERN = '/\{\{\s*([a-z0-9_.]+)\s*\}\}/i';

    /** @var array<string, string> */
    private array $values = [];

    /**
     * @param array<string, scalar|null> $values
     */
    public function __construct(array $values = [])
    {
        foreach ($values as $name => $value) {
            $this->set((string) $name, $value);
        }
    }

    public function set(string $name, mixed $value): self
    {
        $this->values[strtolower($name)] = $value === null ? '' : (string) $value;

        return $this;
    }

    /**
     * @return list<string> placeholder names available to a message
     */
    public function availableNames(): array
    {
        $names = array_keys($this->values);
        sort($names);

        return $names;
    }

    /**
     * @param bool $escape false for plain-text contexts such as a log line
     */
    public function render(string $template, bool $escape = true): string
    {
        // Not cast to string: preg_replace_callback() answers null on a PCRE failure such as
        // the backtrack limit, and casting that to a string would post an empty followup
        // onto somebody's ticket without a word of explanation. An exception is recorded
        // against the ticket in the execution log, which is the outcome that can be acted on.
        $rendered = preg_replace_callback(
            self::PATTERN,
            function (array $matches) use ($escape): string {
                $name = strtolower($matches[1]);
                if (!array_key_exists($name, $this->values)) {
                    return $matches[0];
                }

                $value = $this->values[$name];

                return $escape ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $value;
            },
            $template,
        );

        if ($rendered === null) {
            throw new RuntimeException('The message template could not be rendered (PCRE failure).');
        }

        return $rendered;
    }

    /**
     * Placeholder names used by a template that this renderer cannot fill.
     *
     * @return list<string>
     */
    public function unknownPlaceholders(string $template): array
    {
        $found = [];
        // A PCRE failure answers false, not 0, and leaves $matches unset -- reporting "no
        // unknown placeholders" for a template nobody managed to read would be a lie.
        $count = preg_match_all(self::PATTERN, $template, $matches);
        if ($count === false || $count === 0) {
            return [];
        }

        foreach ($matches[1] as $name) {
            $name = strtolower($name);
            if (!array_key_exists($name, $this->values) && !in_array($name, $found, true)) {
                $found[] = $name;
            }
        }

        return $found;
    }
}
