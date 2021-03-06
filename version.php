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
 * Reminder plugin version information
 *
 * @package    local
 * @subpackage reminders
 * @copyright  2012 Isuru Madushanka Weerarathna, modified (Tasks and Events) by Mario Wehr
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2015090500;
$plugin->requires  = 2015051100;        // require moodle 2.9 or higher
$plugin->release   = '1.4.2';
$plugin->maturity  = MATURITY_RC;
$plugin->component = 'local_reminders';

global $CFG;
if ($CFG->version > 2014051200) {
    $plugin->cron = 86400;  // Default: 900, will run for 15-minutes, 86400 if we use tasks
}else{
    $plugin->cron = 900;
}


