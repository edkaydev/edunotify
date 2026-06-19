<?php
require_once('../../config.php');
require_once(__DIR__ . '/classes/bot.php');
require_once(__DIR__ . '/classes/util.php');

$context = context_system::instance();
require_login();
require_capability('local/edunotify:view', $context);

$PAGE->set_url('/local/edunotify/index.php');
$PAGE->set_context($context);
$PAGE->set_title(get_string('dashboard', 'local_edunotify'));
$PAGE->set_heading(get_string('dashboard', 'local_edunotify'));
$PAGE->requires->css('/local/edunotify/styles.css');

$action = optional_param('action', '', PARAM_ALPHA);
$tab = optional_param('tab', 'dashboard', PARAM_ALPHA);

if ($action === 'status') {
    $botdata = \local_edunotify\bot::get_status();
    header('Content-Type: application/json');
    echo json_encode($botdata);
    exit;
}

$starterror = '';
$startok = false;
$canmanage = has_capability('local/edunotify:manage', $context);

if ($canmanage && data_submitted() && confirm_sesskey()) {
    if ($action === 'start') {
        if (\local_edunotify\bot::start()) {
            $startok = true;
        } else {
            $starterror = \local_edunotify\bot::get_last_error();
        }
    }

    if ($action === 'stop') {
        if (\local_edunotify\bot::stop()) {
            redirect($PAGE->url, get_string('bot_stopped', 'local_edunotify'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        }
        redirect($PAGE->url, get_string('bot_stop_fail', 'local_edunotify'), null,
            \core\output\notification::NOTIFY_ERROR);
    }

    if ($action === 'reconnect') {
        $result = \local_edunotify\bot::reconnect();
        if ($result && !empty($result['success'])) {
            redirect($PAGE->url, get_string('bot_reconnecting', 'local_edunotify'), null,
                \core\output\notification::NOTIFY_INFO);
        } else {
            redirect($PAGE->url, get_string('bot_reconnect_fail', 'local_edunotify'), null,
                \core\output\notification::NOTIFY_ERROR);
        }
    }

    if ($action === 'logout') {
        if (\local_edunotify\bot::logout()) {
            redirect($PAGE->url, get_string('bot_logout', 'local_edunotify'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        }
        redirect($PAGE->url, get_string('bot_stop_fail', 'local_edunotify'), null,
            \core\output\notification::NOTIFY_ERROR);
    }

    if ($action === 'test') {
        $testnumber = optional_param('testnumber', '', PARAM_RAW);
        if (!empty($testnumber)) {
            $cleaned = preg_replace('/[^0-9+]/', '', $testnumber);
            if (!preg_match('/^\+?[1-9]\d{6,14}$/', $cleaned)) {
                redirect($PAGE->url, get_string('phone_invalid', 'local_edunotify'), null,
                    \core\output\notification::NOTIFY_ERROR);
            }
            $result = \local_edunotify\bot::send_test($testnumber, get_string('test_message', 'local_edunotify'));
            if ($result && !empty($result['success'])) {
                redirect($PAGE->url, get_string('test_sent', 'local_edunotify', s($testnumber)), null,
                    \core\output\notification::NOTIFY_SUCCESS);
            } else {
                $errmsg = $result['error'] ?? get_string('test_failed', 'local_edunotify');
                redirect($PAGE->url, get_string('test_failed', 'local_edunotify') . ' ' . $errmsg, null,
                    \core\output\notification::NOTIFY_ERROR);
            }
        }
        redirect($PAGE->url);
    }

    if ($action === 'bulk_reminder') {
        $courseid = required_param('courseid', PARAM_INT);
        $course = $DB->get_record('course', ['id' => $courseid]);
        if ($course) {
            $contextc = \context_course::instance($courseid);
            $role = \local_edunotify\util::get_student_role();
            if ($role) {
                $enrolled = get_enrolled_users($contextc, 'mod/forum:viewdiscussion', 0, 'u.*', null, 0, 0, true);
                $students = [];
                foreach ($enrolled as $u) {
                    $roles = get_user_roles($contextc, $u->id, true);
                    foreach ($roles as $r) {
                        if ($r->shortname === $role->shortname) { $students[] = $u; break; }
                    }
                }
                $nophone = 0;
                foreach ($students as $u) {
                    $phone = \local_edunotify\util::get_user_phone($u);
                    if (!$phone) $nophone++;
                }
                redirect($PAGE->url . '?tab=adoption', get_string('bulk_reminder_sent', 'local_edunotify', $nophone), null,
                    \core\output\notification::NOTIFY_SUCCESS);
            }
        }
        redirect($PAGE->url . '?tab=adoption');
    }
}

$botrunning = \local_edunotify\bot::is_running();
$botdata = \local_edunotify\bot::get_status();

global $DB;

$phonefield = \local_edunotify\util::get_phone_field();

$totalwithphones = 0;
if ($phonefield) {
    $totalwithphones = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT d.userid) FROM {user_info_data} d WHERE d.fieldid = ? AND d.data != '' AND d.data IS NOT NULL",
        [$phonefield->id]
    );
}

$phonebuiltin = 0;
if (!$phonefield) {
    $phonebuiltin = $DB->count_records_sql(
        "SELECT COUNT(id) FROM {user} WHERE (phone1 != '' AND phone1 IS NOT NULL) OR (phone2 != '' AND phone2 IS NOT NULL)"
    );
}
$totalwithphones = max($totalwithphones, $phonebuiltin);

$studentrole = \local_edunotify\util::get_student_role();
$totalstudents = 0;
if ($studentrole) {
    $totalstudents = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT ra.userid) FROM {role_assignments} ra JOIN {context} ctx ON ctx.id = ra.contextid WHERE ra.roleid = ? AND ctx.contextlevel = ?",
        [$studentrole->id, CONTEXT_COURSE]
    );
}

