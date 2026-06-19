<?php
namespace local_edunotify;

defined('MOODLE_INTERNAL') || die();

class util {
    public static function get_student_role(): ?\stdClass {
        global $DB;
        $configrole = get_config('local_edunotify', 'studentrole');
        $role = null;
        if (!empty($configrole)) {
            $role = $DB->get_record('role', ['shortname' => $configrole]);
        }
        if (!$role) {
            $role = $DB->get_record('role', ['shortname' => 'student']);
        }
        if (!$role) {
            $role = $DB->get_record('role', ['archetype' => 'student']);
        }
        return $role ?: null;
    }

    public static function get_phone_field(): ?\stdClass {
        global $DB;
        $shortname = get_config('local_edunotify', 'phonefield');
        $field = null;
        if (!empty($shortname)) {
            $field = $DB->get_record('user_info_field', ['shortname' => $shortname]);
        }
        if (!$field) {
            $field = $DB->get_record('user_info_field', ['shortname' => 'phone']);
        }
        if (!$field) {
            $field = $DB->get_record('user_info_field', ['shortname' => 'whatsapp']);
        }
        return $field ?: null;
    }

    public static function get_user_phone(\stdClass $user): ?string {
        global $DB;
        $field = self::get_phone_field();
        $raw = '';
        if ($field) {
            $data = $DB->get_field('user_info_data', 'data', [
                'userid' => $user->id,
                'fieldid' => $field->id,
            ]);
            $raw = trim($data ?? '');
        }
        if (empty($raw)) $raw = trim($user->phone1 ?? '');
        if (empty($raw)) $raw = trim($user->phone2 ?? '');
        if (empty($raw)) return null;

        $cleaned = preg_replace('/[^0-9]/', '', $raw);
        if (!preg_match('/^\d{7,15}$/', $cleaned)) return null;
        if (strpos($cleaned, '0') === 0) {
            $cc = get_config('local_edunotify', 'countrycode') ?: '256';
            $cleaned = $cc . substr($cleaned, 1);
        }
        return $cleaned . '@c.us';
    }

    public static function batch_get_user_phones(array $users): array {
        global $DB;
        $field = self::get_phone_field();
        $userids = array_map(function($u) { return $u->id; }, $users);
        if (empty($userids)) return [];

        $customdata = [];
        if ($field) {
            $rs = $DB->get_records('user_info_data', ['fieldid' => $field->id], '', 'userid, data');
            foreach ($rs as $r) {
                $customdata[$r->userid] = trim($r->data ?? '');
            }
        }

        $optouts = $DB->get_records_list('local_edunotify_optout', 'userid', $userids, '', 'userid, opted_out');
        $allcourseoptouts = $DB->get_records_list('local_edunotify_course_optout', 'userid', $userids, '', 'id, userid, courseid');
        $courseoptoutsbyuser = [];
        foreach ($allcourseoptouts as $co) {
            $courseoptoutsbyuser[$co->userid][] = $co->courseid;
        }

        $phones = [];
        foreach ($users as $user) {
            $raw = $customdata[$user->id] ?? '';
            if (empty($raw)) $raw = trim($user->phone1 ?? '');
            if (empty($raw)) $raw = trim($user->phone2 ?? '');
            if (empty($raw)) continue;

            $cleaned = preg_replace('/[^0-9]/', '', $raw);
            if (!preg_match('/^\d{7,15}$/', $cleaned)) continue;
            if (strpos($cleaned, '0') === 0) {
                $cc = get_config('local_edunotify', 'countrycode') ?: '256';
                $cleaned = $cc . substr($cleaned, 1);
            }

            if (!empty($optouts[$user->id]->opted_out)) continue;

            $phones[$cleaned . '@c.us'] = [
                'userid' => $user->id,
                'courseoptouts' => $courseoptoutsbyuser[$user->id] ?? [],
            ];
        }
        return $phones;
    }

    public static function is_module_type_allowed(string $modtype): bool {
        $allowed = get_config('local_edunotify', 'allowed_modtypes');
        if (empty($allowed)) return true;
        $types = explode(',', $allowed);
        return in_array($modtype, array_map('trim', $types));
    }

    public static function is_quiet_hours(): bool {
        $start = get_config('local_edunotify', 'quietstart');
        $end = get_config('local_edunotify', 'quietend');
        if (empty($start) || empty($end)) return false;

        $now = (int)date('Gi');
        $start = (int)str_replace(':', '', $start);
        $end = (int)str_replace(':', '', $end);

        if ($start < $end) {
            return $now >= $start && $now < $end;
        }
        return $now >= $start || $now < $end;
    }
}
