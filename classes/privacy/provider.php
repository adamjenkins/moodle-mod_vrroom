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

namespace mod_vrroom\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_vrroom\local\attempt\event_store;
use mod_vrroom\local\questions\usage;

/**
 * Privacy provider for mod_vrroom.
 *
 * Personal data: the user's attempts (vrroom_attempts), the statements recorded during them
 * (vrroom_events), the answers stored by the question engine (one usage per attempt) and the
 * user's last position in a room (core xAPI state store, item id = module context id).
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
    /** @var string[] The user preferences VR room stores. */
    private const PREFERENCES = ['mod_vrroom_invertx', 'mod_vrroom_inverty'];

    /** @var string This component. */
    private const COMPONENT = 'mod_vrroom';

    /**
     * Describe the personal data VR room stores.
     *
     * @param collection $collection the collection to add to
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        foreach (self::PREFERENCES as $name) {
            $collection->add_user_preference($name, 'privacy:metadata:preference:' . $name);
        }
        $collection->add_database_table('vrroom_attempts', [
            'userid' => 'privacy:metadata:vrroom_attempts:userid',
            'attempt' => 'privacy:metadata:vrroom_attempts:attempt',
            'state' => 'privacy:metadata:vrroom_attempts:state',
            'score' => 'privacy:metadata:vrroom_attempts:score',
            'timestart' => 'privacy:metadata:vrroom_attempts:timestart',
            'timefinish' => 'privacy:metadata:vrroom_attempts:timefinish',
            'timedue' => 'privacy:metadata:vrroom_attempts:timedue',
            'timemodified' => 'privacy:metadata:vrroom_attempts:timemodified',
        ], 'privacy:metadata:vrroom_attempts');

        $collection->add_database_table('vrroom_events', [
            'verb' => 'privacy:metadata:vrroom_events:verb',
            'objecttype' => 'privacy:metadata:vrroom_events:objecttype',
            'objectid' => 'privacy:metadata:vrroom_events:objectid',
            'resultjson' => 'privacy:metadata:vrroom_events:resultjson',
            'clientasserted' => 'privacy:metadata:vrroom_events:clientasserted',
            'timestamp' => 'privacy:metadata:vrroom_events:timestamp',
            'timecreated' => 'privacy:metadata:vrroom_events:timecreated',
        ], 'privacy:metadata:vrroom_events');

        $collection->add_subsystem_link('core_question', [], 'privacy:metadata:core_question');
        $collection->add_subsystem_link('core_xapi', [], 'privacy:metadata:core_xapi');

        return $collection;
    }

    /**
     * The module contexts in which the user has VR room data.
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
                  JOIN {vrroom_attempts} a ON a.vrroomid = cm.instance
                 WHERE a.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'vrroom',
            'userid' => $userid,
        ]);

        \core_xapi\privacy\provider::add_contexts_for_userid($contextlist, $userid, self::COMPONENT);

        return $contextlist;
    }

    /**
     * The users who have VR room data in a context.
     *
     * @param userlist $userlist the userlist to add to
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $sql = "SELECT a.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {vrroom_attempts} a ON a.vrroomid = cm.instance
                 WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $sql, ['modname' => 'vrroom', 'cmid' => $context->instanceid]);

        \core_xapi\privacy\provider::add_userids_for_context($userlist);
    }

    /**
     * Export the user's VR room preferences (reversed drag directions).
     *
     * @param int $userid the user
     * @return void
     */
    public static function export_user_preferences(int $userid): void {
        foreach (self::PREFERENCES as $name) {
            $value = get_user_preferences($name, null, $userid);
            if ($value !== null) {
                writer::export_user_preference(
                    self::COMPONENT,
                    $name,
                    transform::yesno((bool)$value),
                    get_string('privacy:metadata:preference:' . $name, self::COMPONENT)
                );
            }
        }
    }

    /**
     * Export the user's data in the approved contexts.
     *
     * @param approved_contextlist $contextlist the approved contexts
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $user = $contextlist->get_user();
        $userid = (int)$user->id;

        foreach ($contextlist->get_contexts() as $context) {
            $vrroomid = self::get_vrroomid($context);
            if ($vrroomid === null) {
                continue;
            }

            $contextdata = helper::get_context_data($context, $user);
            writer::with_context($context)->export_data([], $contextdata);
            helper::export_context_files($context, $user);

            $states = \core_xapi\privacy\provider::get_xapi_states_for_user($userid, self::COMPONENT, (int)$context->id);
            if ($states) {
                writer::with_context($context)->export_data(
                    [get_string('privacy:xapistate', 'core_xapi')],
                    (object)$states
                );
            }

            $attempts = $DB->get_records('vrroom_attempts', ['vrroomid' => $vrroomid, 'userid' => $userid], 'attempt ASC');
            foreach ($attempts as $attempt) {
                self::export_attempt($context, $userid, $attempt);
            }
        }
    }

    /**
     * Export one attempt: its data, its statements and its question usage.
     *
     * @param \context $context module context
     * @param int $userid the user being exported (the attempt owner)
     * @param \stdClass $attempt vrroom_attempts row
     * @return void
     */
    private static function export_attempt(\context $context, int $userid, \stdClass $attempt): void {
        $subcontext = [get_string('privacy:attemptpath', 'mod_vrroom', $attempt->attempt)];

        $data = (object)[
            'attempt' => (int)$attempt->attempt,
            'state' => (string)$attempt->state,
            'score' => $attempt->score === null ? null : (float)$attempt->score,
            'timestart' => transform::datetime($attempt->timestart),
            'timefinish' => empty($attempt->timefinish) ? null : transform::datetime($attempt->timefinish),
            'timedue' => empty($attempt->timedue) ? null : transform::datetime($attempt->timedue),
            'timemodified' => empty($attempt->timemodified) ? null : transform::datetime($attempt->timemodified),
        ];
        writer::with_context($context)->export_data($subcontext, $data);

        $statements = [];
        foreach (event_store::get_rows((int)$attempt->id) as $row) {
            $statements[] = (object)[
                'verb' => (string)$row->verb,
                'objecttype' => (string)$row->objecttype,
                'sceneid' => $row->sceneid,
                'objectid' => $row->objectid,
                'result' => $row->resultjson === null ? null : json_decode($row->resultjson),
                'clientasserted' => transform::yesno((int)$row->clientasserted === 1),
                'timestamp' => transform::datetime($row->timestamp),
                'timecreated' => transform::datetime($row->timecreated),
            ];
        }
        if ($statements) {
            writer::with_context($context)->export_related_data(
                $subcontext,
                'statements',
                (object)['statements' => $statements]
            );
        }

        if (!empty($attempt->uniqueid)) {
            $options = usage::display_options(true);
            $options->context = $context;
            \core_question\privacy\provider::export_question_usage(
                $userid,
                $context,
                $subcontext,
                (int)$attempt->uniqueid,
                $options,
                true
            );
        }
    }

    /**
     * Delete every user's data in a context.
     *
     * @param \context $context the context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        $vrroomid = self::get_vrroomid($context);
        if ($vrroomid === null) {
            return;
        }
        self::delete_attempts($vrroomid, null);
        \core_xapi\privacy\provider::delete_states_for_all_users($context, self::COMPONENT);
    }

    /**
     * Delete one user's data in the approved contexts.
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
            self::delete_attempts($vrroomid, [$userid]);
            // Scoped to this context: the state item id is the module context id.
            \core_xapi\privacy\provider::delete_states_for_user($contextlist, self::COMPONENT, (int)$context->id);
        }
    }

    /**
     * Delete the data of several users in one context.
     *
     * @param approved_userlist $userlist the approved users in a context
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        $vrroomid = self::get_vrroomid($context);
        if ($vrroomid === null) {
            return;
        }
        $userids = array_map('intval', $userlist->get_userids());
        if (!$userids) {
            return;
        }
        self::delete_attempts($vrroomid, $userids);
        \core_xapi\privacy\provider::delete_states_for_userlist($userlist, (int)$context->id);
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
     * Delete attempts with their question usages and statements.
     *
     * Direct deletes only: privacy requests must not trigger regrades or events.
     *
     * @param int $vrroomid vrroom id
     * @param int[]|null $userids users, or null for everyone
     * @return void
     */
    private static function delete_attempts(int $vrroomid, ?array $userids): void {
        global $DB;
        $select = 'vrroomid = :vrroomid';
        $params = ['vrroomid' => $vrroomid];
        if ($userids !== null) {
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'user');
            $select .= " AND userid {$insql}";
            $params += $inparams;
        }
        $attempts = $DB->get_records_select('vrroom_attempts', $select, $params, '', 'id, uniqueid');
        if (!$attempts) {
            return;
        }
        $qubaids = [];
        foreach ($attempts as $attempt) {
            if (!empty($attempt->uniqueid)) {
                $qubaids[] = (int)$attempt->uniqueid;
            }
        }
        usage::delete($qubaids);
        event_store::delete_for_attempts(array_keys($attempts));
        $DB->delete_records_select('vrroom_attempts', $select, $params);
    }
}
