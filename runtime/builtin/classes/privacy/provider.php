<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace vrroomruntime_builtin\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for the VR room built-in viewer run mode.
 *
 * Personal data: the user's presence in a live room (vrroomruntime_builtin_peers, kept only while the
 * user is in the room) and the connection messages passed between the browsers of a room
 * (vrroomruntime_builtin_signals, deleted when delivered or after 60 seconds). In a live room the
 * browsers connect directly to each other, so the other participants (and any STUN or TURN server
 * the site sets up) receive the user's name, IP addresses, pose and voice: described as external
 * locations. The attempts themselves belong to mod_vrroom.
 *
 * Deletes use direct SQL so this provider does not depend on the live room classes.
 *
 * @package    vrroomruntime_builtin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string This component. */
    private const COMPONENT = 'vrroomruntime_builtin';

    /** @var string Live room presences. */
    private const TABLE_PEERS = 'vrroomruntime_builtin_peers';

    /** @var string Connection messages. */
    private const TABLE_SIGNALS = 'vrroomruntime_builtin_signals';

    /** @var int Peer ids per IN () chunk. */
    private const CHUNK = 500;

    /**
     * Describe the personal data of live rooms.
     *
     * @param collection $collection the collection to add to
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(self::TABLE_PEERS, [
            'userid' => 'privacy:metadata:peers:userid',
            'groupid' => 'privacy:metadata:peers:groupid',
            'room' => 'privacy:metadata:peers:room',
            'peerid' => 'privacy:metadata:peers:peerid',
            'kind' => 'privacy:metadata:peers:kind',
            'attemptid' => 'privacy:metadata:peers:attemptid',
            'timejoined' => 'privacy:metadata:peers:timejoined',
            'timeseen' => 'privacy:metadata:peers:timeseen',
            'lastsignal' => 'privacy:metadata:peers:lastsignal',
            'ratewindow' => 'privacy:metadata:peers:ratewindow',
            'pollcount' => 'privacy:metadata:peers:pollcount',
            'signalcount' => 'privacy:metadata:peers:signalcount',
        ], 'privacy:metadata:peers');

        $collection->add_database_table(self::TABLE_SIGNALS, [
            'frompeer' => 'privacy:metadata:signals:frompeer',
            'topeer' => 'privacy:metadata:signals:topeer',
            'type' => 'privacy:metadata:signals:type',
            'payload' => 'privacy:metadata:signals:payload',
            'timecreated' => 'privacy:metadata:signals:timecreated',
        ], 'privacy:metadata:signals');

        $collection->add_external_location_link('livepeers', [
            'fullname' => 'privacy:metadata:livepeers:fullname',
            'ipaddress' => 'privacy:metadata:livepeers:ipaddress',
            'pose' => 'privacy:metadata:livepeers:pose',
            'voice' => 'privacy:metadata:livepeers:voice',
        ], 'privacy:metadata:livepeers');

        $collection->add_external_location_link('iceservers', [
            'ipaddress' => 'privacy:metadata:iceservers:ipaddress',
        ], 'privacy:metadata:iceservers');

        return $collection;
    }

    /**
     * The module contexts of the activities in whose live rooms the user is.
     *
     * @param int $userid user id
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {" . self::TABLE_PEERS . "} p ON p.vrroomid = cm.instance
                 WHERE p.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'vrroom',
            'userid' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * The users in the live rooms of an activity.
     *
     * @param userlist $userlist the userlist to add to
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $sql = "SELECT p.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {" . self::TABLE_PEERS . "} p ON p.vrroomid = cm.instance
                 WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $sql, ['modname' => 'vrroom', 'cmid' => $context->instanceid]);
    }

    /**
     * Export the user's presence, the connection messages the user's browser sent and, without
     * their payload (the sender's data), the messages waiting to be delivered to the user.
     *
     * @param approved_contextlist $contextlist the approved contexts
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $vrroomid = self::get_vrroomid($context);
            if ($vrroomid === null) {
                continue;
            }
            $peers = $DB->get_records(self::TABLE_PEERS, ['vrroomid' => $vrroomid, 'userid' => $userid], 'id ASC');
            if (!$peers) {
                continue;
            }

            $presence = [];
            $peerids = [];
            foreach ($peers as $peer) {
                $peerids[] = (string)$peer->peerid;
                $presence[] = (object)[
                    'groupid' => (int)$peer->groupid,
                    'room' => (int)$peer->room,
                    'peerid' => (string)$peer->peerid,
                    'kind' => (string)$peer->kind,
                    'attemptid' => (int)$peer->attemptid,
                    'timejoined' => transform::datetime($peer->timejoined),
                    'timeseen' => transform::datetime($peer->timeseen),
                    'lastsignal' => empty($peer->lastsignal) ? null : transform::datetime($peer->lastsignal),
                    'ratewindow' => empty($peer->ratewindow) ? null : transform::datetime($peer->ratewindow),
                    'pollcount' => (int)$peer->pollcount,
                    'signalcount' => (int)$peer->signalcount,
                ];
            }

            $signals = [];
            [$insql, $inparams] = $DB->get_in_or_equal($peerids, SQL_PARAMS_NAMED, 'peer');
            $rows = $DB->get_records_select(
                self::TABLE_SIGNALS,
                "vrroomid = :vrroomid AND frompeer {$insql}",
                ['vrroomid' => $vrroomid] + $inparams,
                'id ASC',
                'id, topeer, type, payload, timecreated'
            );
            foreach ($rows as $row) {
                $signals[] = (object)[
                    'topeer' => (string)$row->topeer,
                    'type' => (string)$row->type,
                    'payload' => (string)$row->payload,
                    'timecreated' => transform::datetime($row->timecreated),
                ];
            }

            // Messages for the user: the payload holds the sender's network addresses, so it stays out.
            $received = [];
            $rows = $DB->get_records_select(
                self::TABLE_SIGNALS,
                "vrroomid = :vrroomid AND topeer {$insql}",
                ['vrroomid' => $vrroomid] + $inparams,
                'id ASC',
                'id, type, timecreated'
            );
            foreach ($rows as $row) {
                $received[] = (object)[
                    'type' => (string)$row->type,
                    'timecreated' => transform::datetime($row->timecreated),
                ];
            }

            writer::with_context($context)->export_data(
                [get_string('privacy:livepath', self::COMPONENT)],
                (object)['presence' => $presence, 'signals' => $signals, 'received' => $received]
            );
        }
    }

    /**
     * Delete every live room presence and message of an activity.
     *
     * @param \context $context the context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        $vrroomid = self::get_vrroomid($context);
        if ($vrroomid === null) {
            return;
        }
        self::delete_peers($vrroomid, null);
    }

    /**
     * Delete the user's live room data in the approved contexts.
     *
     * @param approved_contextlist $contextlist the approved contexts
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $vrroomid = self::get_vrroomid($context);
            if ($vrroomid === null) {
                continue;
            }
            self::delete_peers($vrroomid, [$userid]);
        }
    }

    /**
     * Delete the live room data of several users in one context.
     *
     * @param approved_userlist $userlist the approved users in a context
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $vrroomid = self::get_vrroomid($userlist->get_context());
        if ($vrroomid === null) {
            return;
        }
        $userids = array_map('intval', $userlist->get_userids());
        if (!$userids) {
            return;
        }
        self::delete_peers($vrroomid, $userids);
    }

    /**
     * The vrroom id behind a module context, or null when the context is not a VR room activity.
     *
     * @param \context $context the context
     * @return int|null
     */
    private static function get_vrroomid(\context $context): ?int {
        global $DB;
        if (!$context instanceof \context_module) {
            return null;
        }
        $sql = "SELECT cm.instance
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                 WHERE cm.id = :cmid";
        $instance = $DB->get_field_sql($sql, ['modname' => 'vrroom', 'cmid' => $context->instanceid]);
        return $instance === false ? null : (int)$instance;
    }

    /**
     * Delete presences of an activity with every message sent from or to them.
     *
     * @param int $vrroomid vrroom id
     * @param int[]|null $userids users, or null for everyone
     * @return void
     */
    private static function delete_peers(int $vrroomid, ?array $userids): void {
        global $DB;

        $select = 'vrroomid = :vrroomid';
        $params = ['vrroomid' => $vrroomid];
        if ($userids !== null) {
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'user');
            $select .= " AND userid {$insql}";
            $params += $inparams;
        }
        $peerids = array_values($DB->get_fieldset_select(self::TABLE_PEERS, 'peerid', $select, $params));

        if ($userids === null) {
            // Everything of the activity, including messages of presences already gone.
            $DB->delete_records(self::TABLE_SIGNALS, ['vrroomid' => $vrroomid]);
        }
        foreach (array_chunk($peerids, self::CHUNK) as $chunk) {
            [$fromsql, $fromparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'from');
            [$tosql, $toparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'to');
            $DB->delete_records_select(
                self::TABLE_SIGNALS,
                "frompeer {$fromsql} OR topeer {$tosql}",
                $fromparams + $toparams
            );
        }
        $DB->delete_records_select(self::TABLE_PEERS, $select, $params);
    }
}
