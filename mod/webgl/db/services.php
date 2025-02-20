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
 * Webgl external functions and service definitions.
 *
 * @package    mod_webgl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$functions = array(
    'mod_webgl_signal_game_loaded' => array(
        'classname' => 'mod_webgl_external',
        'methodname' => 'signal_game_loaded',
        'classpath' => 'mod/webgl/externallib.php',
        'description' => 'Mark this game loaded and seen by the current user',
        'type' => 'write',
        'ajax'        => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE)
    ),
    'mod_webgl_signal_game_progress' => array(
        'classname' => 'mod_webgl_external',
        'methodname' => 'signal_game_progress',
        'classpath' => 'mod/webgl/externallib.php',
        'description' => 'Signal the game progress achieved by the current user',
        'type' => 'write',
        'ajax'        => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE)
    )
);
