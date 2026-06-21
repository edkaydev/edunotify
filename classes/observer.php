<?php
namespace local_edunotify;

defined('MOODLE_INTERNAL') || die();

class observer {
    public static function course_module_created(\core\event\course_module_created $event): void {
        try {
            $data = $event->get_data();
            $cm = get_coursemodule_from_id('', $data['contextinstanceid']);
            if (!$cm) return;

            if (!util::is_module_type_allowed($cm->modname)) return;

            $payload = [
                'courseid' => (int)$cm->course,
                'cmid' => (int)$cm->id,
                'modtype' => $cm->modname,
                'eventType' => '\core\event\course_module_created',
            ];

            $record = new \stdClass();
            $record->payload = json_encode($payload);
            $record->attempts = 0;
            $record->timecreated = time();

            global $DB;
            $DB->insert_record('local_edunotify_queue', $record);
        } catch (\Throwable $e) {
            debugging('local_edunotify: observer error: ' . $e->getMessage(), DEBUG_NORMAL);
        }
    }
}