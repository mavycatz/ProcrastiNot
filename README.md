# ProcrastiNot

> **Your deadlines called. We answered.**

A beginner-friendly **Student Task Management System** that runs locally on
XAMPP (PHP 8 + MySQL). Track assignments, deadlines, and study tasks in a
playful glassmorphism workspace built on a violet, lavender, mint and
baby-blue palette — in light or dark theme.

- Register and log in securely (hashed passwords, sessions, CSRF protection)
- Create, edit, complete, and delete tasks with importance, due dates, and an
  estimated time
- **Smart Priority Engine** — unfinished tasks are automatically ranked by a
  transparent, rule-based score, with a plain-English reason for every ranking
- **Just 5 Minutes** — a low-pressure focus timer that records how long you
  actually worked on a task, plus optional step-by-step breakdowns for big
  assignments
- **Comeback Mode** — a kind, no-shame way to restart overdue work: pick a task,
  see one realistic next action, and move a deadline only with your approval
- **Optional XP, levels, streaks and badges** (Anti-Cramming) that you can switch
  off at any time — missing a deadline never costs you points
- **My Account** — one calm page for your profile (initials avatar, name, email,
  course, year level, member-since date), a secure change-password form with
  current-password verification, appearance and preference shortcuts, your
  personal productivity summary, and log out
- A **task-first dashboard** — the answer to "what should I do next?" is always
  at the top, with one recommended task, two primary statistics and the rest
  tucked into compact, expandable sections
- Organize work with views: All Tasks, My Day, Upcoming, Completed, Overdue
- Search, filter, and sort your tasks (including a "Smart (recommended)" sort)
- See deadlines on a monthly calendar
- Study your habits on dashboard and reports charts (Chart.js): weekly
  daily-completion bars, a status doughnut with the total in its centre, focus
  minutes by weekday, an overdue trend, and optional XP-over-time
- Navigate comfortably on any screen — a compact desktop sidebar, a phone
  header with your avatar, a five-item bottom navigation bar (Home, Tasks,
  Calendar, Progress, More), and an account popover for everything else
- Switch between light and dark themes at any time (your choice is remembered)
- Everything is computed from **your real data** — no fake sample numbers

---

## Requirements

