<?php
namespace local_edunotify;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/bot.php');
require_once(__DIR__ . '/util.php');

class observer {
    public static function course_module_created(\core\event\course_module_created $event) {
        try {
            global $DB, $CFG;
            $data = $event->get_data();
            $cm = get_coursemodule_from_id('', $data['contextinstanceid']);
            if (!$cm) return;
            if (!util::is_module_type_allowed($cm->modname)) return;

            $course = $DB->get_record('course', ['id' => $cm->course]);
            $context = \context_course::instance($cm->course);

            $role = util::get_student_role();
            if (!$role) return;

            $enrolled = get_enrolled_users($context, 'mod/' . $cm->modname . ':view', 0, 'u.*', null, 0, 0, true);
            $students = [];
            foreach ($enrolled as $u) {
                $roles = get_user_roles($context, $u->id, true);
                foreach ($roles as $r) {
                    if ($r->shortname === $role->shortname) {
                        $students[] = $u;
                        break;
                    }
                }
            }

            if (empty($students)) return;

            $url = $CFG->wwwroot . '/mod/' . $cm->modname . '/view.php?id=' . $cm->id;

            $payload = [
                'targetNumbers' => [],
                'userIds' => [],
                'message' => get_string('msg_course_module_created', 'local_edunotify', [
                    'coursename' => $course->fullname ?? '',
                    'modname' => get_string('modulename', $cm->modname),
                    'url' => $url,
                ]),
                'courseid' => $cm->course,
                'cmid' => $cm->id,
                'modtype' => $cm->modname,
                'eventType' => '\core\event\course_module_created',
            ];

            $phones = util::batch_get_user_phones($students);
            foreach ($phones as $ph => $info) {
                if (in_array($cm->course, $info['courseoptouts'])) continue;
                $payload['targetNumbers'][] = $ph;
                $payload['userIds'][$ph] = $info['userid'];
            }

            if (empty($payload['targetNumbers'])) return;

            \local_edunotify\task\process_queue::enqueue($payload);
        } catch (\Throwable $e) {
            debugging('local_edunotify course_module_created failed: ' . $e->getMessage(), DEBUG_NORMAL);
        }
    }

    public static function assignment_submitted(\core\event\assessable_submitted $event) {
        try {
            global $DB, $CFG;
            $data = $event->get_data();
            $cm = get_coursemodule_from_id('assign', $data['contextinstanceid']);
            if (!$cm) return;
            if (!util::is_module_type_allowed('assign')) return;

            $submission = $DB->get_record('assign_submission', ['id' => $data['objectid']]);
            if (!$submission || !$submission->userid) return;

            $user = \core_user::get_user($submission->userid);
            if (!$user) return;
            $phone = util::get_user_phone($user);
            if (!$phone) return;

            $optout = $DB->get_record('local_edunotify_optout', ['userid' => $user->id]);
            if ($optout && $optout->opted_out) return;

            $course = $DB->get_record('course', ['id' => $cm->course]);
            $url = $CFG->wwwroot . '/mod/assign/view.php?id=' . $cm->id;

            \local_edunotify\task\process_queue::enqueue([
                'targetNumbers' => [$phone],
                'userIds' => [$phone => $user->id],
                'message' => get_string('msg_assignment_submitted', 'local_edunotify', [
                    'username' => fullname($user),
                    'coursename' => $course->fullname ?? '',
                    'url' => $url,
                ]),
                'courseid' => $cm->course,
                'cmid' => $cm->id,
                'modtype' => 'assign',
                'eventType' => '\core\event\assessable_submitted',
            ]);
        } catch (\Throwable $e) {
            debugging('local_edunotify assignment_submitted failed: ' . $e->getMessage(), DEBUG_NORMAL);
        }
    }

