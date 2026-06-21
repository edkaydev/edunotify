# EduNotify — WhatsApp Bot for Moodle

Send automatic WhatsApp notifications to students when new activities are added to their courses.

- **Version:** 3.1.0
- **Moodle compatibility:** 4.x – 5.x
- **License:** GPL v3

---

## What It Does

When a teacher adds an activity (assignment, quiz, forum, lesson, etc.) to a course, EduNotify sends a WhatsApp message to every enrolled student who has registered their phone number.

### Example message students receive

```
📢 *EduNotify* 📢

*Course:* Introduction to Computer Science
*Instructor:* Dr. Smith
*Action:* New assignment added
*Item:* Week 1 Assignment
https://moodle.example.com/mod/assign/view.php?id=1

Please log into the portal to view.
```

---

## How It Works (Simple Terms)

```
Teacher adds activity
       ↓
Moodle event fires
       ↓
EduNotify checks: Is this an allowed activity type?
                   Are there students enrolled?
                   Do they have phone numbers?
                   Have they opted out?
       ↓
Message goes into Queue (database table)
       ↓
Cron runs every minute → picks up queued messages
       ↓
Node.js bot sends via WhatsApp Web
       ↓
Log entry saved
```

Everything is **asynchronous** — the teacher never waits for WhatsApp. If the bot is offline, messages stay in the queue and are sent when the bot comes back.

---

## Installation

### 1. Upload the plugin

1. Go to **Site administration → Plugins → Install plugins**
2. Upload `edunotify.zip`
3. Click **Install plugin from the ZIP file**
4. Once installed, click **Upgrade Moodle database**

### 2. Verify tables were created

After installation, Moodle creates 6 tables:
- `local_edunotify_log` — delivery history
- `local_edunotify_recipient` — per-user delivery records (for GDPR)
- `local_edunotify_optout` — users who opted out globally
- `local_edunotify_course_optout` — users who opted out per course
- `local_edunotify_queue` — messages waiting to be sent

### 3. Configure

1. Go to **Site administration → Plugins → Local plugins → EduNotify**
2. **Phone field** — Select the custom profile field that stores phone numbers, or leave as *Auto-detect*
3. **Student role** — Select which role identifies students, or leave as *Auto-detect*
4. **Country code** — Your country's international dialing code (default: `256` for Uganda)
5. **Batch size / pause** — How many messages to send at once and how long to pause between batches (default: 25 messages, then wait 60 seconds)
6. **Message template** — Customize the WhatsApp message format

---

## Server Requirements

| Requirement | Purpose | Install command |
|---|---|---|
| **Node.js 18+** | Runs the WhatsApp bot | `sudo apt install -y nodejs npm` |
| **npm** | Installs bot dependencies | *(included with Node.js)* |
| **Chromium** | WhatsApp Web rendering engine | `sudo apt install -y chromium-browser` |

These must be installed **before** starting the bot.

---

## Starting the Bot

### Step 1: Install system dependencies

Run on your server (one-time):

```bash
sudo apt update
sudo apt install -y nodejs npm chromium-browser
```

### Step 2: Go to the Dashboard

1. **Site administration → Plugins → Local plugins → EduNotify**
2. Click **Go to Dashboard →**

### Step 3: Install bot dependencies

If you see a warning next to *Bot dependencies*, run:

```bash
cd /var/www/html/moodle/local/edunotify/bot
npm install
```

*(The plugin will also auto-install these when you click Start Bot.)*

### Step 4: Start the bot

Click **Start Bot**. The page will refresh and a QR code will appear.

### Step 5: Scan the QR code

1. Open **WhatsApp** on your phone
2. Tap **Menu (⋮) → Linked Devices → Link a Device**
3. Scan the QR code on the dashboard
4. The dashboard will show **Connected** with a green dot

You can now send test messages from the dashboard.

---

## When Notifications Are Sent

Notifications are sent automatically when:

| Event | When it fires |
|---|---|
| **New activity added** | Teacher adds any activity module (assign, quiz, forum, lesson, etc.) to a course |

The plugin monitors only this one event. It does **not** send notifications for:
- Activity updates or changes
- Submission notifications
- Grade updates
- Forum posts
- Completion events

(These were removed to keep the plugin focused and avoid overwhelming students.)

### Allowed module types

You can restrict which activity types trigger notifications in **Settings → Allowed Module Types**. Leave empty to allow all.

Example to only send for assignments and quizzes:
```
assign, quiz
```

---

## Student Experience

### Phone number popup

When a student logs in and has **no phone number** registered, a popup appears:

