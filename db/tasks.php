<?php
/**
 * Reminder plugin task configuration
 *
 * @package    local
 * @subpackage reminders
 * @copyright  2012 Isuru Madushanka Weerarathna, modified (Tasks and Events) by Mario Wehr
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
$tasks = array(
    array(
        'classname' => 'local_reminders\task\reminder_task',
        'blocking' => 0,
        'minute' => '*/15',
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*'
    )
);