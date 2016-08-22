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
 * Library function for reminders cron function.
 *
 * @package    local
 * @subpackage reminders
 * @copyright  2012 Isuru Madushanka Weerarathna, modified (Tasks and Events) by Mario Wehr
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Library function for reminders cron function.
 *
 * @package    local
 * @subpackage reminders
 * @copyright  2012 Isuru Madushanka Weerarathna
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot . '/local/reminders/reminder.class.php');
require_once($CFG->dirroot . '/local/reminders/contents/site_reminder.class.php');
require_once($CFG->dirroot . '/local/reminders/contents/user_reminder.class.php');
require_once($CFG->dirroot . '/local/reminders/contents/course_reminder.class.php');
require_once($CFG->dirroot . '/local/reminders/contents/group_reminder.class.php');
require_once($CFG->dirroot . '/local/reminders/contents/due_reminder.class.php');

require_once($CFG->dirroot . '/calendar/lib.php');
require_once($CFG->dirroot . '/group/lib.php');
require_once($CFG->libdir . '/accesslib.php');

require_once($CFG->dirroot . '/availability/classes/info_module.php');
require_once($CFG->libdir . '/modinfolib.php');

/// CONSTANTS ///////////////////////////////////////////////////////////

DEFINE('REMINDERS_DAYIN_SECONDS', 24 * 3600);

DEFINE('REMINDERS_FIRST_CRON_CYCLE_CUTOFF_DAYS', 5);

DEFINE('REMINDERS_7DAYSBEFORE_INSECONDS', 7*24*3600);
DEFINE('REMINDERS_3DAYSBEFORE_INSECONDS', 3*24*3600);
DEFINE('REMINDERS_1DAYBEFORE_INSECONDS', 24*3600);

DEFINE('REMINDERS_SEND_ALL_EVENTS', 50);
DEFINE('REMINDERS_SEND_ONLY_VISIBLE', 51);

DEFINE('REMINDERS_ACTIVITY_BOTH', 60);
DEFINE('REMINDERS_ACTIVITY_ONLY_OPENINGS', 61);
DEFINE('REMINDERS_ACTIVITY_ONLY_CLOSINGS', 62);

DEFINE('REMINDERS_SEND_AS_NO_REPLY', 70);
DEFINE('REMINDERS_SEND_AS_ADMIN', 71);

/// FUNCTIONS ///////////////////////////////////////////////////////////

/**
 * Function to be run periodically according to the moodle cron
 * Finds all events due for a reminder and send them out to the users.
 *
 */
