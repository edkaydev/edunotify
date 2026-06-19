<?php
namespace local_edunotify\privacy;

defined('MOODLE_INTERNAL') || die();

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider {

    public static function get_metadata(\core_privacy\local\metadata\collection $collection): \core_privacy\local\metadata\collection {
        $collection->add_database_table('local_edunotify_log', [
            'courseid' => 'privacy:metadata:log:courseid',
            'cmid' => 'privacy:metadata:log:cmid',
            'modname' => 'privacy:metadata:log:modname',
            'timecreated' => 'privacy:metadata:log:timecreated',
        ], 'privacy:metadata:log');

        $collection->add_database_table('local_edunotify_recipient', [
            'userid' => 'privacy:metadata:recipient:userid',
            'phone' => 'privacy:metadata:recipient:phone',
            'status' => 'privacy:metadata:recipient:status',
        ], 'privacy:metadata:recipient');

        $collection->add_database_table('local_edunotify_optout', [
            'userid' => 'privacy:metadata:optout:userid',
            'opted_out' => 'privacy:metadata:optout:opted_out',
        ], 'privacy:metadata:optout');

        $collection->add_database_table('local_edunotify_course_optout', [
            'userid' => 'privacy:metadata:optout:userid',
            'courseid' => 'privacy:metadata:log:courseid',
        ], 'privacy:metadata:optout');

        return $collection;
    }

    public static function get_reason(): string {
        return 'privacy:metadata:reason';
    }

    public static function export_user_data(\core_privacy\local\request\context $context, array $subcontext, array $userdata): void {
    }

    public static function delete_data_for_user(\core_privacy\local\request\approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        $DB->delete_records('local_edunotify_recipient', ['userid' => $userid]);
        $DB->delete_records('local_edunotify_optout', ['userid' => $userid]);
        $DB->delete_records('local_edunotify_course_optout', ['userid' => $userid]);

        $queued = $DB->get_records('local_edunotify_queue');
        foreach ($queued as $item) {
            $payload = json_decode($item->payload, true);
            if (!$payload) continue;
            $userids = $payload['userIds'] ?? [];
            if (in_array($userid, $userids)) {
                $DB->delete_records('local_edunotify_queue', ['id' => $item->id]);
            }
        }
    }

    public static function get_contexts_for_userid(int $userid): \core_privacy\local\request\contextlist {
        $contextlist = new \core_privacy\local\request\contextlist();
        $contextlist->add_system_context();
        return $contextlist;
    }

    public static function export_user_data_for_context(\core_privacy\local\request\approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;

        $optout = $DB->get_record('local_edunotify_optout', ['userid' => $userid]);
        if ($optout) {
            \core_privacy\local\request\writer::with_context($contextlist->current())
                ->export_data([get_string('privacy:metadata:optout', 'local_edunotify')], $optout);
        }

        $courseoptouts = $DB->get_records('local_edunotify_course_optout', ['userid' => $userid]);
        if ($courseoptouts) {
            foreach ($courseoptouts as $co) {
                \core_privacy\local\request\writer::with_context($contextlist->current())
                    ->export_data([get_string('privacy:metadata:optout', 'local_edunotify'), 'course_' . $co->courseid], $co);
            }
        }

        $recipients = $DB->get_records('local_edunotify_recipient', ['userid' => $userid]);
        if ($recipients) {
            foreach ($recipients as $r) {
                \core_privacy\local\request\writer::with_context($contextlist->current())
                    ->export_data([get_string('privacy:metadata:recipient', 'local_edunotify')], $r);
            }
        }
    }

    public static function delete_data_for_all_users_in_context(\core_privacy\local\request\approved_contextlist $contextlist): void {
        global $DB;
        $DB->delete_records('local_edunotify_optout');
        $DB->delete_records('local_edunotify_course_optout');
        $DB->delete_records('local_edunotify_recipient');
        $DB->delete_records('local_edunotify_queue');
    }
}
