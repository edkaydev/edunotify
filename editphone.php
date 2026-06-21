<?php
require_once('../../config.php');

try {
    global $USER, $DB, $OUTPUT, $PAGE, $CFG, $SESSION;

    $context = context_system::instance();
    $PAGE->set_url('/local/edunotify/editphone.php');
    $PAGE->set_context($context);
    $PAGE->set_title(get_string('editphone', 'local_edunotify'));
    $PAGE->set_heading(get_string('editphone', 'local_edunotify'));

    require_login();

    $action = optional_param('action', '', PARAM_ALPHA);
    $optout = optional_param('optout', 0, PARAM_INT);
    $optin = optional_param('optin', 0, PARAM_INT);
    $courseoptout = optional_param('courseoptout', 0, PARAM_INT);
    $courseoptin = optional_param('courseoptin', 0, PARAM_INT);

    if ($action === 'popup_optout' && confirm_sesskey()) {
        $existing = $DB->get_record('local_edunotify_optout', ['userid' => $USER->id]);
        if ($existing) {
            $DB->set_field('local_edunotify_optout', 'opted_out', 1, ['id' => $existing->id]);
            $DB->set_field('local_edunotify_optout', 'timemodified', time(), ['id' => $existing->id]);
        } else {
            $rec = new \stdClass();
            $rec->userid = $USER->id;
            $rec->opted_out = 1;
            $rec->timecreated = time();
            $rec->timemodified = time();
            $DB->insert_record('local_edunotify_optout', $rec);
        }
        echo 'OK';
        exit;
    }

    if ($optout && confirm_sesskey()) {
        $existing = $DB->get_record('local_edunotify_optout', ['userid' => $USER->id]);
        if ($existing) {
            $DB->set_field('local_edunotify_optout', 'opted_out', 1, ['id' => $existing->id]);
            $DB->set_field('local_edunotify_optout', 'timemodified', time(), ['id' => $existing->id]);
        } else {
            $rec = new \stdClass();
            $rec->userid = $USER->id;
            $rec->opted_out = 1;
            $rec->timecreated = time();
            $rec->timemodified = time();
            $DB->insert_record('local_edunotify_optout', $rec);
        }
        set_user_preference('local_edunotify_phone_dismissed_ts', time());
        \core\notification::success(get_string('optout_success', 'local_edunotify'));
        redirect(new moodle_url('/'));
        exit;
    }

    if ($optin && confirm_sesskey()) {
        $existing = $DB->get_record('local_edunotify_optout', ['userid' => $USER->id]);
        if ($existing) {
            $DB->set_field('local_edunotify_optout', 'opted_out', 0, ['id' => $existing->id]);
            $DB->set_field('local_edunotify_optout', 'timemodified', time(), ['id' => $existing->id]);
        }
        \core\notification::success(get_string('optin_success', 'local_edunotify'));
        redirect($PAGE->url);
        exit;
    }

    if ($courseoptout && confirm_sesskey()) {
        $exists = $DB->get_record('local_edunotify_course_optout', ['userid' => $USER->id, 'courseid' => $courseoptout]);
        if (!$exists) {
            $rec = new \stdClass();
            $rec->userid = $USER->id;
            $rec->courseid = $courseoptout;
            $rec->timecreated = time();
            $DB->insert_record('local_edunotify_course_optout', $rec);
        }
        \core\notification::success(get_string('course_optout_success', 'local_edunotify'));
        redirect($PAGE->url);
        exit;
    }

    if ($courseoptin && confirm_sesskey()) {
        $DB->delete_records('local_edunotify_course_optout', ['userid' => $USER->id, 'courseid' => $courseoptin]);
        \core\notification::success(get_string('course_optin_success', 'local_edunotify'));
        redirect($PAGE->url);
        exit;
    }

    $returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
    if (empty($returnurl)) {
        $returnurl = '/';
    }

    $isstudent = true;

    $error = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
        $phone = trim(optional_param('phone', '', PARAM_RAW));
        $cleaned = preg_replace('/[^0-9]/', '', $phone);

        if (empty($phone)) {
            $error = get_string('phone_required', 'local_edunotify');
        } elseif (!preg_match('/^\d{7,15}$/', $cleaned)) {
            $error = get_string('phone_invalid', 'local_edunotify');
        } else {
            $phonefieldshort = get_config('local_edunotify', 'phonefield');
            $field = null;
            if (!empty($phonefieldshort)) {
                $field = $DB->get_record('user_info_field', ['shortname' => $phonefieldshort]);
            }
            if (!$field) {
                $field = $DB->get_record('user_info_field', ['shortname' => 'phone']);
            }
            if (!$field) {
                $field = $DB->get_record('user_info_field', ['shortname' => 'whatsapp']);
            }

            if ($field) {
                $existing = $DB->get_record('user_info_data', [
                    'userid' => $USER->id,
                    'fieldid' => $field->id,
                ]);
                $record = new \stdClass();
                $record->userid = $USER->id;
                $record->fieldid = $field->id;
                $record->data = $cleaned;
                if ($existing) {
                    $record->id = $existing->id;
                    $DB->update_record('user_info_data', $record);
                } else {
                    $DB->insert_record('user_info_data', $record);
                }
            } else {
                $userobj = new \stdClass();
                $userobj->id = $USER->id;
                $userobj->phone1 = $cleaned;
                user_update_user($userobj, false);
            }

            $notify = optional_param('notify', 1, PARAM_INT);
            $existing = $DB->get_record('local_edunotify_optout', ['userid' => $USER->id]);
            if ($notify) {
                if ($existing) {
                    $DB->set_field('local_edunotify_optout', 'opted_out', 0, ['id' => $existing->id]);
                    $DB->set_field('local_edunotify_optout', 'timemodified', time(), ['id' => $existing->id]);
                }
            } else {
                if ($existing) {
                    $DB->set_field('local_edunotify_optout', 'opted_out', 1, ['id' => $existing->id]);
                    $DB->set_field('local_edunotify_optout', 'timemodified', time(), ['id' => $existing->id]);
                } else {
                    $rec = new \stdClass();
                    $rec->userid = $USER->id;
                    $rec->opted_out = 1;
                    $rec->timecreated = time();
                    $rec->timemodified = time();
                    $DB->insert_record('local_edunotify_optout', $rec);
                }
            }

            \core\notification::success(get_string('phone_saved', 'local_edunotify'));
            redirect(new moodle_url($returnurl));
        }
    }

    $phonefieldshort = get_config('local_edunotify', 'phonefield');
    $field = null;
    if (!empty($phonefieldshort)) {
        $field = $DB->get_record('user_info_field', ['shortname' => $phonefieldshort]);
    }
    if (!$field) {
        $field = $DB->get_record('user_info_field', ['shortname' => 'phone']);
    }
    if (!$field) {
        $field = $DB->get_record('user_info_field', ['shortname' => 'whatsapp']);
    }

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

    $optoutrec = $DB->get_record('local_edunotify_optout', ['userid' => $USER->id]);
    $isoptedout = $optoutrec && $optoutrec->opted_out;

    $courseoptouts = $DB->get_records('local_edunotify_course_optout', ['userid' => $USER->id], '', 'courseid');
    $courseoptoutids = array_keys($courseoptouts);

    $mycourses = enrol_get_users_courses($USER->id, true, 'id, fullname, shortname');

    echo $OUTPUT->header();
    ?>

    <div class="uwn-wrapper" style="max-width:600px;margin:2rem auto;">
        <div class="uwn-card">
            <div class="uwn-card-icon" style="font-size:2rem;text-align:center;margin-bottom:1rem;">[Phone]</div>
            <h2 style="text-align:center;margin-bottom:0.5rem;"><?php echo get_string('editphone', 'local_edunotify'); ?></h2>
            <p style="text-align:center;color:var(--uwn-text-secondary,#6b7280);margin-bottom:1.5rem;">
                <?php echo get_string('editphone_desc', 'local_edunotify'); ?>
            </p>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?php echo s($error); ?></div>
            <?php endif; ?>

            <form method="post" action="<?php echo $PAGE->url; ?>">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="returnurl" value="<?php echo s($returnurl); ?>">
                <div class="form-group">
                    <label for="phone"><?php echo get_string('phone_number', 'local_edunotify'); ?></label>
                    <input type="tel" name="phone" id="phone" class="form-control"
                           value="<?php echo s($phone); ?>"
                           placeholder="e.g. 256746838046"
                           <?php echo empty($phone) ? 'required autofocus' : ''; ?>
                           pattern="[0-9+\s\-()]+"
                           inputmode="numeric">
                    <small class="form-text text-muted">
                        <?php echo get_string('phone_edit_hint', 'local_edunotify'); ?>
                    </small>
                </div>
                <div class="form-group" style="margin-top:1.2rem;">
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:0.95rem;font-weight:400;">
                        <input type="hidden" name="notify" value="0">
                        <input type="checkbox" name="notify" value="1"
                               style="width:18px;height:18px;accent-color:#37e380;cursor:pointer;"
                               <?php echo $isoptedout ? '' : 'checked'; ?>>
                        <?php echo get_string('receive_notifications', 'local_edunotify'); ?>
                    </label>
                </div>
                <div style="text-align:center;margin-top:1.5rem;">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <?php echo get_string('save', 'local_edunotify'); ?>
                    </button>
                </div>
            </form>

            <?php if (!empty($mycourses)): ?>
            <div style="margin-top:2rem;border-top:1px solid var(--uwn-border,#e5e7eb);padding-top:1.5rem;">
                <h3 style="font-size:1rem;margin-bottom:1rem;"><?php echo get_string('course_optout_title', 'local_edunotify'); ?></h3>
                <p style="font-size:0.85rem;color:#6b7280;margin-bottom:1rem;"><?php echo get_string('course_optout_desc', 'local_edunotify'); ?></p>
                <ul style="list-style:none;padding:0;margin:0;">
                    <?php foreach ($mycourses as $mc): ?>
                    <li style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #f3f4f6;">
                        <span style="font-size:0.88rem;"><?php echo s($mc->fullname); ?></span>
                        <?php if (in_array($mc->id, $courseoptoutids)): ?>
                            <a href="?courseoptin=<?php echo $mc->id; ?>&sesskey=<?php echo sesskey(); ?>" class="btn btn-sm btn-outline-success" style="font-size:0.78rem;">
                                <?php echo get_string('optin_course', 'local_edunotify'); ?>
                            </a>
                        <?php else: ?>
                            <a href="?courseoptout=<?php echo $mc->id; ?>&sesskey=<?php echo sesskey(); ?>" class="btn btn-sm btn-outline-danger" style="font-size:0.78rem;">
                                <?php echo get_string('optout_course', 'local_edunotify'); ?>
                            </a>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div style="margin-top:2rem;border-top:1px solid var(--uwn-border,#e5e7eb);padding-top:1rem;text-align:center;">
                <a href="<?php echo s($returnurl); ?>" style="color:#6b7280;font-size:0.85rem;text-decoration:none;">
                    ← <?php echo get_string('back', 'local_edunotify'); ?>
                </a>
            </div>
        </div>
    </div>

    <?php
    echo $OUTPUT->footer();
} catch (\Throwable $e) {
    debugging('local_edunotify editphone failed: ' . $e->getMessage(), DEBUG_NORMAL);
    if (!headers_sent()) {
        redirect(new moodle_url('/'), 'EduNotify error: ' . $e->getMessage(),
            null, \core\output\notification::NOTIFY_ERROR);
    }
}
