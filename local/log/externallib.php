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

class local_log_external extends external_api {

    /**
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function get_events_parameters() {
        return new external_function_parameters(array(
                        'userid' => new external_value(PARAM_INT, 'The user'),
                        'courseid' => new external_value(PARAM_INT, 'The course'),
                )
        );
    }

    /**
     * Returns welcome message
     * @return string welcome message
     */
    public static function get_events($userid,$courseid) {
        global $USER;

        //Parameter validation
        //REQUIRED
        $params = self::validate_parameters(self::get_events_parameters(),
                array('userid' => $userid,
                    'courseid' => $courseid
                ));

        //Context validation
        //OPTIONAL but in most web service it should present
        $context = get_context_instance(CONTEXT_USER, $USER->id);
        self::validate_context($context);
        $manager = get_log_manager();
        $readers = $manager->get_readers('\core\log\sql_reader');
        $reader = false;
        if ($readers) {
            $reader = reset($readers);
        }
        if ($reader){
            $events=$reader->get_events_select('userid = :userid AND courseid = :courseid',
                array('userid'=>$userid,'courseid'=>$courseid),'',0,0 );

            $data=[];
            foreach ($events as $id => $event){
                $data[$id]=$event->get_data();
            }
            return json_encode($data);
        }
        return '{}';
    }

    /**
     * Returns description of method result value
     * @return external_description
     */
    public static function get_events_returns() {
        return new external_value(PARAM_RAW, 'Data');
    }



}
