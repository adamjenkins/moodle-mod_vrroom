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

namespace mod_vrroom\local\template;

use core_course\local\entity\content_item;
use core_course\local\entity\string_title;

/**
 * Activity chooser entries: one per installed template.
 *
 * Core shows only the items a module's get_course_content_items() callback returns
 * (course/classes/local/repository/content_item_readonly_repository.php), so VR room appears in
 * the chooser once per template ("VR room: Gallery walk", "VR room: Escape chain", ...), each linking
 * to the activity form with the template already chosen.
 *
 * @package    mod_vrroom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class chooser {
    /**
     * One chooser item per installed template, in template name order.
     *
     * Item ids are 1 + the template's position in name order. A course-scoped default item links
     * to /course/mod.php, which redirects to modedit.php with a fixed parameter list and would
     * drop the template parameter, so course-scoped items link to /course/modedit.php directly
     * (the mod_lti precedent for preconfigured tools); the chooser appends the section and
     * beforemod parameters itself. Items without a course scope keep the default link.
     *
     * @param content_item $default The default content item core built for mod_vrroom.
     * @return content_item[]
     */
    public static function content_items(content_item $default): array {
        $items = [];
        $position = 0;
        foreach (registry::get_all() as $name => $template) {
            $link = self::link_for($default->get_link(), $name);
            $items[] = new content_item(
                id: $position + 1,
                name: 'vrroom_' . $name,
                title: new string_title(get_string('choosertitle', 'mod_vrroom', $template->get_display_name())),
                link: $link,
                icon: $default->get_icon(),
                help: $template->get_chooser_help() ?: $default->get_help(),
                archetype: $default->get_archetype(),
                componentname: $default->get_component_name(),
                purpose: $default->get_purpose(),
                branded: $default->is_branded(),
                gradable: $default->is_gradable(),
                otherpurpose: $default->get_other_purpose(),
                summary: $default->get_summary(),
            );
            $position++;
        }
        return $items;
    }

    /**
     * The creation link for one template.
     *
     * @param \moodle_url $defaultlink The default item's link.
     * @param string $name Template short name.
     * @return \moodle_url
     */
    private static function link_for(\moodle_url $defaultlink, string $name): \moodle_url {
        $courseid = (int)$defaultlink->param('id');
        if ($courseid > 0 && str_ends_with($defaultlink->get_path(), '/course/mod.php')) {
            $params = [
                'add' => 'vrroom',
                'return' => 0,
                'course' => $courseid,
            ];
            $sectionreturn = $defaultlink->param('sr');
            if ($sectionreturn !== null && $sectionreturn !== '') {
                $params['sr'] = (int)$sectionreturn;
            }
            $params['template'] = $name;
            return new \moodle_url('/course/modedit.php', $params);
        }
        $link = new \moodle_url($defaultlink);
        $link->param('template', $name);
        return $link;
    }
}