    public static function assignment_graded(\mod_assign\event\submission_graded $event) {
        try {
            global $DB, $CFG;
            $data = $event->get_data();
            $cm = get_coursemodule_from_id('assign', $data['contextinstanceid']);
            if (!$cm) return;
            if (!util::is_module_type_allowed('assign')) return;

            $submission = $DB->get_record('assign_submission', ['id' => $data['objectid']]);
            if (!$submission) return;

            $user = \core_user::get_user($submission->userid);
            if (!$user) return;
            $phone = util::get_user_phone($user);
            if (!$phone) return;

            $optout = $DB->get_record('local_edunotify_optout', ['userid' => $user->id]);
            if ($optout && $optout->opted_out) return;

            $course = $DB->get_record('course', ['id' => $cm->course]);
            $grade = $DB->get_record('assign_grades', ['assignment' => $cm->instance, 'userid' => $user->id]);
            $url = $CFG->wwwroot . '/mod/assign/view.php?id=' . $cm->id;

            \local_edunotify\task\process_queue::enqueue([
                'targetNumbers' => [$phone],
                'userIds' => [$phone => $user->id],
                'message' => get_string('msg_assignment_graded', 'local_edunotify', [
                    'username' => fullname($user),
                    'coursename' => $course->fullname ?? '',
                    'grade' => $grade ? $grade->grade : 'N/A',
                    'url' => $url,
                ]),
                'courseid' => $cm->course,
                'cmid' => $cm->id,
                'modtype' => 'assign',
                'eventType' => '\mod_assign\event\submission_graded',
            ]);
        } catch (\Throwable $e) {
            debugging('local_edunotify assignment_graded failed: ' . $e->getMessage(), DEBUG_NORMAL);
        }
    }

    public static function forum_post_created(\core\event\forum_post_created $event) {
        try {
            global $DB, $CFG;
            $data = $event->get_data();
            $cm = get_coursemodule_from_id('forum', $data['contextinstanceid']);
            if (!$cm) return;
            if (!util::is_module_type_allowed('forum')) return;
            if (empty(get_config('local_edunotify', 'enable_forum'))) return;

            $post = $DB->get_record('forum_posts', ['id' => $data['objectid']]);
            if (!$post) return;

            $discussion = $DB->get_record('forum_discussions', ['id' => $post->discussion]);
            if (!$discussion) return;

            $course = $DB->get_record('course', ['id' => $cm->course]);
            $context = \context_course::instance($cm->course);

            $role = util::get_student_role();
            if (!$role) return;

            $enrolled = get_enrolled_users($context, 'mod/forum:viewdiscussion', 0, 'u.*', null, 0, 0, true);
            $students = [];
            foreach ($enrolled as $u) {
                $roles = get_user_roles($context, $u->id, true);
                foreach ($roles as $r) {
                    if ($r->shortname === $role->shortname) {
                        $students[] = $u;
                        break;
                    }
                }
            }

            if (empty($students)) return;

            $url = $CFG->wwwroot . '/mod/forum/discuss.php?d=' . $discussion->id;

            $payload = [
                'targetNumbers' => [],
                'userIds' => [],
                'message' => get_string('msg_forum_post', 'local_edunotify', [
                    'coursename' => $course->fullname ?? '',
                    'discussionname' => $discussion->name,
                    'url' => $url,
                ]),
                'courseid' => $cm->course,
                'cmid' => $cm->id,
                'modtype' => 'forum',
                'eventType' => '\core\event\forum_post_created',
            ];

            $phones = util::batch_get_user_phones($students);
            $courseoptoutuserids = [];
            foreach ($phones as $ph => $info) {
                if (in_array($cm->course, $info['courseoptouts'])) {
                    $courseoptoutuserids[] = $info['userid'];
                    continue;
                }
                $payload['targetNumbers'][] = $ph;
                $payload['userIds'][$ph] = $info['userid'];
            }

            if (empty($payload['targetNumbers'])) return;

            \local_edunotify\task\process_queue::enqueue($payload);
        } catch (\Throwable $e) {
            debugging('local_edunotify forum_post_created failed: ' . $e->getMessage(), DEBUG_NORMAL);
        }
    }

    public static function course_module_updated(\core\event\course_module_updated $event) {
        try {
            global $DB, $CFG;
            $data = $event->get_data();
            $cmid = $data['contextinstanceid'];
            $cm = get_coursemodule_from_id('', $cmid);
            if (!$cm) return;
            if (!util::is_module_type_allowed($cm->modname)) return;

            $course = $DB->get_record('course', ['id' => $cm->course]);
            $context = \context_course::instance($cm->course);

            $role = util::get_student_role();
            if (!$role) return;

            $enrolled = get_enrolled_users($context, 'mod/' . $cm->modname . ':view', 0, 'u.*', null, 0, 0, true);
            $students = [];
            foreach ($enrolled as $u) {
                $roles = get_user_roles($context, $u->id, true);
                foreach ($roles as $r) {
                    if ($r->shortname === $role->shortname) {
                        $students[] = $u;
                        break;
                    }
                }
            }

            if (empty($students)) return;

            $url = $CFG->wwwroot . '/mod/' . $cm->modname . '/view.php?id=' . $cm->id;

            $payload = [
                'targetNumbers' => [],
                'userIds' => [],
                'message' => get_string('msg_course_module_updated', 'local_edunotify', [
                    'coursename' => $course->fullname ?? '',
                    'modname' => get_string('modulename', $cm->modname),
                    'url' => $url,
                ]),
                'courseid' => $cm->course,
                'cmid' => $cm->id,
                'modtype' => $cm->modname,
                'eventType' => '\core\event\course_module_updated',
            ];

            $phones = util::batch_get_user_phones($students);
            foreach ($phones as $ph => $info) {
                if (in_array($cm->course, $info['courseoptouts'])) continue;
                $payload['targetNumbers'][] = $ph;
                $payload['userIds'][$ph] = $info['userid'];
            }

            if (empty($payload['targetNumbers'])) return;

            \local_edunotify\task\process_queue::enqueue($payload);
        } catch (\Throwable $e) {
            debugging('local_edunotify course_module_updated failed: ' . $e->getMessage(), DEBUG_NORMAL);
        }
    }

