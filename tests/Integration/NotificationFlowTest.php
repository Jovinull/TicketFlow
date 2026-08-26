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

namespace GlpiPlugin\Ticketclock\Tests\Integration;

use CommonITILActor;
use Config as CoreConfig;
use Group;
use Group_Ticket;
use GlpiPlugin\Ticketclock\Config;
use GlpiPlugin\Ticketclock\Engine\RuleEngine;
use GlpiPlugin\Ticketclock\Enum\ActionType;
use GlpiPlugin\Ticketclock\Rule;
use GlpiPlugin\Ticketclock\RuleAction;
use GlpiPlugin\Ticketclock\RuleGroup;
use Notification;
use PHPUnit\Framework\TestCase;
use QueuedNotification;
use Session;
use Ticket;
use User;
use UserEmail;

/**
 * The "raise a notification" action, end to end.
 *
 * This is the action that can fail without anybody noticing: a rule that solves a ticket
 * leaves a solution behind, but a notification that never left produces no artefact at all
 * on the ticket. So the assertion is on GLPI's own outgoing queue -- if a row lands there,
 * core accepted the event and resolved a recipient.
 *
 * Notifications are switched on for the duration of the test and put back afterwards; a
 * fresh GLPI ships with them off, which is why this was never exercised by accident.
 */
final class NotificationFlowTest extends TestCase
{
    private const EVENT = 'update';

    private int $groups_id = 0;
    private int $rules_id = 0;
    private int $requester_id = 0;
    private int $notifications_id = 0;

    /** @var array<string, mixed> */
    private array $core_config_before = [];
    private bool $notification_was_active = false;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
        Session::changeActiveEntities(0, true);

        $this->core_config_before = [
            'use_notifications'     => CoreConfig::getConfigurationValue('core', 'use_notifications'),
            'notifications_mailing' => CoreConfig::getConfigurationValue('core', 'notifications_mailing'),
        ];
        CoreConfig::setConfigurationValues('core', [
            'use_notifications'     => 1,
            'notifications_mailing' => 1,
        ]);

        // GLPI ships "Update Ticket" disabled. Arm it, and remember how it was found.
        $notification = new Notification();
        self::assertTrue(
            $notification->getFromDBByCrit(['itemtype' => 'Ticket', 'event' => self::EVENT]),
            'GLPI must ship a notification for the ticket update event',
        );
        $this->notifications_id = $notification->getID();
        $this->notification_was_active = (bool) $notification->fields['is_active'];
        $notification->update(['id' => $this->notifications_id, 'is_active' => 1]);

        $suffix = uniqid();

        // A recipient with a real address: no address, no queue row, and the test would
        // then be measuring the mailbox rather than the plugin.
        $this->requester_id = (int) (new User())->add([
            'name'         => 'ticketclock_notified_' . $suffix,
            'entities_id'  => 0,
            '_profiles_id' => 0,
        ]);
        self::assertGreaterThan(0, $this->requester_id);
        (new UserEmail())->add([
            'users_id'    => $this->requester_id,
            'is_default'  => 1,
            'email'       => 'ticketclock_' . $suffix . '@example.test',
        ]);

        $this->groups_id = (int) (new Group())->add([
            'name'        => 'TicketFlow notify group ' . $suffix,
            'entities_id' => 0,
            'is_assign'   => 1,
        ]);

        $rule = new Rule();
        $this->rules_id = (int) $rule->add([
            'name'          => 'TicketFlow notification rule',
            'entities_id'   => 0,
            'is_recursive'  => 1,
            'rule_type'     => 'pending_inactivity',
            'target_status' => Ticket::WAITING,
            'delay_value'   => 5,
            'delay_unit'    => 'calendar_days',
            'calendar_mode' => 'none',
            'reset_events'  => 'requester_followup',
        ]);
        $rule->update(['id' => $this->rules_id, 'is_active' => 1]);

