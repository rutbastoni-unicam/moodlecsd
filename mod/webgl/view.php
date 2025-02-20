<?php
// This file is part of the Zoom plugin for Moodle - http://moodle.org/
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

/**
 * AWS webgl module version info
 *
 * @package mod_webgl
 * @copyright  2020 Brain station 23 ltd <>  {@link https://brainstation-23.com/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_course\output\activity_navigation;

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once(dirname(__FILE__) . '/lib.php');
require_once(dirname(__FILE__) . '/BlobStorage.php');
$id = optional_param('id', 0, PARAM_INT); // Course_module ID, or.
$n = optional_param('n', 0, PARAM_INT); // Webgl instance ID - it should be named as the first character of the module.

if ($id) {
    $cm = get_coursemodule_from_id('webgl', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
    $webgl = $DB->get_record('webgl', array('id' => $cm->instance), '*', MUST_EXIST);
} else if ($n) {
    $webgl = $DB->get_record('webgl', array('id' => $n), '*', MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $webgl->course), '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('webgl', $webgl->id, $course->id, false, MUST_EXIST);
} else {
    throw new Exception('You must specify a course_module ID or an instance ID');
}

require_login($course, true, $cm);

// Now it should be marked viewed from javascript, as soon as the Unity interface gets loaded
//webgl_view($course, $cm, $context, $webgl);

// Print the page header.

$PAGE->set_url('/mod/webgl/view.php', array('id' => $cm->id));
$PAGE->set_title(format_string($webgl->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_cacheable(false);
$PAGE->set_pagelayout('embedded');
$PAGE->requires->js_call_amd('mod_webgl/unitygame', 'init');
echo $OUTPUT->header();

$courseurl = new moodle_url('/course/view.php');
?>
<form action="<?php echo $courseurl; ?>" method="get" id="mod_webgl_course_url">
    <input type="hidden" name="id" value="<?php echo $course->id; ?>">
</form>

<?php

$iframe = '
<div class="webgl-iframe-content-loader" data-webgl="' . $webgl->id . '">
<iframe
width="100%"
height="100%"
frameborder="0"
src="' . $webgl->index_file_url . '" ></iframe>';
echo $iframe;

// Get a list of all the activities in the course.
$modules = get_fast_modinfo($course->id)->get_cms();

// Put the modules into an array in order by the position they are shown in the course.
$mods = [];
$activitylist = [];

$sectionnum = 0;

foreach ($modules as $module) {
    // Only add activities the user can access, aren't in stealth mode and have a url (eg. mod_label does not).
    if (!$module->uservisible || $module->is_stealth() || empty($module->url)) {
        continue;
    }
    $mods[$module->id] = $module;

    // No need to add the current module to the list for the activity dropdown menu.
    if ($module->id == $cm->id) {
        $sectionnum = $module->sectionnum;
        continue;
    }
    // Module name.
    $modname = $module->get_formatted_name();
    // Display the hidden text if necessary.
    if (!$module->visible) {
        $modname .= ' ' . get_string('hiddenwithbrackets');
    }
    // Module URL.
    $linkurl = new moodle_url($module->url, array('forceview' => 1));
    // Add module URL (as key) and name (as value) to the activity list array.
    $activitylist[$linkurl->out(false)] = $modname;
}

// Add back url to course and right section
$sectionurl = (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false) . '#section-' . $sectionnum;
// Prepend di un elemento con una chiave
$activitylist = [$sectionurl => get_string('gamebacktosection', 'webgl')] + $activitylist;
$nummods = count($mods);

// If there is only one mod then do nothing.
//if ($nummods == 1) {
//    return '';
//}

// Get an array of just the course module ids used to get the cmid value based on their position in the course.
$modids = array_keys($mods);

// Get the position in the array of the course module we are viewing.
$position = array_search($cm->id, $modids);

$prevmod = null;
$nextmod = null;

// Check if we have a previous mod to show.
if ($position > 0) {
    $prevmod = $mods[$modids[$position - 1]];
}

// Check if we have a next mod to show.
if ($position < ($nummods - 1)) {
    $nextmod = $mods[$modids[$position + 1]];
}

$activitynav = new activity_navigation($prevmod, $nextmod, $activitylist);
$renderer = $PAGE->get_renderer('core', 'course');
echo '</div>';
?>
    <div style="width:100%;
        background: #fff;
        padding: 12px;color: #424242;
        text-decoration: none;
        font-size: 15px;
        border-radius: 4px;">
        <?php echo $renderer->render($activitynav); ?>
    </div>
<?php
echo $OUTPUT->footer();
