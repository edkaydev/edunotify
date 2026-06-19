<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_module_created',
        'callback' => '\local_edunotify\observer::course_module_created',
    ],
    [
        'eventname' => '\core\event\course_module_updated',
        'callback' => '\local_edunotify\observer::course_module_updated',
    ],
    [
        'eventname' => '\mod_assign\event\assessable_submitted',
        'callback' => '\local_edunotify\observer::assignment_submitted',
    ],
    [
        'eventname' => '\mod_assign\event\submission_graded',
        'callback' => '\local_edunotify\observer::assignment_graded',
    ],
    [
        'eventname' => '\mod_forum\event\post_created',
        'callback' => '\local_edunotify\observer::forum_post_created',
    ],
    [
        'eventname' => '\core\event\course_module_completion_updated',
        'callback' => '\local_edunotify\observer::course_module_completion_updated',
    ],
    [
        'eventname' => '\core\event\grade_item_updated',
        'callback' => '\local_edunotify\observer::grade_item_updated',
    ],
];
