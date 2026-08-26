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
 * @link      https://github.com/Jovinull/ticketflow
 * -------------------------------------------------------------------------
 */

// Run from a GLPI root: `php plugins/ticketflow/tools/seed-demo.php`.
// GLPI_ROOT lets a container that keeps GLPI elsewhere point this at it.
$glpi_root = getenv('GLPI_ROOT') ?: dirname(__DIR__, 3);
chdir($glpi_root);
require_once $glpi_root . '/vendor/autoload.php';
(new Glpi\Kernel\Kernel())->boot();
Plugin::load('ticketflow', true);

$_SESSION['glpicronuserrunning'] = 'cron_demo_seed';
$_SESSION['glpi_currenttime']    = date('Y-m-d H:i:s');
$_SESSION['glpiactive_entity']   = 0;
$_SESSION['glpiactiveentities']  = [0];
$_SESSION['glpiactiveentities_string'] = "'0'";
$_SESSION['glpiactiveprofile']   = ['id' => 4, 'interface' => 'central'];
$_SESSION['glpiname']            = 'demo-seed';
$_SESSION['glpiID']              = 2;
$_SESSION['glpi_use_mode']       = 0;

/**
 * Add a record, or reuse the one already carrying that name.
 *
 * Re-running this script has to be harmless: a demo instance gets re-seeded whenever the
 * screenshots are refreshed, and failing halfway through on "that user already exists"
 * leaves the instance in a state nobody asked for.
 */
function ent(string $class, array $input): int
{
    $o = new $class();

    if (isset($input['name'])) {
        /** @var DBmysql $DB */
        global $DB;
        // Not getFromDBByCrit(): it throws when a name is not unique, and an instance that
        // has been seeded twice by hand is exactly the case this guard is here for.
        $existing = $DB->request([
            'SELECT' => 'id',
            'FROM'   => $o::getTable(),
            'WHERE'  => ['name' => $input['name']],
            'ORDER'  => 'id',
            'LIMIT'  => 1,
        ])->current();

        if ($existing !== null) {
            return (int) $existing['id'];
        }
    }

    $id = $o->add($input);
    if (!$id) {
        fwrite(STDERR, "failed to add $class\n");
        exit(1);
    }
    return (int) $id;
}

$eng = ent(Group::class, [
    'name'         => 'Software Engineering',
    'entities_id'  => 0,
    'is_recursive' => 1,
    'is_assign'    => 1,
    'comment'      => 'Demo group used by the TicketFlow example rules.',
]);
ent(Group::class, [
    'name'         => 'Service Desk',
    'entities_id'  => 0,
    'is_recursive' => 1,
    'is_assign'    => 1,
]);

$dev = ent(User::class, [
    'name' => 'a.developer', 'realname' => 'Developer', 'firstname' => 'Alex',
    'password' => 'DemoPass#2026', 'password2' => 'DemoPass#2026',
    '_profiles_id' => 6, '_entities_id' => 0,
]);
$req = ent(User::class, [
    'name' => 'c.requester', 'realname' => 'Requester', 'firstname' => 'Chris',
    'password' => 'DemoPass#2026', 'password2' => 'DemoPass#2026',
    '_profiles_id' => 1, '_entities_id' => 0,
]);
if (countElementsInTable(Group_User::getTable(), ['groups_id' => $eng, 'users_id' => $dev]) === 0) {
    ent(Group_User::class, ['groups_id' => $eng, 'users_id' => $dev]);
}

$titles = [
    'Nightly export job fails on the reporting database',
    'Invoice PDF renders with the wrong currency symbol',
    'Single sign-on redirects to the login page in a loop',
    'API rate limit reached during the weekly import',
    'Search returns no result for hyphenated part numbers',
    'Timesheet approval e-mail is never delivered',
];
$now  = new DateTimeImmutable('now');
$made = [];
foreach ($titles as $i => $title) {
    $opened = $now->sub(new DateInterval('P' . (12 + $i * 2) . 'D'))->format('Y-m-d H:i:s');
    $tid = (new Ticket())->add([
        'name'                => $title,
        'content'             => 'Demo ticket created to illustrate TicketFlow. No real data involved.',
        'entities_id'         => 0,
        'type'                => Ticket::INCIDENT_TYPE,
        'date'                => $opened,
        '_users_id_requester' => $req,
        '_groups_id_assign'   => $eng,
        '_users_id_assign'    => $dev,
        'status'              => CommonITILObject::ASSIGNED,
    ]);
    if (!$tid) {
        fwrite(STDERR, "ticket failed\n");
        exit(1);
    }
    $made[] = [(int) $tid, $opened];
}

