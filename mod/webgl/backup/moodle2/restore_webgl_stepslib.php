<?php
/**
 * Structure step to restore one choice activity
 */
class restore_webgl_activity_structure_step extends restore_activity_structure_step {

    protected function define_structure() {

        $paths = array();
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('webgl', '/activity/webgl');
        if ($userinfo) {
            $paths[] = new restore_path_element('webgl_achievement', '/activity/webgl/achievements/achievement');
        }

        // Return the paths wrapped into standard activity structure
        return $this->prepare_activity_structure($paths);
    }

    protected function process_webgl($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        // insert the choice record
        $newitemid = $DB->insert_record('webgl', $data);
        // immediately after inserting "activity" record, call this
        $this->apply_activity_instance($newitemid);
    }

    protected function process_webgl_achievement($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->webgl = $this->get_new_parentid('webgl');
        $data->userid = $this->get_mappingid('user', $data->userid);

        $newitemid = $DB->insert_record('webgl_achievements', $data);
        $this->set_mapping('webgl_achievement', $oldid, $newitemid);
    }

    protected function after_execute() {
        global $DB;

        $this->add_related_files('mod_webgl', 'content', null);
    }
}

?>