- [XAMPP](https://www.apachefriends.org/) with **Apache**, **PHP 8+**,
  **MySQL**, and **phpMyAdmin** (the default XAMPP install includes all of these)
- A modern web browser (Chrome, Edge, or Firefox)
- Internet is only needed once for optional fonts, icons, and charts — the app
  works fully offline without them

---

## Installation (step by step)

### 1. Place the project folder

Copy this entire folder into your XAMPP web root so the final path is:

```
C:\xampp\htdocs\tasktrack\
```

(On macOS/Linux it is usually `/opt/lampp/htdocs/tasktrack/`.)

### 2. Start XAMPP

Open the **XAMPP Control Panel** and start **Apache** and **MySQL**. Both
should show green "Running" labels.

### 3. Import the database with phpMyAdmin

1. Open <http://localhost/phpmyadmin> in your browser.
2. Click **Import** in the top menu.
3. Click **Choose File** and select `tasktrack_db`'s setup file:
   `C:\xampp\htdocs\tasktrack\database.sql`
4. Click **Go** at the bottom of the page.

You should see a success message. Click **Databases** in the left sidebar and
confirm that **tasktrack_db** exists with the core tables
(`users`, `categories`, `tasks`) plus the feature tables
(`task_subtasks`, `focus_sessions`, `xp_transactions`, `user_achievements`,
`task_deadline_history`).

> Alternative (command line): open a terminal and run
> `C:\xampp\mysql\bin\mysql -u root < database.sql`

#### Already have an older ProcrastiNot install?

Do **not** re-import `database.sql` (that would erase your tasks). Instead, run
the additive migrations once — they keep all your data and only add the new
columns and tables:

```powershell
C:\xampp\mysql\bin\mysql -u root tasktrack_db < migrations\2026-10-19-smart-upgrade.sql
C:\xampp\mysql\bin\mysql -u root tasktrack_db < migrations\2026-10-19-account-fields.sql
```

(The second one adds the optional `course` and `year_level` profile fields used
by the My Account page.) You can also import each file through
phpMyAdmin → **Import** while `tasktrack_db` is selected.

### 4. Check the configuration (only if needed)

Open `config/config.php`. The defaults match a stock XAMPP:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'tasktrack_db');
define('DB_USER', 'root');
define('DB_PASS', '');   // empty by default
```

Change these only if your MySQL username/password differ.

### 5. Open ProcrastiNot

Visit **<http://localhost/tasktrack/>** — you will be sent to the login page.

---

## Create the first student account

1. On the login page, click **Create an account**.
2. Fill in your name, email address, and a password (at least 8 characters),
   confirm it, and click **Create account**.
3. You are signed in automatically and land on your dashboard with a friendly
   empty state — create your first task!

**Or try the demo account** (included by the optional sample data in
`database.sql`):

| Field    | Value                |
|----------|----------------------|
| Email    | `student@example.com`|
| Password | `password123`        |

You can delete the demo account and its tasks from the app, or skip the sample
data entirely by removing the clearly labeled **OPTIONAL SAMPLE DATA** section
at the bottom of `database.sql` before importing.

---

## How to use ProcrastiNot

- **Dashboard** — ordered so the answer to "what should I do next?" is always
  first: your greeting with a quick **Add task** button, a compact **Daily
  Focus** panel with a progress bar, two primary statistics (To Do, Completed)
  with a "Show all" toggle for the rest, a single **recommended** task with its
  ranking reason and a "Start 5 minutes" action, upcoming deadlines, two compact
  charts, and collapsed **Productivity trend** and **Momentum & achievements**
  sections you can open when you want them.
- **Tasks** — create tasks with title, description, notes, subject/category,
  importance, status, due date, estimated time, and a "My Day" flag. Switch
  between the All Tasks, My Day, Upcoming, Completed, and Overdue views. Search
  by title and filter by status, priority, subject, and deadline. Sort by
  **Smart (recommended)** to follow the priority engine. Click a title to see
  the full details, the ranking reason, any steps, and a "Just 5 Minutes"
  button.
- **Focus (Just 5 Minutes)** — start a five-minute timer on a task, pause,
  continue, or add five more minutes, add a short note, and break the task into
  small steps. The timer records how long you focused but **never** changes the
  task status for you.
- **Comeback Mode** — a supportive list of everything overdue, ranked by
  priority, each with one realistic next action. Adjust a deadline only when you
  confirm it; the original deadline is kept for honest reporting.
- **Calendar** — a monthly grid of your deadlines. Click a day to see what is
  due. On phones, a clean list replaces the grid.
- **Progress** (reports) — your **momentum and achievements** (level, XP,
  weekly goal, streaks, focus time and badge grid), plus completion totals and
  rate, **on-time completion rate**, pending vs. completed, tasks per week, a
  breakdown by subject, **focus minutes by weekday** (with your most productive
  day), an **overdue trend**, and optional **XP over time**.
- **My Account** — edit your profile (name, email, optional course and year
  level, preferred greeting), change your password with current-password
  verification, open appearance/study preferences, see a personal productivity
  summary, and log out.
- **Settings** — set your daily study time and weekly goal, switch the optional
  XP system on or off, and toggle the theme. (Profile details and your password
  now live on **My Account**.)

---

## How the Smart Priority Engine works

There is no AI model and no cloud service — the engine is plain, readable PHP
(`includes/priority.php`). Each unfinished task gets a **0–100 score** from four
weighted factors:

| Factor            | Weight | Meaning |
|-------------------|:------:|---------|
| Deadline urgency  | 35%    | How close the due date is |
| Importance        | 30%    | The task's priority you set (low / medium / high) |
| Overdue risk      | 20%    | Whether the remaining effort fits in the time left |
| Effort / planning | 15%    | A nudge for bigger tasks so they start earlier |

```
score = 100 × (0.35 × urgency + 0.30 × importance + 0.20 × risk + 0.15 × effort)
```

Every factor is normalised to 0–1 first, and a task that is already overdue is
**not** penalised twice. The score maps to a label (Critical / High / Medium /
Low) and is shown with a short sentence explaining the ranking. Completed tasks
are excluded. Changing a task's importance or a deadline recalculates the score
immediately, and **ProcrastiNot never changes a task's status because it is
overdue** — it only tells you where to look.

**XP rules (optional):** completing a task +10, finishing before the deadline
+5, a 5-minute-plus focus session +3 (1–4 minutes +1). Level = `XP ÷ 100 + 1`.
Every award is written once to a ledger with a unique key, so toggling a task
completed and back earns nothing extra, and a missed deadline never removes XP.
You can switch the whole system off in Settings.

---

## Folder tour

```
tasktrack/
├── index.php            Entry point (sends you to login or dashboard)
├── login.php            Login page
├── register.php         Student registration
├── logout.php           Destroys the session securely
├── dashboard.php        Personal dashboard with live stats
├── tasks.php            Task list, create, edit, detail, delete
├── focus.php            "Just 5 Minutes" focus timer and steps
├── comeback.php         Comeback Mode for overdue work
├── calendar.php         Monthly deadline calendar
├── reports.php          Progress page: momentum, achievements and analytics
├── account.php          My Account: profile, password, summary, preferences
├── settings.php         Study rhythm and motivation preferences
├── database.sql         Database setup + optional sample data
├── migrations/
│   ├── 2026-10-19-smart-upgrade.sql   Additive upgrade for existing installs
│   └── 2026-10-19-account-fields.sql  Adds optional course/year_level fields
├── config/
│   ├── config.php       Database credentials and error settings
│   └── database.php     Shared PDO (prepared statements) connection
├── includes/
│   ├── auth.php         Sessions and login protection
│   ├── functions.php    Escaping, CSRF, flashes, validation
│   ├── tasks.php        Task database queries (all owner-scoped)
│   ├── priority.php     Smart Priority Engine (rule-based scoring)
│   ├── focus.php        Subtasks and focus sessions
│   ├── comeback.php     Comeback Mode queries and rescheduling
│   ├── gamification.php Optional XP, levels, streaks, badges
│   ├── analytics.php    Report metrics (on-time, trends, focus rhythm)
│   ├── layout.php       Navigation structure (sidebar, bottom nav, account menu)
│   ├── header.php       Page header, sidebar and topbar
│   └── footer.php       Account popover, bottom nav and scripts
├── assets/
│   ├── css/style.css    The Playful Glassmorphism theme (light + dark)
│   ├── js/              Page behaviour (toasts, charts, timer, dialogs…)
│   └── images/
└── logs/                Error log (written only when something fails)
```

---

## Notes for students and reviewers

- **Security**: passwords are stored with `password_hash()` (bcrypt) and
  checked with `password_verify()`; every database query uses PDO prepared
  statements; every state-changing form carries a CSRF token; all output is
  HTML-escaped; every task query is scoped to the signed-in student; changing
  your password on the My Account page requires your current password.
- **Overdue is never stored** — a task is overdue when
  `due_date < today AND status <> 'completed'`, so it can never go stale.
- **Completion dates are recorded** in `completed_at` the moment a task is
  marked done, so weekly counts and the on-time rate are based on when you
  actually finished. If you reschedule a deadline, the first deadline is kept in
  `original_due_date` so reports and the on-time rate stay honest.
- **Motivation, not pressure**: Comeback Mode never shames you, deadlines only
  move when you confirm them, and the optional XP system docks no points for
  lateness.
- Charts (Chart.js), icons (Lucide), and fonts (Plus Jakarta Sans) load from
  public CDNs when online. Offline, everything still works — charts fall back
  to accessible data tables, and system fonts are used.

---

## Troubleshooting

### "Database connection failed" page
1. Make sure **MySQL is running** in the XAMPP Control Panel.
2. Confirm you imported `database.sql` and the database is named
   `tasktrack_db` (phpMyAdmin → Databases).
3. Check the credentials in `config/config.php` match your MySQL user.

### MySQL refuses to start / "Port 3306 in use"
Another program (often another MySQL/MariaDB service) is using port 3306:
- Open the XAMPP Control Panel → **Config** → **my.ini** (MySQL) and confirm
  `port=3306`, or stop the other MySQL service:
  open Windows **Services** (`services.msc`) and stop any *MySQL* service that
  is not XAMPP's, or run `net stop mysql` in an administrator terminal.
- Then start MySQL again from the XAMPP Control Panel.

### phpMyAdmin import errors
- The import must go through the **Import** tab of phpMyAdmin with the whole
  `database.sql` file (do not paste partial SQL).
- If you see "MySQL said: Documentation#2006 - MySQL server has gone away",
  raise the limits in `C:\xampp\php\php.ini`:
  `upload_max_filesize = 64M`, `post_max_size = 64M`, then restart Apache.
- "Access denied" inside phpMyAdmin means the browser session expired —
  log in to phpMyAdmin again.

### Blank white page
PHP errors are hidden on purpose. Two ways to see what is wrong:
1. Open `logs/php-error.log` inside the project folder — real errors are
   written there.
2. Temporarily show errors by setting `display_errors` to `'1'` in
   `config/config.php` while debugging (set it back afterwards).

### "Access denied for user 'root'@'localhost'"
Your MySQL root password is not empty. Put it in `config/config.php`:

```php
define('DB_PASS', 'your-password');
```

### Session warnings ("headers already sent")
A stray space or BOM before `<?php` in a file, or output before
`session_start()`. Every ProcrastiNot file starts cleanly with `<?php`; if you
edited one, remove any blank lines or text above the first `<?php` tag.

### "This page cannot find tasktrack" (404)
The folder is not exactly named `tasktrack` inside `htdocs`, or Apache was
not restarted after copying. The URL must be `http://localhost/tasktrack/`.

### Fonts, icons, or charts missing
You are offline — that is expected and safe. The app keeps working with
system fonts, text labels, and data tables instead of charts.

---

## Uninstall

1. Delete the `tasktrack` folder from `htdocs`.
2. (Optional) In phpMyAdmin, drop the `tasktrack_db` database.
