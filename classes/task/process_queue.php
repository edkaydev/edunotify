<?php
namespace local_edunotify\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../bot.php');

class process_queue extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('process_queue_task', 'local_edunotify');
    }

    public function execute() {
        global $DB;

        $queued = $DB->get_records('local_edunotify_queue', null, 'timecreated ASC', '*', 0, 50);
        if (empty($queued)) return;

        $botrunning = \local_edunotify\bot::is_running();
        if (!$botrunning) {
            mtrace(get_string('queue_bot_offline', 'local_edunotify', count($queued)));
            return;
        }

        foreach ($queued as $item) {
            $payload = json_decode($item->payload, true);
            if (!$payload) {
                $DB->delete_records('local_edunotify_queue', ['id' => $item->id]);
                continue;
            }

            try {
                $result = \local_edunotify\bot::send_notification($payload);
            } catch (\Exception $e) {
                $result = null;
            }

            if ($result) {
                self::write_log($payload, $result);
                $DB->delete_records('local_edunotify_queue', ['id' => $item->id]);
                mtrace(get_string('queue_processed', 'local_edunotify', $item->id));
            } else {
                $item->attempts++;
                $item->timecreated = time();
                if ($item->attempts >= 5) {
                    $error = get_string('queue_dropped_max_attempts', 'local_edunotify');
                    self::write_log($payload, null, $error);
                    $DB->delete_records('local_edunotify_queue', ['id' => $item->id]);
                    mtrace(get_string('queue_dropped', 'local_edunotify', $item->id));
                } else {
                    $DB->update_record('local_edunotify_queue', $item);
                    mtrace(get_string('queue_requeue', 'local_edunotify', ['id' => $item->id, 'attempts' => $item->attempts]));
                }
            }
            sleep(2);
        }
    }

    public static function enqueue(array $payload): void {
        global $DB;

        $record = new \stdClass();
        $record->payload = json_encode($payload);
        $record->attempts = 0;
        $record->timecreated = time();
        $DB->insert_record('local_edunotify_queue', $record);
    }

    private static function write_log(array $payload, $result, ?string $error = null): int {
        global $DB;
        $log = new \stdClass();
        $log->courseid = (int)($payload['courseid'] ?? 0);
        $log->cmid = (int)($payload['cmid'] ?? 0);
        $log->modname = clean_param($payload['modtype'] ?? '', PARAM_ALPHAEXT);
        $log->recipients = count($payload['targetNumbers'] ?? []);
        $log->eventtype = $payload['eventType'] ?? '';
        $log->status = $result ? 'sent' : 'failed';
        $log->error_msg = $error ?? ($result ? '' : 'Bot send failed');
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
    }
}
