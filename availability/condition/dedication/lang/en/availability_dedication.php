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
 * Language strings.
 *
 * @package availability_dedication
 * @copyright 2015 Daniel Neis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['ajaxerror'] = 'Error contacting server';
$string['pluginname'] = 'Dedication';
$string['title'] = 'Minimun dedication';
$string['description'] = 'Prevent access if the user didnt has a minimun dedication to the course.';
$string['eitherdescription'] = 'you have not reached the minimun {$a->dedication} (you have dedicated {$a->actual})';
$string['eithernotdescription'] = 'you have reached the minimun {$a->dedication} hours (you have {$a->actual} hours)';
$string['fieldlabel'] = 'Minimun dedication (H) / Limit (m):';
$string['validnumber'] = 'You must use a H/M format where H is minimun dedication float Hours and M is limit int minutes';