    public static function course_module_completion_updated(\core\event\course_module_completion_updated $event) {
        try {
            global $DB, $CFG;
            $data = $event->get_data();
            $cmid = $data['contextinstanceid'];
            $cm = get_coursemodule_from_id('', $cmid);
            if (!$cm) return;
            if (!util::is_module_type_allowed($cm->modname)) return;

            $user = \core_user::get_user($data['relateduserid']);
            if (!$user) return;
            $phone = util::get_user_phone($user);
            if (!$phone) return;

            $optout = $DB->get_record('local_edunotify_optout', ['userid' => $user->id]);
            if ($optout && $optout->opted_out) return;

            $courseoptout = $DB->get_record('local_edunotify_course_optout', ['userid' => $user->id, 'courseid' => $cm->course]);
            if ($courseoptout) return;

            $course = $DB->get_record('course', ['id' => $cm->course]);
            $url = $CFG->wwwroot . '/mod/' . $cm->modname . '/view.php?id=' . $cm->id;

            \local_edunotify\task\process_queue::enqueue([
                'targetNumbers' => [$phone],
                'userIds' => [$phone => $user->id],
                'message' => get_string('msg_completion', 'local_edunotify', [
                    'username' => fullname($user),
                    'coursename' => $course->fullname ?? '',
                    'url' => $url,
                ]),
                'courseid' => $cm->course,
                'cmid' => $cm->id,
                'modtype' => $cm->modname,
                'eventType' => '\core\event\course_module_completion_updated',
            ]);
        } catch (\Throwable $e) {
            debugging('local_edunotify course_module_completion_updated failed: ' . $e->getMessage(), DEBUG_NORMAL);
        }
    }

    public static function grade_item_updated(\core\event\grade_item_updated $event) {
        try {
            global $DB, $CFG;
            $data = $event->get_data();
            $courseid = $data['courseid'];
            if (!$courseid) return;

            $context = \context_course::instance($courseid);
            $role = util::get_student_role();
            if (!$role) return;

            $enrolled = get_enrolled_users($context, 'moodle/grade:view', 0, 'u.*', null, 0, 0, true);
            $students = [];
            foreach ($enrolled as $u) {
                $roles = get_user_roles($context, $u->id, true);
                foreach ($roles as $r) {
                    if ($r->shortname === $role->shortname) {
                        $students[] = $u;
                        break;
                    }
                }
            }

            if (empty($students)) return;

            $course = $DB->get_record('course', ['id' => $courseid]);
            $url = $CFG->wwwroot . '/grade/report/user/index.php?id=' . $courseid;

            $payload = [
                'targetNumbers' => [],
                'userIds' => [],
                'message' => get_string('msg_grade_updated', 'local_edunotify', [
                    'coursename' => $course->fullname ?? '',
                    'url' => $url,
                ]),
                'courseid' => $courseid,
                'cmid' => 0,
                'modtype' => 'grade',
                'eventType' => '\core\event\grade_item_updated',
            ];

            $phones = util::batch_get_user_phones($students);
            foreach ($phones as $ph => $info) {
                if (in_array($courseid, $info['courseoptouts'])) continue;
                $payload['targetNumbers'][] = $ph;
                $payload['userIds'][$ph] = $info['userid'];
            }

            if (empty($payload['targetNumbers'])) return;

            \local_edunotify\task\process_queue::enqueue($payload);
        } catch (\Throwable $e) {
            debugging('local_edunotify grade_item_updated failed: ' . $e->getMessage(), DEBUG_NORMAL);
        }
    }
}