        RuleGroup::setGroupsForRule($this->rules_id, [$this->groups_id]);
        RuleAction::setActionsForRule($this->rules_id, [
            'send_notification' => ['enabled' => 1, 'event' => self::EVENT],
        ]);

        Config::set(['execution_enabled' => 1, 'dry_run_global' => 0]);
    }

    protected function tearDown(): void
    {
        Config::set(['execution_enabled' => 0, 'dry_run_global' => 1]);

        if ($this->notifications_id > 0) {
            (new Notification())->update([
                'id'        => $this->notifications_id,
                'is_active' => (int) $this->notification_was_active,
            ]);
        }
        CoreConfig::setConfigurationValues('core', $this->core_config_before);

        parent::tearDown();
    }

    private function createPendingTicket(): int
    {
        $ticket = new Ticket();
        $tickets_id = (int) $ticket->add([
            'name'                => 'TicketFlow notification ticket',
            'content'             => 'waiting for the requester',
            'entities_id'         => 0,
            'status'              => Ticket::INCOMING,
            '_users_id_requester' => $this->requester_id,
        ]);
        self::assertGreaterThan(0, $tickets_id);

        (new Group_Ticket())->add([
            'tickets_id' => $tickets_id,
            'groups_id'  => $this->groups_id,
            'type'       => CommonITILActor::ASSIGN,
        ]);
        $ticket->update(['id' => $tickets_id, 'status' => Ticket::WAITING]);

        /** @var \DBmysql $DB */
        global $DB;
        $DB->update(
            Ticket::getTable(),
            ['begin_waiting_date' => date('Y-m-d H:i:s', strtotime('-30 days'))],
            ['id' => $tickets_id],
        );

        return $tickets_id;
    }

    private function queuedFor(int $tickets_id): int
    {
        return countElementsInTable(QueuedNotification::getTable(), [
            'itemtype' => 'Ticket',
            'items_id' => $tickets_id,
        ]);
    }

    private function runTheRule(): \GlpiPlugin\Ticketclock\Engine\RunReport
    {
        $rule = new Rule();
        self::assertTrue($rule->getFromDB($this->rules_id));

        return (new RuleEngine())->runRule($rule->toDefinition());
    }

    public function testAnExpiredTicketRaisesTheNotification(): void
    {
        $tickets_id = $this->createPendingTicket();
        $before = $this->queuedFor($tickets_id);

        $report = $this->runTheRule();

        self::assertSame(1, $report->executed, 'the rule must have acted on the ticket');
        self::assertGreaterThan(
            $before,
            $this->queuedFor($tickets_id),
            'raising the notification must put something in GLPI\'s outgoing queue',
        );
    }

    /**
     * The queued mail must be addressed and carry the ticket, not just exist.
     */
    public function testTheQueuedNotificationIsAddressedAndCarriesTheTicket(): void
    {
        $tickets_id = $this->createPendingTicket();
        $this->runTheRule();

        /** @var \DBmysql $DB */
        global $DB;
        $rows = iterator_to_array($DB->request([
            'FROM'  => QueuedNotification::getTable(),
            'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $tickets_id],
            'ORDER' => 'id DESC',
            'LIMIT' => 1,
        ]));
        self::assertCount(1, $rows, 'exactly one notification for this ticket');

        $row = reset($rows);
        self::assertNotSame('', (string) $row['recipient'], 'the notification has a recipient');
        self::assertStringContainsString('@', (string) $row['recipient']);
        self::assertNotSame('', (string) $row['name'], 'the notification has a subject');
    }

    /**
     * A dry run must describe the notification without raising it.
     */
    public function testADryRunQueuesNothing(): void
    {
        Config::set(['dry_run_global' => 1]);

        $tickets_id = $this->createPendingTicket();
        $before = $this->queuedFor($tickets_id);

        $report = $this->runTheRule();

        self::assertSame(0, $report->executed);
        self::assertSame(1, $report->simulated);
        self::assertSame($before, $this->queuedFor($tickets_id), 'a simulation must send nothing');
    }
}
