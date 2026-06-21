<?php
namespace local_edunotify\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../bot.php');
require_once(__DIR__ . '/../util.php');

class process_queue extends \core\task\scheduled_task {
    private const MAX_ATTEMPTS = 10;

    public function get_name() {
        return get_string('process_queue_task', 'local_edunotify');
    }

    public function execute() {
        global $DB;

        try {
            $botrunning = false;
            try {
                $botrunning = \local_edunotify\bot::is_running();
            } catch (\Throwable $e) {
                mtrace('local_edunotify: error checking bot status: ' . $e->getMessage());
                return;
            }

            if (!$botrunning) {
                try {
                    if (!\local_edunotify\bot::was_intentionally_stopped()) {
                        self::auto_start_bot();
                    }
                } catch (\Throwable $e) {
                    mtrace('local_edunotify: auto-start failed: ' . $e->getMessage());
                }
                return;
            }

            $queued = $DB->get_records('local_edunotify_queue', null, 'timecreated ASC', '*', 0, 50);
            if (empty($queued)) return;

            foreach ($queued as $item) {
                try {
                    self::process_item($item);
                } catch (\Throwable $e) {
                    mtrace('local_edunotify: item #' . $item->id . ' error: ' . $e->getMessage());
                    $item->attempts = (int)$item->attempts + 1;
                    if ($item->attempts >= self::MAX_ATTEMPTS) {
                        $DB->delete_records('local_edunotify_queue', ['id' => $item->id]);
                        mtrace('local_edunotify: dropped #' . $item->id . ' after ' . $item->attempts . ' attempts');
                    } else {
                        $item->timecreated = time() + 3600;
                        $DB->update_record('local_edunotify_queue', $item);
                    }
                }
                usleep(500000);
            }
        } catch (\Throwable $e) {
            mtrace('local_edunotify: cron task failed: ' . $e->getMessage());
        }
    }

    private static function auto_start_bot(): void {
        $lastauto = get_config('local_edunotify', 'last_auto_start');
        $count = (int)(get_config('local_edunotify', 'auto_start_count') ?: 0);
        $resetday = (int)(get_config('local_edunotify', 'auto_start_day') ?: 0);
        $today = (int)(time() / 86400);

        if ($resetday !== $today) {
            $count = 0;
            set_config('auto_start_count', 0, 'local_edunotify');
            set_config('auto_start_day', $today, 'local_edunotify');
        }

        if ($count >= 3) return;
        if ($lastauto && (time() - $lastauto) <= 1200) return;

        $result = \local_edunotify\bot::start();
        if (!empty($result['success'])) {
            set_config('last_auto_start', time(), 'local_edunotify');
            set_config('auto_start_count', $count + 1, 'local_edunotify');
            mtrace('local_edunotify: auto-started bot (' . ($count + 1) . '/3)');
        }
    }

    private static function process_item(\stdClass $item): void {
        global $DB;

        $item->attempts = (int)$item->attempts;

        $payload = json_decode($item->payload, true);
        if (!is_array($payload)) {
            $DB->delete_records('local_edunotify_queue', ['id' => $item->id]);
            return;
        }

        $full = self::resolve_payload($payload);
        if ($full === null) {
            $DB->delete_records('local_edunotify_queue', ['id' => $item->id]);
            return;
        }

        if (empty($full['targetNumbers'])) {
            $DB->delete_records('local_edunotify_queue', ['id' => $item->id]);
            return;
        }

        $result = \local_edunotify\bot::send_notification($full);
        if ($result) {
            self::write_log($full, $result);
            $DB->delete_records('local_edunotify_queue', ['id' => $item->id]);
            mtrace('local_edunotify: processed #' . $item->id . ' (' . count($full['targetNumbers']) . ' recipients)');
        } else {
            $item->attempts++;
            if ($item->attempts >= self::MAX_ATTEMPTS) {
                $DB->delete_records('local_edunotify_queue', ['id' => $item->id]);
                mtrace('local_edunotify: dropped #' . $item->id . ' after ' . $item->attempts . ' attempts');
                return;
            }
            $delays = [0, 60, 300, 600, 1800, 3600, 3600, 7200, 14400, 28800];
            $index = min($item->attempts - 1, count($delays) - 1);
            $item->timecreated = time() + $delays[$index];
            $DB->update_record('local_edunotify_queue', $item);
            mtrace('local_edunotify: requeue #' . $item->id . ' attempt ' . $item->attempts . '/' . self::MAX_ATTEMPTS . ', next in ' . $delays[$index] . 's');
        }
    }

    private static function resolve_payload(array $payload): ?array {
        global $DB, $CFG;

        $cmid = (int)($payload['cmid'] ?? 0);
        $courseid = (int)($payload['courseid'] ?? 0);
        $modtype = $payload['modtype'] ?? '';

        if (!$cmid || !$courseid || !$modtype) return null;

        $cm = get_coursemodule_from_id($modtype, $cmid);
        if (!$cm) return null;

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) return null;

        $context = \context_course::instance($courseid);
        $role = \local_edunotify\util::get_student_role();
        if (!$role) return null;

