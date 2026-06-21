<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_edunotify', get_string('pluginname', 'local_edunotify'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_heading(
        'local_edunotify/prereq',
        get_string('requirements', 'local_edunotify'),
        get_string('requirements_desc', 'local_edunotify')
    ));

    $settings->add(new admin_setting_heading(
        'local_edunotify/connection',
        get_string('connection_settings', 'local_edunotify'),
        get_string('connection_settings_desc', 'local_edunotify')
    ));

    $phonefields = ['' => get_string('autodetect', 'local_edunotify')];
    $fields = $DB->get_records('user_info_field', null, 'name ASC');
    foreach ($fields as $field) {
        $phonefields[$field->shortname] = $field->name . ' (' . $field->shortname . ')';
    }

    $settings->add(new admin_setting_configselect(
        'local_edunotify/phonefield',
        get_string('phonefield', 'local_edunotify'),
        get_string('phonefield_desc', 'local_edunotify'),
        '',
        $phonefields
    ));

    $roles = ['' => get_string('autodetect', 'local_edunotify')];
    $allroles = $DB->get_records('role', null, 'shortname ASC');
    foreach ($allroles as $role) {
        $roles[$role->shortname] = $role->shortname . ' (' . $role->archetype . ')';
    }

    $settings->add(new admin_setting_configselect(
        'local_edunotify/studentrole',
        get_string('studentrole', 'local_edunotify'),
        get_string('studentrole_desc', 'local_edunotify'),
        '',
        $roles
    ));

    $settings->add(new admin_setting_configtext(
        'local_edunotify/countrycode',
        get_string('countrycode', 'local_edunotify'),
        get_string('countrycode_desc', 'local_edunotify'),
        '256',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_edunotify/botport',
        get_string('botport', 'local_edunotify'),
        get_string('botport_desc', 'local_edunotify'),
        '5001',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_edunotify/batchsize',
        get_string('batchsize', 'local_edunotify'),
        get_string('batchsize_desc', 'local_edunotify'),
        '20',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_edunotify/batchpause',
        get_string('batchpause', 'local_edunotify'),
        get_string('batchpause_desc', 'local_edunotify'),
        '90',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_edunotify/dailylimit',
        get_string('dailylimit', 'local_edunotify'),
        get_string('dailylimit_desc', 'local_edunotify'),
        '500',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_edunotify/hourlylimit',
        get_string('hourlylimit', 'local_edunotify'),
        get_string('hourlylimit_desc', 'local_edunotify'),
        '80',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_edunotify/allowed_modtypes',
        get_string('allowed_modtypes', 'local_edunotify'),
        get_string('allowed_modtypes_desc', 'local_edunotify'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_heading(
        'local_edunotify/templates_heading',
        get_string('message_templates', 'local_edunotify'),
        get_string('message_templates_desc', 'local_edunotify')
    ));

    $defaulttemplate = "[EN] *EduNotify* [EN]\n\n*Course:* {{courseName}}\n*Instructor:* {{instructor}}\n*Action:* {{actionType}}\n*Item:* {{activityTitle}}\n{{activityUrl}}\n\nPlease log into the portal to view.\n\nTo opt out, reply STOP or visit: {{optoutUrl}}";

    $settings->add(new admin_setting_configtextarea(
        'local_edunotify/template_course_module_created',
        get_string('template_new_activity', 'local_edunotify'),
        get_string('template_new_activity_desc', 'local_edunotify'),
        $defaulttemplate,
        PARAM_RAW
    ));

    $settings->add(new admin_setting_heading(
        'local_edunotify/botheading',
        get_string('bot_management', 'local_edunotify'),
        get_string('bot_management_desc', 'local_edunotify')
    ));

    $botrunning = false;
    $botstatus = ['status' => 'unknown', 'message' => ''];
    if (class_exists('\\local_edunotify\\bot') && function_exists('get_config')) {
        try {
            $botrunning = \local_edunotify\bot::is_running();
            $botstatus = \local_edunotify\bot::get_status();
        } catch (\Throwable $e) {
            $botrunning = false;
        }
    }

    $label = $botrunning
        ? '<span style="color:#37e380;font-weight:700;">● ' . get_string('online', 'local_edunotify') . '</span>'
        : '<span style="color:#dc3545;font-weight:700;">● ' . get_string('offline', 'local_edunotify') . '</span>';

    $msg = !empty($botstatus['message']) ? '<p style="color:#6b7280;font-size:0.88rem;">' . s($botstatus['message']) . '</p>' : '';

    $apikey = get_config('local_edunotify', 'apikey');
    $apikeyhtml = '';
    if ($apikey) {
        $apikeyhtml = '<p style="font-size:0.82rem;color:#6b7280;margin-top:8px;"><strong>API Key:</strong> <code style="background:#f3f4f6;padding:2px 8px;border-radius:4px;font-size:0.8rem;">' . s(substr($apikey, 0, 16)) . '...' . '</code></p>';
    }

    $dashurl = new \moodle_url('/local/edunotify/index.php');
    $link = '<p><a href="' . $dashurl . '" class="btn btn-primary" style="margin-top:6px;">' . get_string('goto_dashboard', 'local_edunotify') . '</a></p>';

    $statushtml = '<p><strong>' . get_string('bot_status_label', 'local_edunotify') . '</strong> ' . $label . '</p>' . $msg . $apikeyhtml . $link;

    $settings->add(new admin_setting_heading('local_edunotify/botstatusdisplay', '', $statushtml));

    if ($botrunning && $botstatus['status'] === 'connected') {
        $settings->add(new admin_setting_heading('local_edunotify/connectedheading', '',
            '<div style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:14px 18px;border-radius:10px;margin-top:10px;">' .
            '[OK] ' . get_string('bot_connected_msg', 'local_edunotify') . '</div>'));
    }
}
