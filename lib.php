<?php
defined('MOODLE_INTERNAL') || die();

function local_edunotify_extend_navigation(global_navigation $nav) {
    try {
        $context = context_system::instance();
        if (!has_capability('local/edunotify:view', $context)) {
            return;
        }

        $node = navigation_node::create(
            get_string('pluginname', 'local_edunotify'),
            new moodle_url('/local/edunotify/index.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'edunotify',
            new pix_icon('icon', '', 'local_edunotify')
        );
        $nav->add_node($node);
    } catch (\Throwable $e) {
        debugging('local_edunotify extend_navigation failed: ' . $e->getMessage(), DEBUG_NORMAL);
    }
}

function local_edunotify_render_navbar_output() {
    try {
        global $OUTPUT, $USER, $DB;

        if (!isloggedin() || isguestuser()) {
            return '';
        }

        $output = '';

        $context = context_system::instance();
        if (has_capability('local/edunotify:view', $context)) {
            $botdata = \local_edunotify\bot::get_status();
            $isconnected = is_array($botdata) && ($botdata['status'] ?? '') === 'connected';
            $color = $isconnected ? '#37e380' : '#dc3545';

            $dot = html_writer::tag('span', "\xE2\x97\x8F", [
                'style' => "color:$color;font-size:1.3rem;line-height:1;position:absolute;top:-4px;right:-6px;text-shadow:0 0 3px rgba(0,0,0,0.3);",
            ]);

            $icon = $OUTPUT->pix_icon('icon', get_string('pluginname', 'local_edunotify'), 'local_edunotify', [
                'class' => 'icon',
                'style' => 'width:22px;height:22px;',
            ]);

            $output .= html_writer::div(
                html_writer::link(
                    new moodle_url('/local/edunotify/index.php'),
                    $icon . $dot,
                    [
                        'class' => 'nav-link',
                        'title' => get_string('pluginname', 'local_edunotify') . ' — ' .
                                   ($isconnected ? get_string('connected', 'local_edunotify') : get_string('offline', 'local_edunotify')),
                        'style' => 'position:relative;display:inline-block;padding:6px 8px;',
                    ]
                ),
                'd-flex align-items-center'
            );
        }

        if (is_siteadmin()) {
            return $output;
        }

        $studentrole = \local_edunotify\util::get_student_role();
        if (!$studentrole || !user_has_role_assignment($USER->id, $studentrole->id)) {
            return $output;
        }

        $optoutrec = $DB->get_record('local_edunotify_optout', ['userid' => $USER->id]);
        if ($optoutrec && $optoutrec->opted_out) {
            return $output;
        }

        $field = \local_edunotify\util::get_phone_field();

        $phone = '';
        if ($field) {
            $phone = $DB->get_field('user_info_data', 'data', [
                'userid' => $USER->id,
                'fieldid' => $field->id,
            ]);
        } else {
            $phone = $USER->phone1 ?? $USER->phone2 ?? '';
        }
        $phone = trim($phone ?? '');
        if (!empty($phone)) {
            return $output;
        }

        $sesskey = sesskey();
        $rawreturn = $_SERVER['REQUEST_URI'] ?? '/';
        $actionurl = new moodle_url('/local/edunotify/editphone.php');

        $output .= html_writer::div('', 'edunotify-phone-popup-backdrop', ['id' => 'edunotify-backdrop']);
        $output .= html_writer::start_div('edunotify-phone-popup', ['id' => 'edunotify-phone-popup']);
        $output .= html_writer::start_div('edunotify-popup-inner');
        $output .= html_writer::tag('div', '+', ['class' => 'edunotify-popup-icon']);
        $output .= html_writer::start_div('edunotify-popup-body');
        $output .= html_writer::tag('p', get_string('phone_warning', 'local_edunotify'), ['class' => 'edunotify-popup-text']);
        $output .= html_writer::start_div('edunotify-popup-actions');
        $output .= html_writer::link(
            new moodle_url('/local/edunotify/editphone.php', ['returnurl' => $rawreturn]),
            get_string('add_number', 'local_edunotify'),
            ['class' => 'edunotify-btn edunotify-btn-primary']
        );
        $output .= html_writer::link(
            '#',
            get_string('dismiss_3days', 'local_edunotify'),
            [
                'class' => 'edunotify-btn edunotify-btn-ghost',
                'id' => 'edunotify-dismiss-later',
            ]
        );
        $output .= html_writer::link(
            '#',
            get_string('dismiss_forever', 'local_edunotify'),
            [
                'class' => 'edunotify-btn edunotify-btn-ghost',
                'id' => 'edunotify-popup-optout',
                'data-sesskey' => $sesskey,
            ]
        );
        $output .= html_writer::end_div();
        $output .= html_writer::end_div();
        $output .= html_writer::end_div();
        $output .= html_writer::end_div();

        $opts = json_encode([
            'actionUrl' => $actionurl->out(),
            'sesskey' => $sesskey,
            'returnurl' => $rawreturn,
        ]);
        $output .= html_writer::script('document.addEventListener("DOMContentLoaded",function(){var o=' . $opts . ';document.getElementById("edunotify-dismiss-later")?.addEventListener("click",function(e){e.preventDefault();["edunotify-phone-popup","edunotify-backdrop"].forEach(function(i){document.getElementById(i)?.remove()})});document.getElementById("edunotify-popup-optout")?.addEventListener("click",function(e){e.preventDefault();var x=new XMLHttpRequest();x.open("POST",o.actionUrl,true);x.setRequestHeader("Content-Type","application/x-www-form-urlencoded");x.send("action=popup_optout&sesskey="+o.sesskey+"&returnurl="+encodeURIComponent(o.returnurl));["edunotify-phone-popup","edunotify-backdrop"].forEach(function(i){document.getElementById(i)?.remove()})})});');

        return $output;
    } catch (\Throwable $e) {
        debugging('local_edunotify render_navbar_output failed: ' . $e->getMessage(), DEBUG_NORMAL);
        return '';
    }
}
