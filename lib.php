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

/// CONSTANTS ///////////////////////////////////////////////////////////

DEFINE('REMINDERS_FIRST_CRON_CYCLE_CUTOFF_DAYS', 2);

DEFINE('REMINDERS_7DAYSBEFORE_INSECONDS', 7*24*3600);
DEFINE('REMINDERS_3DAYSBEFORE_INSECONDS', 3*24*3600);
DEFINE('REMINDERS_1DAYBEFORE_INSECONDS', 24*3600);

DEFINE('REMINDERS_SEND_ALL_EVENTS', 50);
DEFINE('REMINDERS_SEND_ONLY_VISIBLE', 51);

DEFINE('REMINDERS_ACTIVITY_BOTH', 60);
DEFINE('REMINDERS_ACTIVITY_ONLY_OPENINGS', 61);
DEFINE('REMINDERS_ACTIVITY_ONLY_CLOSINGS', 62);

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

    // loading roles allowed to receive reminder messages from configuration
    //
    $allroles = get_all_roles();
    $courseroleids = array();
    if (!empty($allroles)) {
        $flag = 0;
        foreach ($allroles as $arole) {
            $roleoptionactivity = $CFG->local_reminders_activityroles;
            if ($roleoptionactivity[$flag] == '1') {
                $activityroleids[] = $arole->id;
            }
            $roleoption = $CFG->local_reminders_courseroles;
            if ($roleoption[$flag] == '1') {
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

    // iterating through each event...
    foreach ($upcomingevents as $event) {
        $event = \calendar_event::create($event, false);

        $aheadday = 0;

        if ($event->timestart - REMINDERS_1DAYBEFORE_INSECONDS >= $timewindowstart &&
            $event->timestart - REMINDERS_1DAYBEFORE_INSECONDS <= $timewindowend) {
            $aheadday = 1;
        } else if ($event->timestart - REMINDERS_3DAYSBEFORE_INSECONDS >= $timewindowstart &&
            $event->timestart - REMINDERS_3DAYSBEFORE_INSECONDS <= $timewindowend) {
            $aheadday = 3;
        } else if ($event->timestart - REMINDERS_7DAYSBEFORE_INSECONDS >= $timewindowstart &&
            $event->timestart - REMINDERS_7DAYSBEFORE_INSECONDS <= $timewindowend) {
            $aheadday = 7;
        }

        if ($aheadday == 0) continue;
        mtrace("   [Local Reminder] Processing event#$event->id [Type: $event->eventtype, inaheadof=$aheadday days]...");

        $optionstr = 'local_reminders_'.$event->eventtype.'rdays';
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
            mtrace("   [Local Reminder] No configuration for eventtype $event->eventtype ".
                "[event#$event->id is ignored!]...");
            continue;
        }

        // this reminder will not be set up to send by configurations
        if ($options[$aheaddaysindex[$aheadday]] == '0') {
            mtrace("   [Local Reminder] No reminder is due in ahead of $aheadday for eventtype $event->eventtype ".
                "[event#$event->id is ignored!]...");
            continue;
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

                    if (!empty($course)) {
                        $context = context_course::instance($course->id);
                        $sendusers = get_role_users($courseroleids, $context, true, 'u.*');

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
                        $reminder = new group_reminder($event, $group, $aheadday);

                        // add module details, if this event is a mod type event
                        //
                        if (!isemptyString($event->modulename) && $event->courseid > 0) {
                            $activityobj = fetch_module_instance($event->modulename, $event->instance, $event->courseid);
                            $reminder->set_activity($event->modulename, $activityobj);
                        }
                        $eventdata = $reminder->create_reminder_message_object($fromuser);

                        $groupmemberroles = groups_get_members_by_role($group->id, $group->courseid, 'u.id');
                        if ($groupmemberroles) {
                            foreach($groupmemberroles as $roleid => $roledata) {
                                foreach($roledata->users as $member) {
                                    $sendusers[] = $DB->get_record('user', array('id' => $member->id));
                                }
                            }
                        }
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
                "for event#[$event->id] (type: $event->eventtype) ".$ex.getMessage());
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
        $sendcount= 0;
        foreach ($sendusers as $touser) {
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
                if ($CFG->version > 2014051200) { // Moodle 2.7+
                    $mailresult = reminder_message_send($eventdata);
                }else{
                    $mailresult = message_send($eventdata);
                }


                if (!$mailresult) {
                    throw new \coding_exception("Could not send out message for event#$event->id to user $eventdata->userto");
                }else{
                    $sendcount++;
                }
            } catch (moodle_exception $mex) {
                $failedcount++;
                mtrace('Error: local/reminders/lib.php local_reminders_cron(): '.$mex.getMessage());
            }
        }

        if ($failedcount > 0) {
            mtrace("  [Local Reminder] Failed to send $failedcount reminders to users for event#$event->id");
        } else {
            mtrace("  [Local Reminder] All reminders was sent successfully for event#$event->id !");
        }

        unset($sendusers);

    }
    if ($CFG->version > 2014051200) { // Moodle 2.7+
        $event = \local_reminders\event\reminder_run::create(
            array(
                'contextid' => 1,
                'other' => array(
                    'sendcount' => $sendcount,
                    'failedcount' => $failedcount
                ))
        );
        $event->trigger();
    }else{
        add_to_log(0, 'local_reminders', 'cron', '', $timewindowend, 0, 0);
    }
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

/**
 * Taken from Moodle API, stripped log event generation, we don't want that every send message event goes right into our log :-)
 *
 * Returns true if input string is empty/whitespaces only, otherwise false.
 *
 * @param type $str string
 *
 * @return boolean true if string is empty or whitespace
 */
function reminder_message_send($eventdata) {
    global $CFG, $DB;

    //new message ID to return
    $messageid = false;

    // Fetch default (site) preferences
    $defaultpreferences = get_message_output_default_preferences();
    $preferencebase = $eventdata->component.'_'.$eventdata->name;
    // If message provider is disabled then don't do any processing.
    if (!empty($defaultpreferences->{$preferencebase.'_disable'})) {
        return $messageid;
    }

    //TODO: we need to solve problems with database transactions here somehow, for now we just prevent transactions - sorry
    $DB->transactions_forbidden();

    // By default a message is a notification. Only personal/private messages aren't notifications.
    if (!isset($eventdata->notification)) {
        $eventdata->notification = 1;
    }

    if (is_number($eventdata->userto)) {
        $eventdata->userto = \core_user::get_user($eventdata->userto);
    }
    if (is_int($eventdata->userfrom)) {
        $eventdata->userfrom = \core_user::get_user($eventdata->userfrom);
    }

    $usertoisrealuser = (\core_user::is_real_user($eventdata->userto->id) != false);
    // If recipient is internal user (noreply user), and emailstop is set then don't send any msg.
    if (!$usertoisrealuser && !empty($eventdata->userto->emailstop)) {
        debugging('Attempt to send msg to internal (noreply) user', DEBUG_NORMAL);
        return false;
    }

    if (!isset($eventdata->userto->auth) or !isset($eventdata->userto->suspended) or !isset($eventdata->userto->deleted)) {
        $eventdata->userto = \core_user::get_user($eventdata->userto->id);
    }

    //after how long inactive should the user be considered logged off?
    if (isset($CFG->block_online_users_timetosee)) {
        $timetoshowusers = $CFG->block_online_users_timetosee * 60;
    } else {
        $timetoshowusers = 300;//5 minutes
    }

    // Work out if the user is logged in or not
    if (!empty($eventdata->userto->lastaccess) && (time()-$timetoshowusers) < $eventdata->userto->lastaccess) {
        $userstate = 'loggedin';
    } else {
        $userstate = 'loggedoff';
    }

    // Create the message object
    $savemessage = new \stdClass();
    $savemessage->useridfrom        = $eventdata->userfrom->id;
    $savemessage->useridto          = $eventdata->userto->id;
    $savemessage->subject           = $eventdata->subject;
    $savemessage->fullmessage       = $eventdata->fullmessage;
    $savemessage->fullmessageformat = $eventdata->fullmessageformat;
    $savemessage->fullmessagehtml   = $eventdata->fullmessagehtml;
    $savemessage->smallmessage      = $eventdata->smallmessage;
    $savemessage->notification      = $eventdata->notification;

    if (!empty($eventdata->contexturl)) {
        $savemessage->contexturl = $eventdata->contexturl;
    } else {
        $savemessage->contexturl = null;
    }

    if (!empty($eventdata->contexturlname)) {
        $savemessage->contexturlname = $eventdata->contexturlname;
    } else {
        $savemessage->contexturlname = null;
    }

    $savemessage->timecreated = time();

    // Fetch enabled processors
    $processors = get_message_processors(true);

    // Preset variables
    $processorlist = array();
    // Fill in the array of processors to be used based on default and user preferences
    foreach ($processors as $processor) {
        // Skip adding processors for internal user, if processor doesn't support sending message to internal user.
        if (!$usertoisrealuser && !$processor->object->can_send_to_any_users()) {
            continue;
        }

        // First find out permissions
        $defaultpreference = $processor->name.'_provider_'.$preferencebase.'_permitted';
        if (isset($defaultpreferences->{$defaultpreference})) {
            $permitted = $defaultpreferences->{$defaultpreference};
        } else {
            // MDL-25114 They supplied an $eventdata->component $eventdata->name combination which doesn't
            // exist in the message_provider table (thus there is no default settings for them).
            $preferrormsg = "Could not load preference $defaultpreference. Make sure the component and name you supplied
                    to message_send() are valid.";
            throw new coding_exception($preferrormsg);
        }

        // Find out if user has configured this output
        // Some processors cannot function without settings from the user
        $userisconfigured = $processor->object->is_user_configured($eventdata->userto);

        // DEBUG: notify if we are forcing unconfigured output
        if ($permitted == 'forced' && !$userisconfigured) {
            debugging('Attempt to force message delivery to user who has "'.$processor->name.'" output unconfigured', DEBUG_NORMAL);
        }

        // Warn developers that necessary data is missing regardless of how the processors are configured
        if (!isset($eventdata->userto->emailstop)) {
            debugging('userto->emailstop is not set. Retrieving it from the user table');
            $eventdata->userto->emailstop = $DB->get_field('user', 'emailstop', array('id'=>$eventdata->userto->id));
        }

        // Populate the list of processors we will be using
        if ($permitted == 'forced' && $userisconfigured) {
            // An admin is forcing users to use this message processor. Use this processor unconditionally.
            $processorlist[] = $processor->name;
        } else if ($permitted == 'permitted' && $userisconfigured && !$eventdata->userto->emailstop) {
            // User has not disabled notifications
            // See if user set any notification preferences, otherwise use site default ones
            $userpreferencename = 'message_provider_'.$preferencebase.'_'.$userstate;
            if ($userpreference = get_user_preferences($userpreferencename, null, $eventdata->userto->id)) {
                if (in_array($processor->name, explode(',', $userpreference))) {
                    $processorlist[] = $processor->name;
                }
            } else if (isset($defaultpreferences->{$userpreferencename})) {
                if (in_array($processor->name, explode(',', $defaultpreferences->{$userpreferencename}))) {
                    $processorlist[] = $processor->name;
                }
            }
        }
    }

    if (empty($processorlist) && $savemessage->notification) {
        //if they have deselected all processors and its a notification mark it read. The user doesnt want to be bothered
        $savemessage->timeread = time();
        $messageid = $DB->insert_record('message_read', $savemessage);
    } else {                        // Process the message
        // Store unread message just in case we can not send it
        $messageid = $savemessage->id = $DB->insert_record('message', $savemessage);
        $eventdata->savedmessageid = $savemessage->id;

        // Try to deliver the message to each processor
        if (!empty($processorlist)) {
            foreach ($processorlist as $procname) {
                if (!$processors[$procname]->object->send_message($eventdata)) {
                    debugging('Error calling message processor '.$procname);
                    $messageid = false;
                }
            }

            //if messaging is disabled and they previously had forum notifications handled by the popup processor
            //or any processor that puts a row in message_working then the notification will remain forever
            //unread. To prevent this mark the message read if messaging is disabled
            if (empty($CFG->messaging)) {
                require_once($CFG->dirroot.'/message/lib.php');
                $messageid = reminder_message_mark_message_read($savemessage, time());
            } else if ( $DB->count_records('message_working', array('unreadmessageid' => $savemessage->id)) == 0){
                //if there is no more processors that want to process this we can move message to message_read
                require_once($CFG->dirroot.'/message/lib.php');
                $messageid = reminder_message_mark_message_read($savemessage, time(), true);
            }
        }
    }

    return $messageid;
}

/**
 * Taken from Moodle API, stripped log event generation, we don't want that every send message event goes right into our log :-)
 *
 * Returns true if input string is empty/whitespaces only, otherwise false.
 *
 * @param type $str string
 *
 * @return boolean true if string is empty or whitespace
 */
function reminder_message_mark_message_read($message, $timeread, $messageworkingempty=false) {
    global $DB;

    $message->timeread = $timeread;

    $messageid = $message->id;
    unset($message->id);//unset because it will get a new id on insert into message_read

    //If any processors have pending actions abort them
    if (!$messageworkingempty) {
        $DB->delete_records('message_working', array('unreadmessageid' => $messageid));
    }
    $messagereadid = $DB->insert_record('message_read', $message);

    $DB->delete_records('message', array('id' => $messageid));

    return $messagereadid;
}

