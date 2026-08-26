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

use Rector\CodeQuality\Rector\If_\ExplicitBoolCompareRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodParameterRector;
use Rector\DeadCode\Rector\MethodCall\RemoveNullNamedArgOnNullDefaultParamRector;
use Rector\DeadCode\Rector\If_\RemoveAlwaysTrueIfConditionRector;
use Rector\DeadCode\Rector\StmtsAwareInterface\RemoveJustPropertyFetchRector;
use Rector\DeadCode\Rector\Return_\RemoveDeadConditionAboveReturnRector;
use Rector\DeadCode\Rector\Stmt\RemoveUnreachableStatementRector;

/**
 * The official skeleton's config delegates to GLPI's own `PluginsRector.php`, which
 * GLPI 11.0.4 does not ship. This stands alone instead: the PHP level the plugin declares
 * (8.2, from composer.json) plus the code-quality sets, scoped to our own sources.
 *
 * Kept deliberately narrow. Rector runs in CI with --dry-run, so a set that rewrites
 * working code on a whim turns into a red build for no gain.
 */
return RectorConfig::configure()
    ->withBootstrapFiles([
        // Loads GLPI and steps around its legacy plugin autoloader; see the file.
        __DIR__ . '/tools/rector-bootstrap.php',
    ])
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/front',
        __DIR__ . '/tests',
        __DIR__ . '/tools',
        __DIR__ . '/setup.php',
        __DIR__ . '/hook.php',
    ])
    ->withSkip([
        __DIR__ . '/vendor',
        __DIR__ . '/var',

        // Rewrites a plain `=== null` into `! … instanceof \Fully\Qualified\Name`, inlining
        // the FQCN even where the class is already imported. Strictly harder to read for no
        // behavioural gain, and this codebase checks nullability constantly.
        FlipTypeControlToUseExclusiveTypeRector::class,

        // Rector's dead-code analysis does not follow variables captured by reference into
        // a closure: in tools/compile-locales.php it concludes the whole body of the
        // catalogue flush is unreachable and deletes it, which would silently break .mo
        // generation. Verified against Rector's own dry-run output.
        RemoveAlwaysTrueIfConditionRector::class => [
            __DIR__ . '/tools/compile-locales.php',
        ],
        RemoveUnreachableStatementRector::class => [
            __DIR__ . '/tools/compile-locales.php',
        ],

        // Every migration method shares one signature because Install dispatches them
        // uniformly; dropping the parameter from the ones that happen not to use it yet
        // would make that contract accidental.
        RemoveUnusedPrivateMethodParameterRector::class => [
            __DIR__ . '/src/Install.php',
        ],

        // In a test, `last_message: null` is the subject under test spelled out, not a
        // redundant argument. Removing it leaves a factory call whose relevance to the
        // assertion is invisible.
        RemoveNullNamedArgOnNullDefaultParamRector::class => [
            __DIR__ . '/tests',
        ],
    ])
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    );