        $enrolled = get_enrolled_users($context, 'mod/' . $modtype . ':view', 0, 'u.*', null, 0, 0, true);
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

        if (empty($students)) return null;

        $url = ($CFG->wwwroot ?? '') . '/mod/' . $modtype . '/view.php?id=' . $cmid;

        // Resolve instructor name from course context
        $instructor = '';
        $teacherroles = $DB->get_records_sql(
            "SELECT DISTINCT r.id, r.shortname FROM {role} r WHERE r.shortname IN ('editingteacher', 'teacher')"
        );
        if (!empty($teacherroles)) {
            $teacherids = array_keys($teacherroles);
            list($insql, $inparams) = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED);
            $teachers = $DB->get_records_sql(
                "SELECT DISTINCT u.id, u.firstname, u.lastname
                 FROM {user} u
                 JOIN {role_assignments} ra ON ra.userid = u.id AND ra.contextid = :ctxid
                 WHERE ra.roleid $insql",
                array_merge(['ctxid' => $context->id], $inparams)
            );
            if (!empty($teachers)) {
                $names = array_map(function($t) { return fullname($t); }, $teachers);
                $instructor = implode(', ', $names);
            }
        }

        // Map event type to human-readable action label
        $actionLabels = [
            '\core\event\course_module_created' => 'New activity added',
            '\core\event\course_module_updated' => 'Activity updated',
            '\core\event\course_module_deleted' => 'Activity removed',
        ];
        $eventType = $payload['eventType'] ?? '';
        $actionType = $actionLabels[$eventType] ?? 'Activity updated';

        // Build message body from config template (fall back to language string)
        $template = get_config('local_edunotify', 'template_course_module_created');
        if (!empty($template)) {
            $replacements = [
                '{{courseName}}' => $course->fullname ?? '',
                '{{activityTitle}}' => $cm->name ?? '',
                '{{activityUrl}}' => $url,
                '{{actionType}}' => $actionType,
                '{{instructor}}' => $instructor,
                '{{optoutUrl}}' => ($CFG->wwwroot ?? '') . '/local/edunotify/editphone.php',
            ];
            $messageBody = strtr($template, $replacements);
        } else {
            $messageBody = get_string('msg_course_module_created', 'local_edunotify', [
                'coursename' => $course->fullname ?? '',
                'modname' => get_string('modulename', $modtype),
                'activitytitle' => $cm->name ?? get_string('modulename', $modtype),
                'url' => $url,
            ]);
        }

        $full = [
            'targetNumbers' => [],
            'userIds' => [],
            'messageBody' => $messageBody,
            'courseName' => $course->fullname ?? '',
            'activityTitle' => $cm->name ?? get_string('modulename', $modtype),
            'activityUrl' => $url,
            'courseid' => $courseid,
            'cmid' => $cmid,
            'modtype' => $modtype,
            'eventType' => $eventType,
            'instructor' => $instructor,
        ];

        $phones = \local_edunotify\util::batch_get_user_phones($students);
        foreach ($phones as $ph => $info) {
            if (in_array($courseid, $info['courseoptouts'], true)) continue;
            $full['targetNumbers'][] = $ph;
            $full['userIds'][$ph] = $info['userid'];
        }

        return $full;
    }

    public static function enqueue(array $payload): void {
        global $DB;
        try {
            $record = new \stdClass();
            $record->payload = json_encode($payload);
            $record->attempts = 0;
            $record->timecreated = time();
            $DB->insert_record('local_edunotify_queue', $record);
        } catch (\Throwable $e) {
            debugging('local_edunotify: enqueue failed: ' . $e->getMessage(), DEBUG_NORMAL);
        }
    }

    private static function write_log(array $payload, $result, ?string $error = null): int {
        global $DB;
        try {
            $success = $result && !empty($result['success']);
            $log = new \stdClass();
            $log->courseid = (int)($payload['courseid'] ?? 0);
            $log->cmid = (int)($payload['cmid'] ?? 0);
            $log->modname = clean_param($payload['modtype'] ?? '', PARAM_ALPHAEXT);
            $log->recipients = count($payload['targetNumbers'] ?? []);
            $log->eventtype = $payload['eventType'] ?? '';
            $log->status = $success ? 'sent' : 'failed';
            $log->error_msg = $error ?? ($success ? '' : ($result['error'] ?? 'Bot send failed'));
            $log->timecreated = time();
            $logid = $DB->insert_record('local_edunotify_log', $log);

            if (!empty($payload['targetNumbers'])) {
                $userids = $payload['userIds'] ?? [];
                foreach ($payload['targetNumbers'] as $phone) {
                    $recipient = new \stdClass();
                    $recipient->userid = $userids[$phone] ?? 0;
                    $recipient->logid = $logid;
                    $recipient->phone = $phone;
                    $recipient->timecreated = time();
                    $DB->insert_record('local_edunotify_recipient', $recipient);
                }
            }

            return $logid;
        } catch (\Throwable $e) {
            debugging('local_edunotify: write_log failed: ' . $e->getMessage(), DEBUG_NORMAL);
            return 0;
        }
    }
}