```
┌─────────────────────────────────────┐
│ 📱                                  │
│ Add your WhatsApp number to receive │
│ course activity alerts.             │
│                                     │
│ [  Add  ] [Remind] [No]            │
└─────────────────────────────────────┘
```

| Button | What happens |
|---|---|
| **Add** | Goes to the phone number entry page |
| **Remind me later** | Closes popup — it will show again on next page load |
| **No** | Opts out permanently — popup never shows again |

The popup appears on **every login** until the student either adds a number or clicks **No**.

### Adding a phone number

1. Click **Add** in the popup, or navigate to the phone settings page
2. Enter your phone number with country code (e.g. `256746838046`)
3. Check **Receive WhatsApp notifications** (uncheck to opt out)
4. Click **Save**

### Opting out

Students can opt out in two ways:
- **From the popup:** Click **No** to permanently stop the popup and opt out
- **From settings:** Uncheck the "Receive WhatsApp notifications" checkbox on the phone entry page

Opted-out students are skipped silently — no log entries, no errors.

### Per-course opt-out

Students with a phone number can opt out of specific courses from the phone settings page. Each course has a toggle:

```
📚 Introduction to Computer Science    [Opt out]
📚 Advanced Mathematics                [Opt in]
```

---

## Delivery Algorithm

```
Message queued
       ↓
Attempt 0 (immediate)
       ↓
  ┌──── Success? ────┐
  │                  │
  Yes                No
  │                  │
  Log sent           Attempt 1 (wait 1 minute)
  Delete queue       │
                     ┌──── Success? ────┐
                     │                  │
                     Yes                No
                     │                  │
                     Log sent           Attempt 2 (wait 5 minutes)
                     Delete queue       │
                                        ┌──── Success? ────┐
                                        │                  │
                                        Yes                No
                                        │                  │
                                        Log sent           Attempt 3 (wait 10 minutes)
                                        Delete queue       │
                                                           ...
                                                           Attempt 6+ (wait 1 hour)
                                                           │
                                                      Retries every hour
                                                      until bot is back
```

### Retry schedule

| Attempt | Delay before next try | Total time since creation |
|---|---|---|
| 0 (initial) | 0 seconds | Immediate |
| 1 | 1 minute | 1 minute |
| 2 | 5 minutes | 6 minutes |
| 3 | 10 minutes | 16 minutes |
| 4 | 30 minutes | 46 minutes |
| 5 | 1 hour | 1 hour 46 minutes |
| 6+ | 1 hour | Keeps retrying every hour |

Messages are **never dropped**. They stay in the queue until successfully sent. If the bot is offline for a week, all messages are sent when it comes back.

### Batch processing

Cron processes up to **50 queued messages** per run, with a **2-second pause** between each message. This prevents WhatsApp rate limits.

---

## Auto-Start on Crash

If the bot crashes, the cron task automatically restarts it:

- **Max restarts per day:** 3
- **Cooldown between restarts:** 20 minutes
- **Resets:** Every day at midnight

The auto-start only triggers if the bot was **not intentionally stopped** by an admin. If an admin clicks **Stop Bot**, the `intentional_stop` flag is set and cron will not auto-start.

---

## Dashboard

The dashboard (`/local/edunotify/index.php`) provides:

### Status card
- **Connected** — WhatsApp is linked and working
- **Connecting** — Bot is running but waiting for QR scan
- **Offline** — Bot is not running

### Stats
- Students with phone numbers / total students
- Total notifications sent

### Controls (admin only)
- **Start Bot** — Launches the bot process
- **Stop Bot** — Stops the bot with `intentional_stop` flag
- **Reconnect** — Generates a fresh QR code
- **Logout & Clear Session** — Disconnects WhatsApp and deletes the session

### Test notification
Send a test WhatsApp message to any phone number to verify everything works.

### Adoption tab
See phone number adoption rates per course with a percentage bar. Admins can send bulk reminders to students without phone numbers.

---

## Admin Settings Reference

| Setting | Description | Default |
|---|---|---|
| **Phone Field** | Custom profile field for phone numbers | Auto-detect |
| **Student Role** | Role used to identify students | Auto-detect |
| **Country Code** | International dialing code for local numbers | `256` |
| **Bot API Port** | Internal port for the Node.js bot API | `5001` |
| **Batch Size** | Messages to send between rate-limit pauses | `25` |
| **Batch Pause** | Seconds to pause between batches | `60` |
| **Allowed Module Types** | Comma-separated list (empty = all) | empty |
| **Message Template** | WhatsApp message format | *(see below)* |

### Message template variables

