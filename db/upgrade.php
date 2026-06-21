<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_edunotify_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026061700) {
        $table = new xmldb_table('local_edunotify_log');

        $oldtable = new xmldb_table('local_umuvanotify_log');
        if ($dbman->table_exists($oldtable)) {
            $dbman->rename_table($oldtable, 'local_edunotify_log');
        }

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('modname', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL);
            $table->add_field('recipients', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $table->add_index('idx_timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026061700, 'local', 'edunotify');
    }

    if ($oldversion < 2026061701) {
        upgrade_plugin_savepoint(true, 2026061701, 'local', 'edunotify');
    }

    if ($oldversion < 2026061702) {
        upgrade_plugin_savepoint(true, 2026061702, 'local', 'edunotify');
    }

    if ($oldversion < 2026061900) {
        $table = new xmldb_table('local_edunotify_log');
        $field = new xmldb_field('eventtype', XMLDB_TYPE_CHAR, '50');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('local_edunotify_recipient');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('logid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('phone', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
            $table->add_field('error_msg', XMLDB_TYPE_TEXT);
            $table->add_field('delivery_ts', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('read_ts', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('logid_fk', XMLDB_KEY_FOREIGN, ['logid'], 'local_edunotify_log', ['id']);
            $table->add_index('idx_userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_edunotify_optout');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('opted_out', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('userid_uq', XMLDB_KEY_UNIQUE, ['userid']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_edunotify_templates');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('eventtype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL);
            $table->add_field('name', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
            $table->add_field('header', XMLDB_TYPE_TEXT);
            $table->add_field('body', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
            $table->add_field('footer', XMLDB_TYPE_TEXT);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_eventtype', XMLDB_INDEX_NOTUNIQUE, ['eventtype']);
            $dbman->create_table($table);
        }

        if (!get_config('local_edunotify', 'apikey')) {
            $apikey = hash('sha256', random_bytes(32));
            set_config('apikey', $apikey, 'local_edunotify');
        }

        set_config('batchsize', 25, 'local_edunotify');
        set_config('batchpause', 60, 'local_edunotify');
        set_config('botport', 5001, 'local_edunotify');

        $defaultbody = "*EduNotify*\n\n*Course:* {{courseName}}\n*Instructor:* {{instructor}}\n*Action:* {{actionType}}\n*Item:* {{activityTitle}}\n{{activityUrl}}\n\nPlease log into the portal to view.\n\nTo opt out, reply STOP or visit: {{optoutUrl}}";
        set_config('template_course_module_created', $defaultbody, 'local_edunotify');

        upgrade_plugin_savepoint(true, 2026061900, 'local', 'edunotify');
    }

    if ($oldversion < 2026061901) {
        $table = new xmldb_table('local_edunotify_log');
        $field = new xmldb_field('error_msg', XMLDB_TYPE_TEXT);
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('local_edunotify_queue');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('payload', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
            $table->add_field('attempts', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_attempts', XMLDB_INDEX_NOTUNIQUE, ['attempts']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_edunotify_course_optout');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_key('courseid_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_edunotify_course_optout');
        $index = new xmldb_index('idx_userid_courseid', XMLDB_INDEX_UNIQUE, ['userid', 'courseid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026061901, 'local', 'edunotify');
    }

    if ($oldversion < 2026061902) {
        $table = new xmldb_table('local_edunotify_templates');
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }
        upgrade_plugin_savepoint(true, 2026061902, 'local', 'edunotify');
    }

    if ($oldversion < 2026061903) {
        unset_config('quietstart', 'local_edunotify');
        unset_config('quietend', 'local_edunotify');
        upgrade_plugin_savepoint(true, 2026061903, 'local', 'edunotify');
    }

    if ($oldversion < 2026061905) {
        $table = new xmldb_table('local_edunotify_queue');
        $index = new xmldb_index('idx_timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        upgrade_plugin_savepoint(true, 2026061905, 'local', 'edunotify');
    }


    if ($oldversion < 2026061906) {
        $table = new xmldb_table('local_edunotify_log');
        $field = new xmldb_field('eventtype', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
        $dbman->change_field_type($table, $field);
        upgrade_plugin_savepoint(true, 2026061906, 'local', 'edunotify');
    }

    if ($oldversion < 2026061907) {
        $template = "[EN] *EduNotify* [EN]\n\n*Course:* {{courseName}}\n*Instructor:* {{instructor}}\n*Action:* {{actionType}}\n*Item:* {{activityTitle}}\n{{activityUrl}}\n\nPlease log into the portal to view.\n\nTo opt out, reply STOP or visit: {{optoutUrl}}";
        set_config('template_course_module_created', $template, 'local_edunotify');
        upgrade_plugin_savepoint(true, 2026061907, 'local', 'edunotify');
    }

    return true;
}
