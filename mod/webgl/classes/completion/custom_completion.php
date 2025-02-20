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

declare(strict_types=1);

namespace mod_webgl\completion;

use core_completion\activity_custom_completion;

/**
 * Activity custom completion subclass for the webgl activity.
 *
 * Class for defining mod_webgl's custom completion rules and fetching the completion statuses
 * of the custom completion rules for a given webgl instance and a user.
 *
 * @package mod_webgl
 * @copyright Simey Lameze <simey@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion
{

    /**
     * Fetches the completion state for a given completion rule.
     *
     * @param string $rule The completion rule.
     * @return int The completion state.
     */
    public function get_state(string $rule): int
    {
        global $DB;

        $this->validate_rule($rule);

        $userid = $this->userid;
        $webglid = $this->cm->instance;

        if (!$webgl = $DB->get_record('webgl', ['id' => $webglid])) {
            throw new \moodle_exception('Unable to find webgl with id ' . $webglid);
        }

        $webglachievementsparams = ['userid' => $userid, 'webgl' => $webglid];
        $webglachievement = $DB->get_record('webgl_achievements', $webglachievementsparams);

        $status = false;

        if ($webglachievement) {
            if ($rule == 'completionminimumscore') {
                $status = $webgl->completionminimumscore <= $webglachievement->score;
            } else if ($rule == 'completionlevels') {
                $status = $webgl->completionlevels <= $webglachievement->completedlevels;
            } else if ($rule == 'completionpuzzlesolved') {
                $status = $webgl->completionpuzzlesolved <= $webglachievement->solvedpuzzle;;
            }
        }


        return $status ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Fetch the list of custom completion rules that this module defines.
     *
     * @return array
     */
    public static function get_defined_custom_rules(): array
    {
        return [
            'completionminimumscore',
            'completionlevels',
            'completionpuzzlesolved',
        ];
    }

    /**
     * Returns an associative array of the descriptions of custom completion rules.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array
    {
        global $DB;

        $completionminimumscore = $this->cm->customdata['customcompletionrules']['completionminimumscore'] ?? 0;
        $completionlevels = $this->cm->customdata['customcompletionrules']['completionlevels'] ?? 0;
        $completionpuzzlesolved = $this->cm->customdata['customcompletionrules']['completionpuzzlesolved'] ?? 0;

        $userscore = 0;
        $userlevels = 0;

        $userid = $this->userid;
        $webglid = $this->cm->instance;

        if (!$webgl = $DB->get_record('webgl', ['id' => $webglid])) {
            throw new \moodle_exception('Unable to find webgl with id ' . $webglid);
        }

        $webglachievementsparams = ['userid' => $userid, 'webgl' => $webglid];
        $webglachievement = $DB->get_record('webgl_achievements', $webglachievementsparams);
        if ($webglachievement) {
            $userscore = $webglachievement->score;
            $userlevels = $webglachievement->completedlevels;
        }

        return [
            'completionminimumscore' => get_string('completiondetail:minimumscore', 'webgl', $userscore . '/' . $completionminimumscore),
            'completionlevels' => get_string('completiondetail:levels', 'webgl', $userlevels . '/' . $completionlevels),
            'completionpuzzlesolved' => get_string('completiondetail:puzzlesolved', 'webgl', $completionpuzzlesolved),
        ];
    }

    /**
     * Returns an array of all completion rules, in the order they should be displayed to users.
     *
     * @return array
     */
    public function get_sort_order(): array
    {
        return [
            'completionview',
            'completionminimumscore',
            'completionlevels',
            'completionpuzzlesolved'
        ];
    }
}
