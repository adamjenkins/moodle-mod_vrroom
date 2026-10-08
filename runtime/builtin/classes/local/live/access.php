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

namespace vrroomruntime_builtin\local\live;

use mod_vrroom\local\attempt\attempt_manager;
use mod_vrroom\local\live_settings;

/**
 * Who may join which live room, and the live block of the launch config.
 *
 * Live rooms belong to an activity, or to one group of it when the activity uses groups, and are
 * numbered from 1: a fixed number of rooms (live mode 1) or rooms students open up to a maximum
 * (live mode 2). Learners join only rooms of groups they are members of, in separate and in visible groups
 * mode; people with moodle/site:accessallgroups may join any group's room. A learner needs an
 * attempt in progress; a teacher previewing needs mod/vrroom:manage.
 *
 * @package    vrroomruntime_builtin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class access {
    /** @var string Peer kind of a learner with an attempt. */
    public const KIND_ATTEMPT = 'attempt';

    /** @var string Peer kind of a teacher preview. */
    public const KIND_PREVIEW = 'preview';

    /** @var string Transport of the built-in live mode. */
    public const TRANSPORT = 'mesh';

    /** @var string Room mode: a fixed number of rooms. */
    public const ROOMMODE_FIXED = 'fixed';

    /** @var string Room mode: rooms students open. */
    public const ROOMMODE_DYNAMIC = 'dynamic';

    /**
     * Load an activity by course module id.
     *
     * @param int $cmid Course module id.
     * @return array{0: \cm_info, 1: \context_module, 2: \stdClass} [cm, context, vrroom]
     */
    public static function load_by_cmid(int $cmid): array {
        global $DB;
        [, $cm] = get_course_and_cm_from_cmid($cmid, 'vrroom');
        $vrroom = $DB->get_record('vrroom', ['id' => $cm->instance], '*', MUST_EXIST);
        return [$cm, \context_module::instance($cm->id), $vrroom];
    }

    /**
     * Load an activity by its vrroom id.
     *
     * @param int $vrroomid vrroom id.
     * @return array{0: \cm_info, 1: \context_module, 2: \stdClass} [cm, context, vrroom]
     * @throws \dml_missing_record_exception when the activity no longer exists
     */
    public static function load_by_vrroomid(int $vrroomid): array {
        global $DB;
        $vrroom = $DB->get_record('vrroom', ['id' => $vrroomid], '*', MUST_EXIST);
        $rawcm = get_coursemodule_from_instance('vrroom', $vrroomid, (int)$vrroom->course, false, MUST_EXIST);
        $cm = get_fast_modinfo((int)$rawcm->course)->get_cm((int)$rawcm->id);
        return [$cm, \context_module::instance($cm->id), $vrroom];
    }

    /**
     * Whether live mode is available in an activity.
     *
     * @param \stdClass $vrroom vrroom row.
     * @return bool
     */
    public static function is_available(\stdClass $vrroom): bool {
        return config::is_enabled()
            && in_array((int)($vrroom->livemode ?? 0), [live_settings::MODE_FIXED, live_settings::MODE_DYNAMIC], true)
            && (string)($vrroom->runtime ?? '') === 'builtin';
    }

    /**
     * The room mode of an activity.
     *
     * @param \stdClass $vrroom vrroom row.
     * @return string ROOMMODE_DYNAMIC when students open rooms, else ROOMMODE_FIXED.
     */
    public static function room_mode(\stdClass $vrroom): string {
        return (int)($vrroom->livemode ?? 0) === live_settings::MODE_DYNAMIC ? self::ROOMMODE_DYNAMIC : self::ROOMMODE_FIXED;
    }

    /**
     * The number of rooms: fixed rooms 1..N (at least 1), or the most rooms students may open (0 = no limit).
     *
     * @param \stdClass $vrroom vrroom row.
     * @return int
     */
    public static function max_rooms(\stdClass $vrroom): int {
        return live_settings::clean_rooms(
            $vrroom->liverooms ?? live_settings::DEFAULT_ROOMS,
            (int)($vrroom->livemode ?? live_settings::MODE_OFF)
        );
    }

    /**
     * The plain label of a room: "Room 2", or "Group A: Room 2" in group mode.
     *
     * @param \cm_info $cm Course module.
     * @param \context_module $context Module context.
     * @param int $groupid The room's group, 0 without groups.
     * @param int $room Room number.
     * @return string
     */
    public static function room_label(\cm_info $cm, \context_module $context, int $groupid, int $room): string {
        if ($groupid > 0) {
            $group = groups_get_group($groupid, 'id, name, courseid');
            if ($group && (int)$group->courseid === (int)$cm->course) {
                return get_string('liveroomnamegroup', 'vrroomruntime_builtin', [
                    'group' => self::plain((string)$group->name, $context),
                    'number' => $room,
                ]);
            }
        }
        return get_string('liveroomname', 'vrroomruntime_builtin', $room);
    }

    /**
     * The room size of an activity, capped at what the mesh supports.
     *
     * @param \stdClass $vrroom vrroom row.
     * @return int
     */
    public static function max_peers(\stdClass $vrroom): int {
        return min(room_manager::MAX_PEERS, max(2, (int)($vrroom->livemaxusers ?? room_manager::MAX_PEERS)));
    }

    /**
     * Check that a user may join as a learner with an attempt or as a teacher previewing.
     *
     * @param \cm_info $cm Course module.
     * @param \context_module $context Module context.
     * @param \stdClass $vrroom vrroom row.
     * @param int $attemptid The attempt in progress, or 0 for a preview.
     * @param int $userid The user.
     * @return array{kind: string, attemptid: int}
     * @throws \moodle_exception errorlivenotallowed, or mod_vrroom's attempt errors
     */
    public static function resolve_kind(
        \cm_info $cm,
        \context_module $context,
        \stdClass $vrroom,
        int $attemptid,
        int $userid
    ): array {
        if ($attemptid > 0) {
            [$attempt] = attempt_manager::require_own_open_attempt($attemptid, $userid);
            if ((int)$attempt->vrroomid !== (int)$vrroom->id) {
                throw new \moodle_exception('errorlivenotallowed', 'vrroomruntime_builtin');
            }
            return ['kind' => self::KIND_ATTEMPT, 'attemptid' => (int)$attempt->id];
        }
        if (!has_capability('mod/vrroom:manage', $context, $userid)) {
            throw new \moodle_exception('errorlivenotallowed', 'vrroomruntime_builtin');
        }
        return ['kind' => self::KIND_PREVIEW, 'attemptid' => 0];
    }

    /**
     * The groups whose rooms a user may join.
     *
     * @param \cm_info $cm Course module.
     * @param \context_module $context Module context.
     * @param int $userid The user.
     * @return array<int, string> groupid => plain name, ordered by name; [] when the activity has no group mode
     */
    public static function allowed_groups(\cm_info $cm, \context_module $context, int $userid): array {
        if (groups_get_activity_groupmode($cm) == NOGROUPS) {
            return [];
        }
        $member = has_capability('moodle/site:accessallgroups', $context, $userid) ? 0 : $userid;
        $groups = groups_get_all_groups((int)$cm->course, $member, (int)$cm->groupingid, 'g.id, g.name', false, true);
        $names = [];
        foreach ($groups as $group) {
            $names[(int)$group->id] = self::plain((string)$group->name, $context);
        }
        \core_collator::asort($names);
        return $names;
    }

    /**
     * The group whose room a user joins.
     *
     * @param \cm_info $cm Course module.
     * @param \context_module $context Module context.
     * @param int $userid The user.
     * @param int $requested The group asked for, 0 for the default.
     * @return int The group id, 0 without group mode.
     * @throws \moodle_exception errorlivenoroom
     */
    public static function resolve_group(\cm_info $cm, \context_module $context, int $userid, int $requested): int {
        if (groups_get_activity_groupmode($cm) == NOGROUPS) {
            return 0;
        }
        $groups = self::allowed_groups($cm, $context, $userid);
        if (!$groups) {
            throw new \moodle_exception('errorlivenoroom', 'vrroomruntime_builtin');
        }
        if ($requested > 0) {
            if (!array_key_exists($requested, $groups)) {
                throw new \moodle_exception('errorlivenoroom', 'vrroomruntime_builtin');
            }
            return $requested;
        }
        return self::default_group($cm, $groups);
    }

    /**
     * Whether a presence may stay in its room (checked on every poll and send).
     *
     * A fixed room above the current number of rooms (the teacher lowered it) is closed; lowering
     * the maximum of rooms students open closes nobody.
     *
     * @param \stdClass $peer vrroomruntime_builtin_peers row.
     * @param \cm_info $cm Course module.
     * @param \context_module $context Module context.
     * @param \stdClass $vrroom vrroom row.
     * @return bool
     */
    public static function still_allowed(\stdClass $peer, \cm_info $cm, \context_module $context, \stdClass $vrroom): bool {
        global $DB;
        if ((int)$peer->vrroomid !== (int)$vrroom->id || !self::is_available($vrroom)) {
            return false;
        }
        $room = (int)($peer->room ?? 0);
        if ($room < 1 || $room > room_manager::MAX_ROOM_NUMBER) {
            return false;
        }
        if (self::room_mode($vrroom) === self::ROOMMODE_FIXED && $room > self::max_rooms($vrroom)) {
            return false;
        }
        $userid = (int)$peer->userid;
        if ($peer->kind === self::KIND_ATTEMPT) {
            $attempt = $DB->get_record('vrroom_attempts', ['id' => (int)$peer->attemptid]);
            if (
                !$attempt || (int)$attempt->userid !== $userid || (int)$attempt->vrroomid !== (int)$vrroom->id
                    || $attempt->state !== attempt_manager::STATE_INPROGRESS || attempt_manager::is_overdue($attempt)
            ) {
                return false;
            }
            if (!has_capability('mod/vrroom:attempt', $context, $userid)) {
                return false;
            }
        } else if ($peer->kind === self::KIND_PREVIEW) {
            if (!has_capability('mod/vrroom:manage', $context, $userid)) {
                return false;
            }
        } else {
            return false;
        }

        $groupid = (int)$peer->groupid;
        $grouped = groups_get_activity_groupmode($cm) != NOGROUPS;
        if (!$grouped) {
            return $groupid === 0;
        }
        return $groupid > 0 && array_key_exists($groupid, self::allowed_groups($cm, $context, $userid));
    }

    /**
     * The live block of the launch config (PHASE3-CONTRACT §3.1, LIVE-ROOMS-CONTRACT §6.1).
     *
     * @param \cm_info $cm Course module.
     * @param \context_module $context Module context.
     * @param \stdClass $vrroom vrroom row.
     * @param \stdClass|null $attempt The attempt, or null for a preview.
     * @param \stdClass $user The user launching.
     * @return array
     */
    public static function launch_block(
        \cm_info $cm,
        \context_module $context,
        \stdClass $vrroom,
        ?\stdClass $attempt,
        \stdClass $user
    ): array {
        $block = [
            'available' => false,
            'canjoin' => false,
            'reason' => '',
            'transport' => self::TRANSPORT,
            'transportmodule' => null,
            'cmid' => (int)$cm->id,
            'attemptid' => $attempt ? (int)$attempt->id : 0,
            'maxpeers' => 0,
            'groups' => [],
            'defaultgroupid' => 0,
            'selfname' => '',
            'roomlist' => false,
            'roommode' => self::ROOMMODE_FIXED,
            'maxrooms' => self::max_rooms($vrroom),
        ];
        if (!self::is_available($vrroom)) {
            return $block;
        }
        $userid = (int)$user->id;
        $block['available'] = true;
        $block['maxpeers'] = self::max_peers($vrroom);
        $block['selfname'] = fullname($user);
        $block['roomlist'] = true;
        $block['roommode'] = self::room_mode($vrroom);

        if ($attempt) {
            $mayjoin = (int)$attempt->userid === $userid && (int)$attempt->vrroomid === (int)$vrroom->id
                && $attempt->state === attempt_manager::STATE_INPROGRESS && !attempt_manager::is_overdue($attempt);
        } else {
            $mayjoin = has_capability('mod/vrroom:manage', $context, $userid);
        }

        $groupok = true;
        if (groups_get_activity_groupmode($cm) != NOGROUPS) {
            $groups = self::allowed_groups($cm, $context, $userid);
            foreach ($groups as $id => $name) {
                $block['groups'][] = ['id' => $id, 'name' => $name];
            }
            if ($groups) {
                $block['defaultgroupid'] = self::default_group($cm, $groups);
            } else {
                $groupok = false;
            }
        }

        $block['canjoin'] = $mayjoin && $groupok;
        if ($mayjoin && !$groupok) {
            $block['reason'] = 'nogroup';
        }
        return $block;
    }

    /**
     * The plain name of a room: the group's name, or the activity's name without groups.
     *
     * @param \cm_info $cm Course module.
     * @param \context_module $context Module context.
     * @param \stdClass $vrroom vrroom row.
     * @param int $groupid The room's group, 0 without groups.
     * @return string
     */
    public static function room_name(\cm_info $cm, \context_module $context, \stdClass $vrroom, int $groupid): string {
        if ($groupid > 0) {
            $group = groups_get_group($groupid, 'id, name, courseid');
            if ($group && (int)$group->courseid === (int)$cm->course) {
                return self::plain((string)$group->name, $context);
            }
        }
        return self::plain((string)$vrroom->name, $context);
    }

    /**
     * The default room among the allowed groups: the active group when allowed, else the first.
     *
     * @param \cm_info $cm Course module.
     * @param array $groups Allowed groups (groupid => name), not empty.
     * @return int
     */
    private static function default_group(\cm_info $cm, array $groups): int {
        $active = (int)groups_get_activity_group($cm);
        if ($active > 0 && array_key_exists($active, $groups)) {
            return $active;
        }
        return (int)array_key_first($groups);
    }

    /**
     * A plain-text label: filtered, tags stripped, entities decoded (the client escapes it).
     *
     * @param string $text
     * @param \context_module $context
     * @return string
     */
    private static function plain(string $text, \context_module $context): string {
        return html_entity_decode(
            strip_tags(format_string($text, true, ['context' => $context, 'escape' => false])),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    }
}