function local_reminders_cron() {
    global $CFG, $DB, $LASTRUN;

    // 2.7 onwards we would like to be called from taks calls
    if (!defined('REMINDER_CALLED_FROM_TASK') AND $CFG->version > 2014051200){
        return;
    }

    if (!isset($CFG->local_reminders_enable) || !$CFG->local_reminders_enable) {
        mtrace("   [Local Reminder] This cron cycle will be skipped, because plugin is not enabled!");
        return;
    }

    $aheaddaysindex = array(7 => 0, 3 => 1, 1 => 2);

    $eventtypearray = array('site', 'user', 'course', 'due', 'group');

    // loading roles allowed to receive reminder messages from configuration
    //
    $allroles = get_all_roles();
    $courseroleids = array();
    if (!empty($allroles)) {
        $flag = 0;
        foreach ($allroles as $arole) {
            $roleoptionactivity = $CFG->local_reminders_activityroles;
            if (isset($roleoptionactivity[$flag]) && $roleoptionactivity[$flag] == '1') {
                $activityroleids[] = $arole->id;
            }
            $roleoption = $CFG->local_reminders_courseroles;
            if (isset($roleoption[$flag]) && $roleoption[$flag] == '1') {
                $courseroleids[] = $arole->id;
            }
            $flag++;
        }
    }

    if ($CFG->version <= 2014051200) { // Moodle 2.7+
        $totalcount = 0;
        $params = array();
        $select = "l.course = 0 AND l.module = 'local_reminders' AND l.action = 'cron'";
        $LASTRUN = get_logs($select, $params, 'l.time DESC', '', 1, $totalcount);
    }
    $timewindowstart = time();
    if (empty($LASTRUN) ) {  // this is the first cron cycle, after plugin is just installed
        mtrace("   [Local Reminder] This is the first cron cycle");
        $timewindowstart = $timewindowstart - REMINDERS_FIRST_CRON_CYCLE_CUTOFF_DAYS * 24 * 3600;
    } else {
        if ($CFG->version > 2014051200) { // Moodle 2.7+
            $timewindowstart = $LASTRUN + 1;
        }else{
            // info field includes that starting time of last cron cycle.
            $firstrecord = current($LASTRUN);
            $timewindowstart = $firstrecord->info + 1;
        }
    }

    // end of the time window will be set as current
    $timewindowend = time();

    // now lets filter appropiate events to send reminders
    //
    $secondsaheads = array(REMINDERS_7DAYSBEFORE_INSECONDS, REMINDERS_3DAYSBEFORE_INSECONDS,
        REMINDERS_1DAYBEFORE_INSECONDS);

    // append custom schedule if any of event categories has defined it.
    foreach ($eventtypearray as $etype) {
        $tempconfigstr = 'local_reminders_'.$etype.'custom';
        if (isset($CFG->$tempconfigstr) && !empty($CFG->$tempconfigstr)
            && $CFG->$tempconfigstr > 0 && !in_array($CFG->$tempconfigstr, $secondsaheads)) {
            array_push($secondsaheads, $CFG->$tempconfigstr);
        }
    }

    $whereclause = '(timestart > '.$timewindowend.') AND (';
    $flagor = false;
    foreach ($secondsaheads as $sahead) {
        if($flagor) {
            $whereclause .= ' OR ';
        }
        $whereclause .= '(timestart - '.$sahead.' >= '.$timewindowstart.' AND '.
            'timestart - '.$sahead.' <= '.$timewindowend.')';
        $flagor = true;
    }
    $whereclause .= ')';

    if (isset($CFG->local_reminders_filterevents)) {
        if ($CFG->local_reminders_filterevents == REMINDERS_SEND_ONLY_VISIBLE) {
            $whereclause .= 'AND visible = 1';
        }
    }

    mtrace("   [Local Reminder] Time window: ".userdate($timewindowstart)." to ".userdate($timewindowend));

    //mtrace("   [Local Reminder] Time window: ".$timewindowstart." to ".$timewindowend);
    //mtrace("   [Local Reminder] Where clause: ".$whereclause);

    $upcomingevents = $DB->get_records_select('event', $whereclause);
    if ($upcomingevents == false) {     // no upcoming events, so let's stop.
        mtrace("   [Local Reminder] No upcoming events. Aborting...");
        if ($CFG->version <= 2014051200) { // Moodle 2.7+
            add_to_log(0, 'local_reminders', 'cron', '', $timewindowend, 0, 0);
        }
        return;
    }

    mtrace("   [Local Reminder] Found ".count($upcomingevents)." upcoming events. Continuing...");

    $fromuser = get_admin();
    $fromuser->email = 'noreply@moodle.ut.ee';
    $fromuser->firstname = 'UT';
    $fromuser->lastname = 'Moodle';
    
    $failedcount = 0;
    $sendcount = 0;
    
    // iterating through each event...
    foreach ($upcomingevents as $event) {
        
        $course = $DB->get_record('course', array('id' => $event->courseid));
        if (!empty($course)) {    
            if (!$course->visible) {
                mtrace("   [Local Reminder] Skipping course #$event->courseid because it is in hidden state.");
                continue; // 16.09.2014 - Skip hidden courses.
            }
        }

        $event = \calendar_event::create($event, false);

        $aheadday = 0;

        $fromcustom = false;

        if ($event->timestart - REMINDERS_1DAYBEFORE_INSECONDS >= $timewindowstart && 
                $event->timestart - REMINDERS_1DAYBEFORE_INSECONDS <= $timewindowend) {
            $aheadday = 1;
        } else if ($event->timestart - REMINDERS_3DAYSBEFORE_INSECONDS >= $timewindowstart &&
            $event->timestart - REMINDERS_3DAYSBEFORE_INSECONDS <= $timewindowend) {
            $aheadday = 3;
        } else if ($event->timestart - REMINDERS_7DAYSBEFORE_INSECONDS >= $timewindowstart &&
            $event->timestart - REMINDERS_7DAYSBEFORE_INSECONDS <= $timewindowend) {
            $aheadday = 7;
        } else {
            // find if custom schedule has been defined by user...
            $tempconfigstr = 'local_reminders_'.$event->eventtype.'custom';
            if (isset($CFG->$tempconfigstr) && !empty($CFG->$tempconfigstr) && $CFG->$tempconfigstr > 0) {
                $customsecs = $CFG->$tempconfigstr;
                if ($event->timestart - $customsecs >= $timewindowstart &&
                    $event->timestart - $customsecs <= $timewindowend) {
                    $aheadday = $customsecs / (REMINDERS_DAYIN_SECONDS * 1.0);
                    mtrace($aheadday);
                    $fromcustom = true;
                }
            }
        }

        if ($aheadday == 0) continue;
        mtrace("   [Local Reminder] Processing event#$event->id [Type: $event->eventtype, inaheadof=$aheadday days]...");

        if (!$fromcustom) {
            $optionstr = 'local_reminders_' . $event->eventtype . 'rdays';
            if (!isset($CFG->$optionstr)) {
                if ($event->modulename) {
                    $optionstr = 'local_reminders_duerdays';
                } else {
                    mtrace("   [Local Reminder] Couldn't find option for event $event->id [type: $event->eventtype]");
                    continue;
                }
            }

            $options = $CFG->$optionstr;

            if (empty($options) || $options == null) {
                mtrace("   [Local Reminder] No configuration for eventtype $event->eventtype " .
                    "[event#$event->id is ignored!]...");
                continue;
            }

            // this reminder will not be set up to send by configurations
            if ($options[$aheaddaysindex[$aheadday]] == '0') {
                mtrace("   [Local Reminder] No reminder is due in ahead of $aheadday for eventtype $event->eventtype " .
                    "[event#$event->id is ignored!]...");
                continue;
            }

        } else {
            mtrace("   [Local Reminder] A reminder can be sent for event#$event->id ".
                    ", detected through custom schedule.");
        }

        $reminder = null;
        $eventdata = null;
        $sendusers = array();

        mtrace("   [Local Reminder] Finding out users for event#".$event->id."...");

        try {

            switch ($event->eventtype) {
                case 'site':
                    $reminder = new site_reminder($event, $aheadday);
                    $sendusers = $DB->get_records_sql("SELECT *
                                FROM {user}
                                WHERE id > 1 AND deleted=0 AND suspended=0 AND confirmed=1;");
                    $eventdata = $reminder->create_reminder_message_object($fromuser);

                    break;

                case 'user':
                    $user = $DB->get_record('user', array('id' => $event->userid));

                    if (!empty($user)) {
                        $reminder = new user_reminder($event, $user, $aheadday);
                        $eventdata = $reminder->create_reminder_message_object($fromuser);
                        $sendusers[] = $user;
                    }

                    break;

                case 'course':
                    $course = $DB->get_record('course', array('id' => $event->courseid));
                    $coursesettings = $DB->get_record('local_reminders_course', array('courseid'=>$event->courseid));
                    if (isset($coursesettings->status_course) && $coursesettings->status_course == 0) {
                        mtrace("  [Local Reminder] Reminder sending for course events has been restricted in the course specific configurations.");
                        break;
                    }

                    if (!empty($course)) {
                        $context = context_course::instance($course->id); //get_context_instance(CONTEXT_COURSE, $course->id);
                        $roleusers = get_role_users($courseroleids, $context, true, 'ra.id as ra_id, u.*');
                        $senduserids = array_map(function($u) { return $u->id; }, $roleusers);
                        $sendusers = array_combine($senduserids, $roleusers);

                        // create reminder object...
                        //
                        $reminder = new course_reminder($event, $course, $aheadday);
                        $eventdata = $reminder->create_reminder_message_object($fromuser);
                    }

                    break;

                case 'open':

                    // if we dont want to send reminders for activity openings...
                    //
                    if (isset($CFG->local_reminders_duesend) && $CFG->local_reminders_duesend == REMINDERS_ACTIVITY_ONLY_CLOSINGS) {
                        mtrace("  [Local Reminder] Reminder sending for activity openings has been restricted in the configurations.");
                        break;
                    }

                case 'close':

                    // if we dont want to send reminders for activity closings...
                    //
                    if (isset($CFG->local_reminders_duesend) && $CFG->local_reminders_duesend == REMINDERS_ACTIVITY_ONLY_OPENINGS) {
                        mtrace("  [Local Reminder] Reminder sending for activity closings has been restricted in the configurations.");
                        break;
                    }

                case 'due':

                    if (!isemptyString($event->modulename)) {
                        $course = $DB->get_record('course', array('id' => $event->courseid));
                        $cm = get_coursemodule_from_instance($event->modulename, $event->instance, $event->courseid);
                        $coursesettings = $DB->get_record('local_reminders_course', array('courseid'=>$event->courseid));
                        if (isset($coursesettings->status_activities) && $coursesettings->status_activities == 0) {
                            mtrace("  [Local Reminder] Reminder sending for activities has been restricted in the course specific configurations.");
                            break;
                        }

                        if (!empty($course) && !empty($cm)) {
                            $activityobj = fetch_module_instance($event->modulename, $event->instance, $event->courseid);
                            $context = \context_module::instance($cm->id);

                            // patch provided by Julien Boulen (jboulen)
                            // to prevent a user receives an alert for an activity that he can't see.
                            //
                            if ($cm->groupmembersonly === '0') {
                                $sendusers = get_role_users($activityroleids, $context, true, 'u.*');
                            } else {
                                $sendusers = groups_get_grouping_members($cm->groupingid);
                            }
                            $reminder = new \due_reminder($event, $course, $context, $aheadday);
                            $reminder->set_activity($event->modulename, $activityobj);
                            $eventdata = $reminder->create_reminder_message_object($fromuser);
                        }
                    }

                    break;

                case 'group':
                    $group = $DB->get_record('groups', array('id' => $event->groupid));

                    if (!empty($group)) {
                        $coursesettings = $DB->get_record('local_reminders_course', array('courseid'=>$group->courseid));
                        if (isset($coursesettings->status_group) && $coursesettings->status_group == 0) {
                            mtrace("  [Local Reminder] Reminder sending for group events has been restricted in the course specific configurations.");
                            break;
                        }

                        $reminder = new group_reminder($event, $group, $aheadday);

                        // add module details, if this event is a mod type event
                        //
                        if (!isemptyString($event->modulename) && $event->courseid > 0) {
                            $activityobj = fetch_module_instance($event->modulename, $event->instance, $event->courseid);
                            $reminder->set_activity($event->modulename, $activityobj);
                        }
                        $eventdata = $reminder->create_reminder_message_object($fromuser);

                        $sendusers = get_users_in_group($group);
                    }

                    break;

                default:
                    if (!isemptyString($event->modulename)) {
                        $course = $DB->get_record('course', array('id' => $event->courseid));
                        $cm = get_coursemodule_from_instance($event->modulename, $event->instance, $event->courseid);

                        if (!empty($course) && !empty($cm)) {
                            $activityobj = fetch_module_instance($event->modulename, $event->instance, $event->courseid);
                            $context = \context_module::instance($cm->id);
                            $sendusers = get_role_users($activityroleids, $context, true, 'u.*');

                            //$sendusers = get_enrolled_users($context, '', $event->groupid, 'u.*');
                            $reminder = new \due_reminder($event, $course, $context, $aheadday);
                            $reminder->set_activity($event->modulename, $activityobj);
                            $eventdata = $reminder->create_reminder_message_object($fromuser);
                        }
                    } else {
                        mtrace("  [Local Reminder] Unknown event type [$event->eventtype]");
                    }
            }

        } catch (Exception $ex) {
            mtrace("  [Local Reminder - ERROR] Error occured when initializing ".
                    "for event#[$event->id] (type: $event->eventtype) ".$ex->getMessage());
            mtrace("  [Local Reminder - ERROR] ".$ex->getTraceAsString());
            continue;
        }

        if ($eventdata == null) {
            mtrace("  [Local Reminder] Event object is not set for the event $event->id [type: $event->eventtype]");
            continue;
        }

        $usize = count($sendusers);
        if ($usize == 0) {
            mtrace("  [Local Reminder] No users found to send reminder for the event#$event->id");
            continue;
        }

        mtrace("  [Local Reminder] Starting sending reminders for $event->id [type: $event->eventtype]");
        $failedcount = 0;
        $sendcount = 0;


        $courseid = $event->courseid;
        if ($courseid > 0) {
            $coursecontext = context_course::instance($courseid);
            // 14.10.2014 - Reminders notification logging.
        }

        create_logfile('---Log time:'.date('d-m-y H:i:s').'<br />Sending init for eventid:'.$event->id.'(CourseID:'.$courseid.' Type:'.$event->eventtype.')!!!<br />', $event->eventtype);

        foreach ($sendusers as $touser) {

            if (isset($coursecontext) && !has_capability('local/reminders:notifications', $coursecontext, $touser)) {
                mtrace("   [Local Reminder] Skipping notification sending to $touser->firstname $touser->lastname because the capability for this role is disabled on course.");
                // 14.10.2014 - Reminders notification logging.
                create_logfile('CourseID:'.$courseid.'('.$course->shortname.')#Skipping sending to - '.$touser->firstname.' '.$touser->lastname.'('.$touser->username.') - .<br />', $event->eventtype);
                continue; // 17.09.2014 - Skipping notifications if teacher has disabled the capability on course.
            }

            create_logfile('CourseID:'.$courseid.'#Sending to - ' . $touser->firstname . ' ' . $touser->lastname . '(' . $touser->username . ') - .<br />', $event->eventtype);

            $eventdata = $reminder->set_sendto_user($touser);
            //$eventdata->userto = $touser;

            //foreach ($touser as $key => $value) {
            //    mtrace(" User: $key : $value");
            //}
            //$mailresult = 1; //message_send($eventdata);
            //mtrace("-----------------------------------");
            //mtrace($eventdata->fullmessagehtml);
            //mtrace("-----------------------------------");
            try {
                $mailresult = message_send($eventdata);
                mtrace('[LOCAL_REMINDERS] Mail Result: '.$mailresult);
                if (!$mailresult) {
                    throw new \coding_exception("Could not send out message for event#$event->id to user $eventdata->userto");
                }else{
                    $sendcount++;
                }
            } catch (moodle_exception $mex) {
                $failedcount++;
                mtrace('Error: local/reminders/lib.php local_reminders_cron(): '.$mex->getMessage());
            }
        }

        //14.10.2014 - Finish logging for course
        create_logfile('Sending finished for eventid:'.$event->id.'(CourseID:'.$courseid.' Type:'.$event->eventtype.')!!!<br />Log time:'.date('d-m-y H:i:s').'---<br />', $event->eventtype);

        if ($failedcount > 0) {
            mtrace("  [Local Reminder] Failed to send $failedcount reminders to users for event#$event->id");
        } else {
            mtrace("  [Local Reminder] All reminders was sent successfully for event#$event->id !");
        }

        unset($sendusers);

    }
    
    $event = \local_reminders\event\reminder_run::create(
        array(
            'contextid' => 1,
            'other' => array(
                'sendcount' => $sendcount,
                'failedcount' => $failedcount
            ))
    );
    $event->trigger();
    
}


//create log file
function create_logfile($string, $course = '', $type = 'student') {

    global $CFG;

    $path = $CFG->dirroot.'/local/reminders/logs';

    if (!is_dir($path)) {
        if (!mkdir($path, 0755)) {
            echo "failed to create dir";
        }
    }

    if ($fh = fopen($path.'/'.date("d_m_y").'_'.$type.'_logfile.log', 'a+')) {
        //$string = "Log time:".date('d-m-y H:i:s')."!---\nCourse: ".$course."\n".$string."Log end!---\n";
        fwrite($fh, utf8_decode(str_replace("<br />", "\n", $string)));
        fclose($fh);
        return true;
    } else {
        return false;
    }
}

/**
 * Returns all users belong to the given group.
 *
 * @param $group group object as received from db.
 * @return array users in an array
 */
function get_users_in_group($group) {
    global $DB;

    $sendusers = array();
    $groupmemberroles = groups_get_members_by_role($group->id, $group->courseid, 'u.id');
    if ($groupmemberroles) {
        foreach($groupmemberroles as $roleid => $roledata) {
            foreach($roledata->users as $member) {
                $sendusers[] = $DB->get_record('user', array('id' => $member->id));
            }
        }
    }
    return $sendusers;
}

/**
 * Adds a database record to local_reminders table, to mark
 * that the current cron cycle is over. Then we flag the time
 * of end of the cron time window, so that no reminders sent
 * twice.
 *
 * @param $timewindowend string cron window time end.
 * @param string $crontype type of reminders cron.
 */
function add_flag_record_db($timewindowend, $crontype = '') {
    global $DB;
}

/**
 * Function to retrive module instace from corresponding module
 * table. This function is written because when sending reminders
 * it can restrict showing some fields in the message which are sensitive
 * to user. (Such as some descriptions are hidden until defined date)
 * Function is very similar to the function in datalib.php/get_coursemodule_from_instance,
 * but by below it returns all fields of the module.
 *
 * Eg: can get the quiz instace from quiz table, can get the new assignment
 * instace from assign table, etc.
 *
 * @param string $modulename name of module type, eg. resource, assignment,...
 * @param int $instance module instance number (id in resource, assignment etc. table)
 * @param int $courseid optional course id for extra validation
 *
 * @return individual module instance (a quiz, a assignment, etc).
 *          If fails returns null
 */
function fetch_module_instance($modulename, $instance, $courseid=0) {
    global $DB;

    $params = array('instance'=>$instance, 'modulename'=>$modulename);

    $courseselect = "";

    if ($courseid) {
        $courseselect = "AND cm.course = :courseid";
        $params['courseid'] = $courseid;
    }

    $sql = "SELECT m.*
              FROM {course_modules} cm
                   JOIN {modules} md ON md.id = cm.module
                   JOIN {".$modulename."} m ON m.id = cm.instance
             WHERE m.id = :instance AND md.name = :modulename
                   $courseselect";

    try {
        return $DB->get_record_sql($sql, $params, IGNORE_MISSING);
    } catch (moodle_exception $mex) {
        mtrace('  [Local Reminder - ERROR] Failed to fetch module instance! '.$mex.getMessage);
        return null;
    }
}

/**
 * Returns true if input string is empty/whitespaces only, otherwise false.
 *
 * @param type $str string
 *
 * @return boolean true if string is empty or whitespace
 */
function isemptyString($str) {
    return !isset($str) || empty($str) || trim($str) === '';
}

// < 2.9 extends after 2.9 just extend..
function local_reminders_extends_settings_navigation($settingsnav, $context) {
    global $CFG, $PAGE;

    // Only add this settings item on non-site course pages.
    if (!$PAGE->course or $PAGE->course->id == 1) {
        return;
    }
 
    // Only let users with the appropriate capability see this settings item.
    if (!has_capability('moodle/course:update', context_course::instance($PAGE->course->id))) {
        return;
    }
 
    if ($settingnode = $settingsnav->find('courseadmin', navigation_node::TYPE_COURSE)) {
        $name = get_string('admintreelabel', 'local_reminders');
        $url = new moodle_url('/local/reminders/coursesettings.php', array('courseid' => $PAGE->course->id));
        $navnode = navigation_node::create(
            $name,
            $url,
            navigation_node::NODETYPE_LEAF,
            'reminders',
            'reminders',
            new pix_icon('i/calendar', $name)
        );
        if ($PAGE->url->compare($url, URL_MATCH_BASE)) {
            $navnode->make_active();
        }
        $settingnode->add_node($navnode);
    }
}
