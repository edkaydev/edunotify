<?php
defined('MOODLE_INTERNAL') || die();

function local_edunotify_extend_navigation(global_navigation $nav) {
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
}

function local_edunotify_render_navbar_output() {
    global $OUTPUT, $USER, $DB;

    if (!isloggedin() || isguestuser()) {
        return '';
    }

    $output = '';

    $context = context_system::instance();
    if (has_capability('local/edunotify:view', $context)) {
        $botdata = \local_edunotify\bot::get_status();
        $isconnected = $botdata['status'] === 'connected';
        $color = $isconnected ? '#37e380' : '#dc3545';

        $dot = html_writer::tag('span', '●', [
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

    $dismissed = get_user_preferences('local_edunotify_phone_dismissed_ts', 0);
    if ($dismissed && (time() - (int)$dismissed) < 259200) {
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
    $output .= html_writer::tag('div', '📱', ['class' => 'edunotify-popup-icon']);
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
            'id' => 'edunotify-dismiss-forever',
            'data-sesskey' => $sesskey,
        ]
    );
    $output .= html_writer::end_div();
    $output .= html_writer::end_div();
    $output .= html_writer::end_div();
    $output .= html_writer::end_div();

    $output .= html_writer::tag('style', '
        .edunotify-phone-popup-backdrop {
            position:fixed;inset:0;z-index:9998;
            background:rgba(0,0,0,0.3);
            animation:edunotify-fadeIn 0.2s ease;
        }
        .edunotify-phone-popup {
            position:fixed;bottom:24px;right:24px;z-index:9999;
            background:#fff;border-radius:12px;
            box-shadow:0 4px 24px rgba(0,0,0,0.15);
            padding:20px;width:320px;
            font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
            animation:edunotify-slideUp 0.25s ease;
        }
        .edunotify-popup-inner {
            display:flex;align-items:flex-start;gap:12px;
        }
        .edunotify-popup-icon {
            font-size:1.5rem;line-height:1;margin-top:2px;
        }
        .edunotify-popup-body { flex:1; }
        .edunotify-popup-text {
            font-size:0.88rem;color:#374151;
            margin:0 0 16px;line-height:1.5;
        }
        .edunotify-popup-actions { display:flex;gap:8px; }
        .edunotify-btn {
            padding:8px 14px;border-radius:8px;
            font-size:0.82rem;font-weight:600;
            text-decoration:none;text-align:center;
            transition:all 0.15s ease;
        }
        .edunotify-btn-primary {
            background:#37e380;color:#fff;flex:1;
        }
        .edunotify-btn-primary:hover {
            background:#2bc970;color:#fff;text-decoration:none;
        }
        .edunotify-btn-ghost {
            background:#f3f4f6;color:#6b7280;
        }
        .edunotify-btn-ghost:hover {
            background:#e5e7eb;color:#374151;text-decoration:none;
        }
        @keyframes edunotify-slideUp {
            from{transform:translateY(16px);opacity:0}
            to{transform:translateY(0);opacity:1}
        }
        @keyframes edunotify-fadeIn {
            from{opacity:0}to{opacity:1}
        }
    ');

    $output .= html_writer::script('
        document.getElementById("edunotify-dismiss-forever")?.addEventListener("click", function(e) {
            e.preventDefault();
            var x = new XMLHttpRequest();
            x.open("POST", "' . $actionurl . '", true);
            x.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
            x.send("action=dismiss_forever&sesskey=' . $sesskey . '&returnurl=' . urlencode($rawreturn) . '");
            document.getElementById("edunotify-phone-popup")?.remove();
            document.getElementById("edunotify-backdrop")?.remove();
        });
    ');

    return $output;
}
