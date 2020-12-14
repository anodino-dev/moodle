<?php

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
 * External Web Service Template
 *
 * @package    localwstemplate
 * @copyright  2011 Moodle Pty Ltd (http://moodle.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . "/externallib.php");
require_once($CFG->libdir . "/filelib.php");

class local_files_external extends external_api {

    /**
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function delete_parameters() {
        return new external_function_parameters(
            array(
                'contextid' => new external_value(PARAM_INT, 'context id', VALUE_DEFAULT, null),
                'component' => new external_value(PARAM_COMPONENT, 'component'),
                'filearea'  => new external_value(PARAM_AREA, 'file area'),
                'contextlevel' => new external_value(PARAM_ALPHA, 'The context level to put the file in,
                        (block, course, coursecat, system, user, module)', VALUE_DEFAULT, null),
                'instanceid' => new external_value(PARAM_INT, 'The Instance id of item associated
                         with the context level', VALUE_DEFAULT, null)
            )
            );
    }
    /**
     * Returns welcome message
     * @return string welcome message
     */
    public static function delete($contextid,$component,$filearea,$contextlevel,$instanceid) {
        global $USER;

        //Parameter validation
        //REQUIRED
        $parameters = array(
            'contextid'    => $contextid,
            'component'    => $component,
            'filearea'     => $filearea,
            'contextlevel' => $contextlevel,
            'instanceid'   => $instanceid);
        $fileinfo = self::validate_parameters(self::delete_parameters(), $parameters);
        
        $browser = get_file_browser();
        
        // We need to preserve backwards compatibility. Zero will use the system context and minus one will
        // use the addtional parameters to determine the context.
        // TODO MDL-40489 get_context_from_params should handle this logic.
        if ($fileinfo['contextid'] == 0) {
            $context = context_system::instance();
        } else {
            if ($fileinfo['contextid'] == -1) {
                $fileinfo['contextid'] = null;
            }
            $context = self::get_context_from_params($fileinfo);
        }
        self::validate_context($context);
        error_log("*********".print_r($context,true));
        
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, $fileinfo['component'], $fileinfo['filearea']);
//         $res=array();
//         foreach ($files as $f) {
//             // $f is an instance of stored_file
//             $res[]=$f->get_filename();
//             $f->delete();
//         }
        return "1";
    }

    /**
     * Returns description of method result value
     * @return external_description
     */
    public static function delete_returns() {
        return new external_value(PARAM_RAW, 'Data');
    }



}
