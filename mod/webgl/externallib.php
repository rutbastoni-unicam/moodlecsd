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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External webgl API
 *
 * @package    mod_webgl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_webgl_external extends external_api
{
    private static function get_completion_info($cm)
    {
        global $PAGE, $USER;
        $completiondetails = \core_completion\cm_completion_details::get_instance($cm, $USER->id);
        $activitydates = \core\activity_dates::get_dates_for_module($cm, $USER->id);

        $activitycompletion = new \core_course\output\activity_completion($cm, $completiondetails);
        $activitycompletiondata = (array) $activitycompletion->export_for_template($PAGE->get_renderer('core'));
        $activitydates = new \core_course\output\activity_dates($activitydates);
        $activitydatesdata = (array) $activitydates->export_for_template($PAGE->get_renderer('core'));
        $data = array_merge($activitycompletiondata, $activitydatesdata);
        return $data;
    }
    /**
     * Mark this game loaded and seen by the current user
     *
     * @param int $webglid
     * @return bool
     */
    public static function signal_game_loaded(int $webglid) {
        global $DB, $CFG;
        require_once($CFG->dirroot . "/mod/webgl/lib.php");

        $params = self::validate_parameters(self::signal_game_loaded_parameters(), [
            'webglid' => $webglid
        ]);

        // Request and permission validation.
        $webgl = $DB->get_record('webgl', array('id' => $params['webglid']), '*', MUST_EXIST);
        list($course, $cm) = get_course_and_cm_from_instance($webgl, 'webgl');

        //TODO check if validate_contex is enough to ensure the user is enrolled in this course and if not, implement the check
        $context = context_module::instance($cm->id);
        self::validate_context($context);

        // Trigger course_module_viewed event.
        webgl_view($course, $cm, $context, $webgl);

//        // Get achievements record
//        $webgl_achievement = $DB->get_record('webgl_achievements', ['webgl' => $params['webglid'], 'userid' => $USER->id]);
        course_modinfo::purge_course_cache($course->id);
        list($courseBis, $cmBis) = get_course_and_cm_from_instance($webgl, 'webgl');
        $completiondata = self::get_completion_info($cmBis);

        return ['gameloadtracked' => true, 'completiondata' => $completiondata];
    }

    /**
     * Defines the parameters for the signal_game_loaded method
     *
     * @return external_function_parameters
     */
    public static function signal_game_loaded_parameters() {
        return new external_function_parameters(
            [
                'webglid' => new external_value(PARAM_INT, 'The game to mark as loaded by the user')
            ]
        );
    }

    /**
     * Returns description of method result value
     *
     * @return external_value
     */
    public static function signal_game_loaded_returns() {
        return new external_single_structure([
            'gameloadtracked' => new external_value(PARAM_BOOL, 'True if the game was successfully marked as loaded by the use'),
            'completiondata' => new external_single_structure([
                'cmid' => new external_value(PARAM_INT, 'Cm info id'),
                'activityname' => new external_value(PARAM_RAW, 'Activity name'),
                'uservisible' => new external_value(PARAM_BOOL, 'True if user is visible'),
                'hascompletion' => new external_value(PARAM_BOOL, 'True if has completion'),
                'isautomatic' => new external_value(PARAM_BOOL, 'True if it is automatic'),
                'ismanual' => new external_value(PARAM_BOOL, 'True if it is manual'),
                'showmanualcompletion' => new external_value(PARAM_BOOL, 'True if it shows manual completion'),
                'istrackeduser' => new external_value(PARAM_BOOL, 'True if it is a tracked user'),
                'overallcomplete' => new external_value(PARAM_BOOL, 'True if it is overall complete'),
                'overallincomplete' => new external_value(PARAM_BOOL, 'True if it is overall incomplete'),
                'overrideby' => new external_value(PARAM_RAW, 'Overridden by'),
                'accessibledescription' => new external_value(PARAM_RAW, 'Accessible description'),
                'hasdates' => new external_value(PARAM_BOOL, 'True if it has dates'),
                'completiondetails' => new external_multiple_structure(
                    new external_single_structure([
                        'description' => new external_value(PARAM_RAW, 'Description'),
                        'key' => new external_value(PARAM_RAW, 'Key'),
                        'statuscomplete' => new external_value(PARAM_BOOL, 'True if it is status complete'),
                        'statuscompletefail' => new external_value(PARAM_BOOL, 'True if it is status complete fail'),
                        'statuscompletepass' => new external_value(PARAM_BOOL, 'True if it is status complete pass'),
                        'statusincomplete' => new external_value(PARAM_BOOL, 'True if it is status incomplete'),
                    ])
                ),
                'activitydates' => new external_multiple_structure(
                    new external_single_structure([
                        'relativeto' => new external_value(PARAM_INT, 'Date relative to'),
                        'timestamp' => new external_value(PARAM_INT, 'Timestamp'),
                        'datestring' => new external_value(PARAM_RAW, 'Date formatted as string')
                    ])
                )
            ])
        ]);
    }

    public static function signal_game_progress($webglid, $score, $completedlevels, $puzzlesolved) {
        global $DB, $USER;

        $params = self::validate_parameters(self::signal_game_progress_parameters(), [
            'webglid' => $webglid,
            'score' => $score,
            'completedlevels' => $completedlevels,
            'puzzlesolved' => $puzzlesolved
        ]);

        // Request and permission validation.
        $webgl = $DB->get_record('webgl', array('id' => $params['webglid']), '*', IGNORE_MISSING);
        list($course, $cm) = get_course_and_cm_from_instance($webgl, 'webgl');

        //TODO check if validate_contex is enough to ensure the user is enrolled in this course and if not, implement the check
        $context = context_module::instance($cm->id);
        self::validate_context($context);

        // Get achievements record
        $webgl_achievement = $DB->get_record('webgl_achievements', ['webgl' => $params['webglid'], 'userid' => $USER->id]);

        $newachievement = false;
        if (!$webgl_achievement) {
            $newachievement = true;

            // User still without achievements
            $webgl_achievement = new stdClass();
            $webgl_achievement->webgl = $params['webglid'];
            $webgl_achievement->userid = $USER->id;

//            try {
//                return $DB->insert_record('local_message', $recordtoinsert);
//            } catch (dml_exception $e) {
//                return false;
//            }
        }

        //Update params only if values are passed to the service (cannot set to zero other previous values)
        if ($params['score'] > 0) {
            $webgl_achievement->score = $params['score'];
        }
        if ($params['completedlevels'] > 0) {
            $webgl_achievement->completedlevels = $params['completedlevels'];
        }
        if ($params['puzzlesolved'] > 0) {
            $webgl_achievement->solvedpuzzle = $params['puzzlesolved'];
        }

        $output = false;
        if ($newachievement) {
            $output = $DB->insert_record('webgl_achievements', $webgl_achievement, true);
            $webgl_achievement->id = $output;
        } else {
            $output = $DB->update_record('webgl_achievements', $webgl_achievement);
        }

        // Fire events to reflect the split..
        $params = array(
            'context' => $context,
            'objectid' => $webgl_achievement->id,
            'other' => array(
                'webglid' => $webgl->id,
            )
        );
        $event = \mod_webgl\event\game_progress_tracked::create($params);
        $event->add_record_snapshot('webgl', $webgl);
        $event->add_record_snapshot('webgl_achievements', $webgl_achievement);
        $event->trigger();

        $completion = new completion_info($course);
        if ($completion->is_enabled($cm) &&
            ($webgl->completionminimumscore || $webgl->completionlevels || $webgl->completionpuzzlesolved)) {
            $completion->update_state($cm, COMPLETION_COMPLETE);
        }

        // Get achievements infos
        course_modinfo::purge_course_cache($course->id);
        list($courseBis, $cmBis) = get_course_and_cm_from_instance($webgl, 'webgl');
        $completiondata = self::get_completion_info($cmBis);

        return ['gameprogresstracked' => true, 'completiondata' => $completiondata];
    }

    /**
     * Defines the parameters for the signal_game_progress method
     *
     * @return external_function_parameters
     */
    public static function signal_game_progress_parameters() {
        return new external_function_parameters(
            [
                'webglid' => new external_value(PARAM_INT, 'The game to mark as loaded by the user'),
                'score' => new external_value(PARAM_INT, 'The score achieved by the user in this game'),
                'completedlevels' => new external_value(PARAM_INT, 'The levels completed by the user in this game'),
                'puzzlesolved' => new external_value(PARAM_INT, 'Flag set if the user solved the puzzle represented in this game')
            ]
        );
    }

    /**
     * Returns description of method result value
     *
     * @return external_value
     */
    public static function signal_game_progress_returns() {
        return new external_single_structure([
            'gameprogresstracked' => new external_value(PARAM_BOOL, 'True if the game progress was successfully registered'),
            'completiondata' => new external_single_structure([
                'cmid' => new external_value(PARAM_INT, 'Cm info id'),
                'activityname' => new external_value(PARAM_RAW, 'Activity name'),
                'uservisible' => new external_value(PARAM_BOOL, 'True if user is visible'),
                'hascompletion' => new external_value(PARAM_BOOL, 'True if has completion'),
                'isautomatic' => new external_value(PARAM_BOOL, 'True if it is automatic'),
                'ismanual' => new external_value(PARAM_BOOL, 'True if it is manual'),
                'showmanualcompletion' => new external_value(PARAM_BOOL, 'True if it shows manual completion'),
                'istrackeduser' => new external_value(PARAM_BOOL, 'True if it is a tracked user'),
                'overallcomplete' => new external_value(PARAM_BOOL, 'True if it is overall complete'),
                'overallincomplete' => new external_value(PARAM_BOOL, 'True if it is overall incomplete'),
                'overrideby' => new external_value(PARAM_RAW, 'Overridden by'),
                'accessibledescription' => new external_value(PARAM_RAW, 'Accessible description'),
                'hasdates' => new external_value(PARAM_BOOL, 'True if it has dates'),
                'completiondetails' => new external_multiple_structure(
                    new external_single_structure([
                        'description' => new external_value(PARAM_RAW, 'Description'),
                        'key' => new external_value(PARAM_RAW, 'Key'),
                        'statuscomplete' => new external_value(PARAM_BOOL, 'True if it is status complete'),
                        'statuscompletefail' => new external_value(PARAM_BOOL, 'True if it is status complete fail'),
                        'statuscompletepass' => new external_value(PARAM_BOOL, 'True if it is status complete pass'),
                        'statusincomplete' => new external_value(PARAM_BOOL, 'True if it is status incomplete'),
                    ])
                ),
                'activitydates' => new external_multiple_structure(
                    new external_single_structure([
                        'relativeto' => new external_value(PARAM_INT, 'Date relative to'),
                        'timestamp' => new external_value(PARAM_INT, 'Timestamp'),
                        'datestring' => new external_value(PARAM_RAW, 'Date formatted as string')
                    ])
                )
            ])
        ]);
    }
}