$page = optional_param('page', 0, PARAM_INT);
$perpage = 20;
$totalcount = $DB->count_records('local_edunotify_log');
$recentlogs = $DB->get_records('local_edunotify_log', null, 'timecreated DESC', '*', $page * $perpage, $perpage);

echo $OUTPUT->header();

if ($startok) {
    echo $OUTPUT->notification(get_string('bot_started', 'local_edunotify'), \core\output\notification::NOTIFY_SUCCESS);
}
if (!empty($starterror)) {
    echo $OUTPUT->notification($starterror, \core\output\notification::NOTIFY_ERROR);
}
?>

<div class="uwn-wrapper" id="edunotify-dashboard-container">
    <div class="uwn-header">
        <div class="uwn-header-brand">
            <img src="<?php echo $OUTPUT->image_url('icon', 'local_edunotify'); ?>" alt="">
            <div>
                <h1><?php echo get_string('dashboard', 'local_edunotify'); ?></h1>
                <p><?php echo get_string('dashboard_desc', 'local_edunotify'); ?></p>
            </div>
        </div>
        <?php if ($canmanage): ?>
        <div class="uwn-header-actions">
            <?php if (!$botrunning): ?>
                <form method="post" action="<?php echo $PAGE->url; ?>" style="display:inline;">
                    <input type="hidden" name="action" value="start">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <button type="submit" class="uwn-btn uwn-btn-primary"><?php echo get_string('start_bot', 'local_edunotify'); ?></button>
                </form>
            <?php else: ?>
                <form method="post" action="<?php echo $PAGE->url; ?>" style="display:inline;" onsubmit="return confirm('<?php echo addslashes(get_string('confirm_stop', 'local_edunotify')); ?>')">
                    <input type="hidden" name="action" value="stop">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <button type="submit" class="uwn-btn uwn-btn-outline" id="uwn-stop-btn"><?php echo get_string('stop_bot', 'local_edunotify'); ?></button>
                </form>
                <form method="post" action="<?php echo $PAGE->url; ?>" style="display:inline;">
                    <input type="hidden" name="action" value="reconnect">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <button type="submit" class="uwn-btn uwn-btn-warning"><?php echo get_string('reconnect', 'local_edunotify'); ?></button>
                </form>
                <form method="post" action="<?php echo $PAGE->url; ?>" style="display:inline;" onsubmit="return confirm('<?php echo addslashes(get_string('confirm_logout', 'local_edunotify')); ?>')">
                    <input type="hidden" name="action" value="logout">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <button type="submit" class="uwn-btn uwn-btn-danger" id="uwn-logout-btn"><?php echo get_string('logout', 'local_edunotify'); ?></button>
                </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="uwn-tabs" style="display:flex;gap:4px;margin-bottom:20px;border-bottom:2px solid #e5e7eb;padding-bottom:0;">
        <a href="<?php echo $PAGE->url; ?>?tab=dashboard" class="uwn-tab <?php echo $tab === 'dashboard' ? 'active' : ''; ?>" style="padding:10px 18px;text-decoration:none;font-weight:600;font-size:0.9rem;border-bottom:2px solid <?php echo $tab === 'dashboard' ? '#37e380' : 'transparent'; ?>;margin-bottom:-2px;color:<?php echo $tab === 'dashboard' ? '#111827' : '#6b7280'; ?>;">
            📊 <?php echo get_string('dashboard', 'local_edunotify'); ?>
        </a>
        <a href="<?php echo $PAGE->url; ?>?tab=adoption" class="uwn-tab <?php echo $tab === 'adoption' ? 'active' : ''; ?>" style="padding:10px 18px;text-decoration:none;font-weight:600;font-size:0.9rem;border-bottom:2px solid <?php echo $tab === 'adoption' ? '#37e380' : 'transparent'; ?>;margin-bottom:-2px;color:<?php echo $tab === 'adoption' ? '#111827' : '#6b7280'; ?>;">
            📱 <?php echo get_string('phone_adoption', 'local_edunotify'); ?>
        </a>
    </div>

