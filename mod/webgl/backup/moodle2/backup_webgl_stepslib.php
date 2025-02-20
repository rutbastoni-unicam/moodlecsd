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

/**
 * @package    mod_webgl
 * @subpackage backup-moodle2
 * @copyright  2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Define all the backup steps that will be used by the backup_webgl_activity_task
 */

/**
 * Define the complete webgl structure for backup, with file and id annotations
 */
class backup_webgl_activity_structure_step extends backup_activity_structure_step {

    protected function define_structure()
    {
        // To know if we are including userinfo
        $userinfo = $this->get_setting_value('userinfo');

        // Define each element separated

        $webgl = new backup_nested_element('webgl', array('id'), array(
            'name', 'intro', 'introformat', 'webgl_file', 'storage_engine',
            'account_name', 'account_key', 'container_name', 'access_key',
            'secret_key', 'endpoint', 'bucket_name', 'cloudfront_url',
            'store_zip_file', 'index_file_url', 'iframe_height', 'iframe_width',
            'before_description', 'timecreated', 'timemodified', 'grade',
            'completionminimumscore', 'completionlevels', 'completionpuzzlesolve'));

        $achievements = new backup_nested_element('achievements');

        $achievement = new backup_nested_element('achievement', array('id'), array(
            'timecreated', 'timemodified', 'userid', 'score', 'completedlevels', 'solvedpuzzle'));

        // Build the tree

        $webgl->add_child($achievements);
        $achievements->add_child($achievement);

        // Define sources

        $webgl->set_source_table('webgl', array('id' => backup::VAR_ACTIVITYID));

        // All these source definitions only happen if we are including user info
        if ($userinfo) {
            $achievement->set_source_sql('
                SELECT *
                  FROM {webgl_achievements}
                 WHERE webgl = ?',
                array(backup::VAR_PARENTID));
        }

        // Define id annotations
        $achievement->annotate_ids('user', 'userid');

        // Define file annotations (files to restore)
        $webgl->annotate_files('mod_webgl', 'content', null);

        // Return the root element (webgl), wrapped into standard activity structure
        return $this->prepare_activity_structure($webgl);
    }
}