global $DB;
foreach ($made as [$tid, $opened]) {
    $when = (new DateTimeImmutable($opened))->add(new DateInterval('P1D'))->format('Y-m-d H:i:s');
    $ok = (new ITILFollowup())->add([
        'itemtype'   => 'Ticket',
        'items_id'   => $tid,
        'content'    => 'We need a confirmation from your side before we can continue.',
        'users_id'   => $dev,
        'is_private' => 0,
        'date'       => $when,
        '_do_not_compute_status' => true,
        '_no_reopen'             => true,
    ]);
    if (!$ok) {
        fwrite(STDERR, "followup failed on $tid\n");
        exit(1);
    }

    (new Ticket())->update([
        'id' => $tid, 'status' => CommonITILObject::WAITING, '_do_not_compute_status' => true,
    ]);

    // Backdate the whole trail. The rule reads the last message *and* the last status
    // change, so the history rows have to move with the ticket or the reference date
    // lands on today and nothing is ever overdue.
    $DB->update('glpi_tickets', ['begin_waiting_date' => $when, 'date_mod' => $when], ['id' => $tid]);
    $DB->update('glpi_itilfollowups', ['date' => $when, 'date_creation' => $when], [
        'itemtype' => 'Ticket', 'items_id' => $tid,
    ]);
    $DB->update('glpi_logs', ['date_mod' => $when], ['itemtype' => 'Ticket', 'items_id' => $tid]);
}


$common = [
    'entities_id'           => 0,
    'is_recursive'          => 1,
    'is_dry_run'            => 1,
    'pendingreasons_id'     => 0,
    'delay_unit'            => 'business_days',
    'calendar_mode'         => 'entity',
    'calendars_id'          => 0,
    '__groups_id_defined'   => 1,
    '_groups_id'            => [$eng],
    '_reset_events_defined' => 1,
];

$first = (new GlpiPlugin\Ticketflow\Rule())->add($common + [
    'name'          => 'Pending without an answer from Software Engineering',
    'comment'       => 'The clock starts on the last message, and only while that message came from the group. Any reply from somebody else stops the rule.',
    'ranking'       => 10,
    'rule_type'     => 'pending_inactivity',
    'target_status' => CommonITILObject::WAITING,
    'start_event'   => 'last_target_group_message',
    'delay_value'   => 5,
    '_reset_events' => ['requester_followup'],
    '_actions'      => [
        'add_followup' => [
            'enabled'    => 1,
            'is_private' => 0,
            'content'    => "This ticket has been waiting for an answer for {{delay}} {{delay_unit}} (deadline: {{deadline}}).\n\nWithout a reply it will be solved automatically.",
        ],
        'final' => [
            'type'             => 'add_solution',
            'content'          => 'Solved automatically by TicketFlow: no answer on ticket {{ticket.id}} since {{reference}}.',
            'solutiontypes_id' => 0,
        ],
    ],
]);

$second = (new GlpiPlugin\Ticketflow\Rule())->add($common + [
    'name'          => 'Approval left undecided for two business days',
    'comment'       => 'Reminder only. Never changes the ticket status.',
    'ranking'       => 20,
    'rule_type'     => 'pending_approval',
    'target_status' => CommonITILObject::APPROVAL,
    'start_event'   => 'pending_start',
    'delay_value'   => 2,
    '_reset_events' => ['validation_answered'],
    '_actions'      => [
        'add_followup' => [
            'enabled' => 1,
            'content' => 'A decision is still pending on this approval request ({{delay}} {{delay_unit}}).',
        ],
    ],
]);

// A rule is always created inactive, on purpose. Arm the first one so the demo has
// something to show; the second stays inactive, which is also worth showing.
(new GlpiPlugin\Ticketflow\Rule())->update(['id' => $first, 'is_active' => 1]);

$report = (new GlpiPlugin\Ticketflow\Engine\RuleEngine())->runAll();
echo 'group ' . $eng . ', tickets ' . implode(',', array_column($made, 0))
    . ", rules $first,$second — " . json_encode($report->counters()) . "\n";