<?php if ($tab === 'adoption'): ?>
    <div class="uwn-section">
        <h2><?php echo get_string('phone_adoption', 'local_edunotify'); ?></h2>
        <p class="uwn-section-desc"><?php echo get_string('phone_adoption_desc', 'local_edunotify'); ?></p>

        <?php
        $sql = "SELECT c.id, c.fullname, c.shortname,
                       COUNT(DISTINCT u.id) AS totalstudents,
                       COUNT(DISTINCT CASE WHEN idata.data IS NOT NULL AND idata.data != '' THEN u.id END) AS withphone
                FROM {course} c
                JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = ?
                JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.roleid = ?
                JOIN {user} u ON u.id = ra.userid
                LEFT JOIN {user_info_data} idata ON idata.userid = u.id " . ($phonefield ? "AND idata.fieldid = ?" : "AND 1=0") . "
                WHERE c.id != ?
                GROUP BY c.id, c.fullname, c.shortname
                ORDER BY withphone ASC, totalstudents DESC";
        $params = [CONTEXT_COURSE, $studentrole->id];
        if ($phonefield) $params[] = $phonefield->id;
        $params[] = SITEID;
        $courses = $DB->get_records_sql($sql, $params);
        ?>

        <div class="uwn-table-wrap">
            <table class="uwn-table">
                <thead>
                    <tr>
                        <th><?php echo get_string('course', 'local_edunotify'); ?></th>
                        <th><?php echo get_string('total_students', 'local_edunotify'); ?></th>
                        <th><?php echo get_string('students_with_phones', 'local_edunotify'); ?></th>
                        <th><?php echo get_string('adoption_pct', 'local_edunotify'); ?></th>
                        <?php if ($canmanage): ?>
                        <th><?php echo get_string('actions', 'local_edunotify'); ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($courses as $c):
                        $pct = $c->totalstudents > 0 ? round(($c->withphone / $c->totalstudents) * 100) : 0;
                    ?>
                    <tr>
                        <td><?php echo s($c->fullname); ?></td>
                        <td><?php echo $c->totalstudents; ?></td>
                        <td><?php echo $c->withphone; ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <div style="flex:1;height:8px;background:#e5e7eb;border-radius:4px;max-width:120px;">
                                    <div style="height:8px;background:<?php echo $pct > 50 ? '#37e380' : ($pct > 20 ? '#f59e0b' : '#ef4444'); ?>;border-radius:4px;width:<?php echo $pct; ?>%;"></div>
                                </div>
                                <span><?php echo $pct; ?>%</span>
                            </div>
                        </td>
                        <?php if ($canmanage): ?>
                        <td>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="bulk_reminder">
                                <input type="hidden" name="courseid" value="<?php echo $c->id; ?>">
                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                <button type="submit" class="uwn-btn uwn-btn-sm" style="font-size:0.78rem;padding:4px 10px;"><?php echo get_string('remind', 'local_edunotify'); ?></button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php else: ?>

    <?php if (!$botrunning): ?>
    <div class="uwn-section">
        <h2><?php echo get_string('setup_status', 'local_edunotify'); ?></h2>
        <?php
            $hasnode = trim(shell_exec('which node 2>/dev/null') ?? '');
            $hasnpm = trim(shell_exec('which npm 2>/dev/null') ?? '');
            $haschrome = \local_edunotify\chrome_detector::detect();
            $hasdeps = is_dir(__DIR__ . '/bot/node_modules');
            $checks = [
                get_string('check_node', 'local_edunotify') => !empty($hasnode),
                get_string('check_npm', 'local_edunotify') => !empty($hasnpm),
                get_string('check_deps', 'local_edunotify') => $hasdeps,
                get_string('check_chrome', 'local_edunotify') => !empty($haschrome),
            ];
        ?>
        <div class="uwn-check-grid">
            <?php foreach ($checks as $label => $ok): ?>
            <span class="uwn-check-badge <?php echo $ok ? 'ok' : 'missing'; ?>">
                <?php echo $ok ? '✅' : '⚠️'; ?> <?php echo $label; ?>
            </span>
            <?php endforeach; ?>
        </div>
        <p class="uwn-section-desc"><?php echo get_string('setup_hint', 'local_edunotify'); ?></p>
    </div>
    <?php endif; ?>

    <div class="uwn-grid">
        <div class="uwn-card uwn-card-status <?php echo $botdata['status']; ?>">
            <div class="uwn-card-status-icon">
                <?php if ($botdata['status'] === 'connected'): ?>
                    <span class="uwn-dot uwn-dot-green"></span>
                <?php elseif ($botrunning): ?>
                    <span class="uwn-dot uwn-dot-yellow"></span>
                <?php else: ?>
                    <span class="uwn-dot uwn-dot-red"></span>
                <?php endif; ?>
            </div>
            <div class="uwn-card-body">
                <h3><?php echo get_string('bot_status', 'local_edunotify'); ?></h3>
                <div class="uwn-status-label">
                    <?php echo $botdata['status'] === 'connected' ? get_string('connected', 'local_edunotify') : ($botrunning ? get_string('connecting', 'local_edunotify') : get_string('offline', 'local_edunotify')); ?>
                </div>
                <div class="uwn-status-msg"><?php echo s($botdata['message'] ?? ''); ?></div>
            </div>
        </div>

        <div class="uwn-card">
            <div class="uwn-card-icon">📱</div>
            <div class="uwn-card-body">
                <h3><?php echo get_string('students_with_phones', 'local_edunotify'); ?></h3>
                <div class="uwn-stat"><?php echo $totalwithphones; ?></div>
                <div class="uwn-stat-sub">/ <?php echo $totalstudents; ?> <?php echo get_string('total_students', 'local_edunotify'); ?></div>
            </div>
        </div>

        <div class="uwn-card">
            <div class="uwn-card-icon">📨</div>
            <div class="uwn-card-body">
                <h3><?php echo get_string('recent_notifications', 'local_edunotify'); ?></h3>
                <div class="uwn-stat"><?php echo $totalcount; ?></div>
                <div class="uwn-stat-sub"><?php echo get_string('total_all_time', 'local_edunotify'); ?></div>
            </div>
        </div>
    </div>

    <?php $hasqr = $botrunning && !empty($botdata['qr']); ?>
    <div class="uwn-section uwn-qr-section" id="uwn-qr-section" style="<?php echo $hasqr ? '' : 'display:none;'; ?>">
        <div class="uwn-qr-inner">
            <h2><?php echo get_string('scan_qr', 'local_edunotify'); ?></h2>
            <p><?php echo get_string('scan_qr_desc', 'local_edunotify'); ?></p>
            <div class="uwn-qr-box">
                <img src="<?php echo $hasqr ? $botdata['qr'] : ''; ?>" alt="QR Code" id="uwn-qr-img">
            </div>
            <p class="uwn-qr-hint"><?php echo get_string('qr_expiry', 'local_edunotify'); ?></p>
            <?php if ($canmanage): ?>
            <form method="post" action="<?php echo $PAGE->url; ?>" style="display:inline;">
                <input type="hidden" name="action" value="reconnect">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <button type="submit" class="uwn-btn uwn-btn-warning"><?php echo get_string('refresh_qr', 'local_edunotify'); ?></button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($canmanage && $botrunning): ?>
    <div class="uwn-section">
        <h2><?php echo get_string('test_notification', 'local_edunotify'); ?></h2>
        <p class="uwn-section-desc"><?php echo get_string('test_desc', 'local_edunotify'); ?></p>
        <form method="post" action="<?php echo $PAGE->url; ?>" class="uwn-test-form">
            <input type="hidden" name="action" value="test">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <div class="uwn-input-group">
                <?php $ecc = get_config('local_edunotify', 'countrycode') ?: '256'; ?>
                <input type="text" name="testnumber" class="uwn-input"
                       placeholder="e.g. <?php echo $ecc; ?>746838046" required
                       pattern="[0-9+\s\-()]+" inputmode="numeric"
                       <?php echo $botdata['status'] !== 'connected' ? 'disabled' : ''; ?>>
                <button type="submit" class="uwn-btn uwn-btn-primary"
                        <?php echo $botdata['status'] !== 'connected' ? 'disabled' : ''; ?>>
                    <?php echo get_string('send', 'local_edunotify'); ?>
                </button>
            </div>
            <?php if ($botdata['status'] !== 'connected'): ?>
                <small class="uwn-field-hint"><?php echo get_string('connect_first', 'local_edunotify'); ?></small>
            <?php endif; ?>
        </form>
    </div>

    <div class="uwn-section">
        <h2><?php echo get_string('preview_message', 'local_edunotify'); ?></h2>
        <p class="uwn-section-desc"><?php echo get_string('preview_desc', 'local_edunotify'); ?></p>
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:16px;font-family:monospace;font-size:0.85rem;white-space:pre-wrap;max-width:500px;">
<?php
$preview = "📢 *EduNotify* 📢\n\n*Course:* " . get_string('preview_sample_course', 'local_edunotify') . "\n*Instructor:* " . get_string('preview_sample_instructor', 'local_edunotify') . "\n*Action:* New assignment added\n*Item:* " . get_string('preview_sample_activity', 'local_edunotify') . "\nhttps://moodle.example.com/mod/assign/view.php?id=1\n\n" . get_string('preview_footer', 'local_edunotify');
echo s($preview);
?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($recentlogs)): ?>
    <div class="uwn-section">
        <h2><?php echo get_string('notification_log', 'local_edunotify'); ?></h2>
        <div class="uwn-table-wrap">
            <table class="uwn-table">
                <thead>
                    <tr>
                        <th><?php echo get_string('time', 'local_edunotify'); ?></th>
                        <th><?php echo get_string('course', 'local_edunotify'); ?></th>
                        <th><?php echo get_string('activity', 'local_edunotify'); ?></th>
                        <th><?php echo get_string('event_type', 'local_edunotify'); ?></th>
                        <th><?php echo get_string('recipients', 'local_edunotify'); ?></th>
                        <th><?php echo get_string('status', 'local_edunotify'); ?></th>
                        <th><?php echo get_string('error_detail', 'local_edunotify'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentlogs as $log):
                        $coursename = $DB->get_field('course', 'fullname', ['id' => $log->courseid]);
                    ?>
                    <tr>
                        <td><?php echo userdate($log->timecreated); ?></td>
                        <td><?php echo $coursename ? s($coursename) : 'ID: ' . $log->courseid; ?></td>
                        <td><span class="uwn-badge"><?php echo s($log->modname); ?></span></td>
                        <td><?php echo s($log->eventtype ?? '-'); ?></td>
                        <td><?php echo $log->recipients; ?></td>
                        <td><?php if ($log->status === 'sent'): ?>
                            <span class="uwn-status-sent">✔ <?php echo get_string('sent', 'local_edunotify'); ?></span>
                        <?php else: ?>
                            <span class="uwn-status-failed">✘ <?php echo get_string('failed', 'local_edunotify'); ?></span>
                        <?php endif; ?></td>
                        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;font-size:0.8rem;color:#6b7280;">
                            <?php echo s($log->error_msg ?? ''); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalcount > $perpage): ?>
        <div style="margin-top:16px;text-align:center;">
            <?php
            $urlwithtab = $PAGE->url . '?tab=dashboard&page={$page}';
            $pagingbar = new \paging_bar($totalcount, $page, $perpage, $urlwithtab);
            $pagingbar->maxdisplay = 10;
            echo $OUTPUT->render($pagingbar);
            ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>