| Variable | Description |
|---|---|
| `{{courseName}}` | Full name of the course |
| `{{activityTitle}}` | Name of the activity |
| `{{activityUrl}}` | Direct URL to the activity |
| `{{actionType}}` | Type of action (e.g. "New assignment added") |
| `{{instructor}}` | Teacher who created the activity |
| `{{optoutUrl}}` | Link to opt-out page |

---

## Security

- The Node.js bot binds to `127.0.0.1:5001` — **not exposed to the internet**
- API key (64 random characters) is auto-generated on first start
- All API calls include the key as a query parameter
- Only the Moodle server can reach the bot
- Phone numbers are stored in the user profile — same visibility as any other profile field
- Privacy provider implements GDPR export and deletion

---

## Troubleshooting

### Bot won't start

```
⚠️ Node.js       ⚠️ npm       ⚠️ Bot dependencies       ⚠️ Browser (Chrome/Chromium)
```

Run these commands in order:

```bash
sudo apt update
sudo apt install -y nodejs npm chromium-browser
cd /var/www/html/moodle/local/edunotify/bot
sudo npm install
```

Then click **Start Bot** on the dashboard.

### QR code not appearing

1. Check the bot log: `cat /var/www/html/moodle/local/edunotify/bot/bot.log`
2. Ensure port 5001 is free: `ss -tlnp | grep 5001`
3. Click **Reconnect** on the dashboard
4. If still not working, click **Stop Bot** → **Start Bot**

### Messages not being delivered

Check the notification log on the dashboard:
- **Status: Sent** — WhatsApp delivered the message
- **Status: Failed** — Check the error detail column

Common causes:
- WhatsApp session expired: Reconnect via dashboard
- Bot process crashed: Check `bot.log` for errors
- Country code wrong: Messages to local numbers starting with `0` need the correct country code prepended

### Popup shows every time

If a student clicks **Remind me later**, the popup reappears on the next page load. This is by design — it will keep showing until they either add a phone number or click **No** (which opts them out permanently).

---

## Uninstall

1. **Site administration → Plugins → Local plugins → EduNotify**
2. Click **Uninstall**
3. The uninstaller:
   - Kills the bot process
   - Deletes the WhatsApp session
   - Drops all 6 database tables
   - Removes all configuration

No orphan files or database entries remain.

---

## Technical Architecture

```
┌─────────────────────────────────────────────────┐
│                   Moodle Server                  │
│                                                  │
│  ┌─────────────────┐    ┌──────────────────┐    │
│  │  Course module   │    │  Cron task        │    │
│  │  created event  ─┼───→│  (process_queue)  │    │
│  │  (observer.php)  │    │  / minute         │    │
│  └────────┬────────┘    └─────────┬──────────┘    │
│           │                       │               │
│           ▼                       ▼               │
│  ┌────────────────────────────────────────────┐   │
│  │          Database (MariaDB)                 │   │
│  │  ┌──────────────────────────────┐          │   │
│  │  │  local_edunotify_queue       │          │   │
│  │  │  local_edunotify_log         │          │   │
│  │  │  local_edunotify_recipient   │          │   │
│  │  │  local_edunotify_optout      │          │   │
│  │  │  local_edunotify_course_optout│         │   │
│  │  └──────────────────────────────┘          │   │
│  └────────────────────┬───────────────────────┘   │
│                       │                           │
│                       ▼                           │
│  ┌────────────────────────────────────────┐       │
│  │  Node.js Bot (localhost:5001)           │       │
│  │  ┌──────────────────────────┐          │       │
│  │  │  WhatsApp Web Client      │          │       │
│  │  │  (Puppeteer + Chromium)   │          │       │
│  │  └──────────────────────────┘          │       │
│  └────────────────────────────────────────┘       │
│                       │                           │
│                       ▼                           │
│              ┌──────────────┐                     │
│              │  WhatsApp     │                     │
│              │  Cloud/Web    │                     │
│              └──────────────┘                     │
└───────────────────────────────────────────────────┘
```

### Key design decisions

| Decision | Why |
|---|---|
| **Bot runs as separate process** | A crash never affects Moodle. OOM, infinite loop, or segfault → only the bot dies |
| **Queue-based delivery** | WhatsApp may be slow or unavailable. Messages are stored and retried |
| **Exponential backoff** | 1min → 5min → 10min → 30min → 1hr. Prevents hammering the API |
| **Messages never dropped** | The queue keeps items until successfully sent, with hourly retries |
| **Auto-start on crash** | Cron checks if bot died unexpectedly and restarts it (max 3/day) |
| **Single event listener** | Only `course_module_created` to keep the plugin simple and predictable |

---

## Support

For issues, bug reports, or feature requests, contact the plugin author or submit an issue on the plugin's repository.
