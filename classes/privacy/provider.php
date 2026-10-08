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

namespace mod_mavr\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_mavr\local\attempt\event_store;
use mod_mavr\local\questions\usage;

/**
 * Privacy provider for mod_mavr.
 *
 * Personal data: the user's attempts (mavr_attempts), the statements recorded during them
 * (mavr_events), the answers stored by the question engine (one usage per attempt) and the
 * user's last position in a room (core xAPI state store, item id = module context id).
 *
 * @package    mod_mavr
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string This component. */
    private const COMPONENT = 'mod_mavr';

    /**
     * Describe the personal data MAVR stores.
     *
     * @param collection $collection the collection to add to
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('mavr_attempts', [
            'userid' => 'privacy:metadata:mavr_attempts:userid',
            'attempt' => 'privacy:metadata:mavr_attempts:attempt',
            'state' => 'privacy:metadata:mavr_attempts:state',
            'score' => 'privacy:metadata:mavr_attempts:score',
            'timestart' => 'privacy:metadata:mavr_attempts:timestart',
            'timefinish' => 'privacy:metadata:mavr_attempts:timefinish',
            'timedue' => 'privacy:metadata:mavr_attempts:timedue',
            'timemodified' => 'privacy:metadata:mavr_attempts:timemodified',
        ], 'privacy:metadata:mavr_attempts');

        $collection->add_database_table('mavr_events', [
            'verb' => 'privacy:metadata:mavr_events:verb',
            'objecttype' => 'privacy:metadata:mavr_events:objecttype',
            'objectid' => 'privacy:metadata:mavr_events:objectid',
            'resultjson' => 'privacy:metadata:mavr_events:resultjson',
            'clientasserted' => 'privacy:metadata:mavr_events:clientasserted',
            'timestamp' => 'privacy:metadata:mavr_events:timestamp',
            'timecreated' => 'privacy:metadata:mavr_events:timecreated',
        ], 'privacy:metadata:mavr_events');

        $collection->add_subsystem_link('core_question', [], 'privacy:metadata:core_question');
        $collection->add_subsystem_link('core_xapi', [], 'privacy:metadata:core_xapi');

        return $collection;
    }

    /**
     * The module contexts in which the user has MAVR data.
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
                  JOIN {mavr_attempts} a ON a.mavrid = cm.instance
                 WHERE a.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'mavr',
            'userid' => $userid,
        ]);

        \core_xapi\privacy\provider::add_contexts_for_userid($contextlist, $userid, self::COMPONENT);

        return $contextlist;
    }

    /**
     * The users who have MAVR data in a context.
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
                  JOIN {mavr_attempts} a ON a.mavrid = cm.instance
                 WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $sql, ['modname' => 'mavr', 'cmid' => $context->instanceid]);

        \core_xapi\privacy\provider::add_userids_for_context($userlist);
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
            $mavrid = self::get_mavrid($context);
            if ($mavrid === null) {
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

            $attempts = $DB->get_records('mavr_attempts', ['mavrid' => $mavrid, 'userid' => $userid], 'attempt ASC');
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
     * @param \stdClass $attempt mavr_attempts row
     * @return void
     */
    private static function export_attempt(\context $context, int $userid, \stdClass $attempt): void {
        $subcontext = [get_string('privacy:attemptpath', 'mod_mavr', $attempt->attempt)];

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
        $mavrid = self::get_mavrid($context);
        if ($mavrid === null) {
            return;
        }
        self::delete_attempts($mavrid, null);
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
            $mavrid = self::get_mavrid($context);
            if ($mavrid === null) {
                continue;
            }
            self::delete_attempts($mavrid, [$userid]);
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
        $mavrid = self::get_mavrid($context);
        if ($mavrid === null) {
            return;
        }
        $userids = array_map('intval', $userlist->get_userids());
        if (!$userids) {
            return;
        }
        self::delete_attempts($mavrid, $userids);
        \core_xapi\privacy\provider::delete_states_for_userlist($userlist, (int)$context->id);
    }

    /**
     * The mavr id behind a module context, or null when the context is not a MAVR activity.
     *
     * @param \context $context the context
     * @return int|null
     */
    private static function get_mavrid(\context $context): ?int {
        global $DB;
        if (!$context instanceof \context_module) {
            return null;
        }
        $sql = "SELECT cm.instance
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                 WHERE cm.id = :cmid";
        $instance = $DB->get_field_sql($sql, ['modname' => 'mavr', 'cmid' => $context->instanceid]);
        return $instance === false ? null : (int)$instance;
    }

    /**
     * Delete attempts with their question usages and statements.
     *
     * Direct deletes only: privacy requests must not trigger regrades or events.
     *
     * @param int $mavrid mavr id
     * @param int[]|null $userids users, or null for everyone
     * @return void
     */
    private static function delete_attempts(int $mavrid, ?array $userids): void {
        global $DB;
        $select = 'mavrid = :mavrid';
        $params = ['mavrid' => $mavrid];
        if ($userids !== null) {
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'user');
            $select .= " AND userid {$insql}";
            $params += $inparams;
        }
        $attempts = $DB->get_records_select('mavr_attempts', $select, $params, '', 'id, uniqueid');
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
        $DB->delete_records_select('mavr_attempts', $select, $params);
    }
}