</div>

<script>
(function() {
    var container = document.getElementById('edunotify-dashboard-container');
    if (!container) return;

    var qrImg = document.getElementById('uwn-qr-img');
    var qrSection = document.getElementById('uwn-qr-section');
    var statusLabel = container.querySelector('.uwn-status-label');
    var statusMsg = container.querySelector('.uwn-status-msg');
    var statusIcon = container.querySelector('.uwn-dot');

    <?php if ($botrunning && $botdata['status'] !== 'connected'): ?>
    var pollCount = 0;
    var pollTimer = setInterval(function() {
        pollCount++;
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '<?php echo $PAGE->url; ?>?action=status&ts=' + Date.now(), true);
        xhr.onload = function() {
            if (xhr.status === 200 && xhr.responseText) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.status === 'connected') {
                        location.reload();
                    }
                    if (data.qr && qrImg) {
                        qrImg.src = data.qr;
                        if (qrSection) qrSection.style.display = 'block';
                    }
                    if (data.message && statusMsg) statusMsg.textContent = data.message;
                    if (data.status && statusLabel) {
                        var labels = {
                            connected: '<?php echo addslashes(get_string('connected', 'local_edunotify')); ?>',
                            awaiting_scan: '<?php echo addslashes(get_string('scan_qr', 'local_edunotify')); ?>',
                            initializing: '<?php echo addslashes(get_string('connecting', 'local_edunotify')); ?>'
                        };
                        statusLabel.textContent = labels[data.status] || data.status;
                    }
                    if (data.status === 'awaiting_scan' || data.status === 'connected') {
                        if (statusIcon) {
                            statusIcon.className = 'uwn-dot ' + (data.status === 'connected' ? 'uwn-dot-green' : 'uwn-dot-yellow');
                        }
                    }
                } catch(e) {}
            }
        };
        xhr.send();
    }, 3000);
    <?php endif; ?>
})();
</script>

<?php
echo $OUTPUT->footer();
