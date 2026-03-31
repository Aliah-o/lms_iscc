# ISCC LMS — Ilocos Sur Community College Learning Management System

A modern, responsive, web-based Learning Management System (LMS) built for Ilocos Sur Community College. College-level only (1st–4th Year), supporting 6 official BSIT/BSM/etc. programs with unlimited sections per program+year.

---

## Tech Stack

- **Backend:** PHP 8+
- **Database:** MySQL / MariaDB
- **Frontend:** Bootstrap 5, Vanilla JS, FontAwesome 6
- **Security:** PDO prepared statements, `password_hash`/`password_verify`, CSRF tokens, session hardening

---

## Quick Start (XAMPP / Laragon)

### 1. Clone or Copy Files

Copy the `iscc-lms` folder into your web root:

- **XAMPP:** `C:\xampp\htdocs\iscc-lms\`
- **Laragon:** `C:\laragon\www\iscc-lms\`

### 2. Start Services

- Start **Apache** and **MySQL** from XAMPP Control Panel (or Laragon).

### 3. Run All Installers (in order)

You must run each installer **one by one** in your browser, in the exact order below. Log in as **superadmin** (after Step 1) before running Steps 2–6.

| Step | URL | What It Does |
|------|-----|--------------|
| **1** | `http://localhost/iscc-lms/install.php` | Creates the `iscc_lms` database, core tables (users, sections, classes, lessons, quizzes, badges, etc.), and seeds sample data. Click **"Install Now"**. |
| **2** | `http://localhost/iscc-lms/forum_install.php` | Creates forum tables: `forum_categories`, `forum_threads`, `forum_posts`, `forum_thread_likes`, `forum_post_reports`. Seeds default categories. |
| **3** | `http://localhost/iscc-lms/forum_migrate_v2.php` | Creates `forum_notifications` and `forum_attachments` tables. Creates `uploads/forums/` directory. |
| **4** | `http://localhost/iscc-lms/forum_migrate_v3.php` | Adds `forum_karma` column to `users`, `anon_display_name` to `forum_threads` and `forum_posts`. Backfills karma scores. |
| **5** | `http://localhost/iscc-lms/kahoot-install.php` | Creates Live Quiz (Kahoot) tables: `kahoot_games`, `kahoot_questions`, `kahoot_sessions`, `kahoot_responses`, `kahoot_scores`. |
| **6** | `http://localhost/iscc-lms/ticket-install.php` | Creates Support Ticket tables: `support_tickets`, `ticket_replies`. |

> **Important:** Steps 2–6 require you to be logged in as **superadmin** (`superadmin` / `password123`).
> Each step shows a success/error page — verify all steps completed before moving to the next.

### 4. Login

Go to `http://localhost/iscc-lms/login.php`

---

## Sample Accounts

All passwords: **`password123`**

| Role | Username(s) |
|------|-------------|
| **Superadmin** | `superadmin` |
| **Staff** | `staff1`, `staff2` |
| **Instructors** | `instructor1` – `instructor6` |
| **Students** | `student1` – `student60` |

---

## Roles & Permissions

### Superadmin
- Manage system settings (name, logo, theme, maintenance mode)
- Manage all users (create/edit/deactivate)
- View full audit logs + platform overview
- Manage badge definitions and rules
- View all courses, sections, classes, student progress

### Staff (Academic Coordinator)
- Create/edit/archive Students and Instructors
- Manage programs (restricted to 6 official programs)
- Manage year levels (1–4)
- Create/manage sections per program+year (unlimited)
- Assign students to program+year+section
- Assign instructors to class loads
- Enroll students in instructor classes
- View reports (read-only, cannot edit content/grades)

### Instructor/Instructor
- Create/manage Lessons & Discussions for assigned classes
- Build and manage Knowledge Trees (Beginner → Intermediate → Advanced)
- Create Quizzes (Multiple Choice & Word Scramble)
- View student progress and growth tracker
- View badges earned by class students

### Student
- Access enrolled classes only
- View lessons and discussions
- Navigate Knowledge Tree (unlock levels sequentially)
- Take quizzes and view results
- Earn badges automatically
- View Personal Growth Tracker

---

## Programs (Strict — 6 Only)

| Code | Program |
|------|---------|
| BSAB | Bachelor of Science in Agribusiness |
| BSIT | Bachelor of Science in Information Technology |
| BSCM | Bachelor of Science in Cooperative Management |
| BSHM | Bachelor of Science in Hospitality Management |
| BSTM | Bachelor of Science in Tourism Management |
| BSM  | Bachelor of Science in Midwifery |

---

## Core Modules

| Module | Page | Roles |
|--------|------|-------|
| Auth & Login | `/login.php` | All |
| Dashboard | `/dashboard.php` | All (role-specific) |
| Programs | `/programs.php` | Superadmin, Staff |
| Sections | `/sections.php` | Superadmin, Staff |
| Assignments | `/assignments.php` | Staff |
| User Management | `/users.php` | Superadmin, Staff |
| My Classes | `/classes.php` | Instructor, Student |
| Lessons | `/lessons.php` | Instructor, Student |
| Knowledge Tree | `/knowledge-tree.php` | Instructor, Student |
| Quizzes | `/quizzes.php` | Instructor, Student |
| Growth Tracker | `/growth-tracker.php` | Instructor, Student |
| Badges | `/badges.php` | Superadmin, Instructor, Student |
| Grades | `/grades.php` | Instructor, Student, Superadmin |
| Activity Submissions | `/activity-submissions.php` | Instructor, Student, Superadmin |
| Settings | `/settings.php` | Superadmin |
| Audit Logs | `/audit-logs.php` | Superadmin, Staff |
| Installer | `/install.php` | Public (one-time) |

---

## Knowledge Tree

- Each instructor class has its own tree
- Levels: **Beginner → Intermediate → Advanced**
- Students must complete all nodes in a level to unlock the next
- Each node can have lesson content + optional linked quiz
- Instructors can create, edit, reorder, and delete nodes
- Students see locked/unlocked/completed states + progress count

---

## Quizzes & Word Scramble

### Multiple Choice
- 4 options (A/B/C/D), single correct answer
- Automatic grading

### Word Scramble
- Instructor inputs a prompt and correct answer word/phrase
- Student sees blank boxes + scrambled letter tiles
- Click tile → fills next empty box
- Click filled box → returns tile (undo)
- Randomized letter order every attempt
- Touch-friendly for mobile

---

## Badge System

| Badge | Rule |
|-------|------|
| First Quiz | Complete any quiz |
| Beginner Complete | Complete all Beginner-level nodes in a class |
| Perfect Score | Score 100% on any quiz |
| Quick Learner | Complete 5 knowledge nodes |
| Growth 10% | Improve monthly avg by 10%+ |
| Dedicated Learner | Complete 10 knowledge nodes |

Badges are **auto-awarded** when conditions are met. Superadmin can create/edit badge definitions.

---

## Growth Tracker

- **Student view:** Monthly average score, improvement %, motivational message, 6-month bar chart
- **Instructor view:** Select a student from their class → view same metrics
- **Calculation:**
  - Monthly average = mean of quiz scores in month
  - Improvement % = `(this_month_avg - last_month_avg) / last_month_avg × 100`
  - If no previous data → "No previous data"

---

## File Structure

```
iscc-lms/
├── assets/
│   └── css/
│       └── style.css           # All custom styles
├── config/
│   ├── constants.php           # Roles, programs, year levels, etc.
│   └── database.php            # DB connection, session, CSRF helpers
├── helpers/
│   └── functions.php           # Auth, redirect, flash, audit, growth
├── views/
│   ├── errors/
│   │   └── 403.php             # Access denied page
│   └── layouts/
│       ├── header.php          # Sidebar + topbar + navigation
│       └── footer.php          # Toast notifications + scripts
├── uploads/
│   ├── forums/                 # Forum attachments
│   ├── kahoot/                 # Kahoot game images
│   ├── lessons/                # Lesson attachments
│   ├── submissions/            # Activity submission files (protected)
│   │   └── .htaccess           # Denies direct access
│   └── tickets/                # Ticket attachments
├── activity-submissions.php    # Student submission + teacher grading
├── assignments.php             # Staff: student/instructor assignments
├── audit-logs.php              # Superadmin/Staff: activity logs
├── badges.php                  # Badge management + student badges
├── classes.php                 # Instructor/Student: class listing
├── dashboard.php               # Role-specific dashboards
├── growth-tracker.php          # Growth metrics + charts
├── index.php                   # Redirect to dashboard/login
├── install.php                 # Auto-installer + seeder
├── knowledge-tree.php          # Skill tree module
├── lessons.php                 # Lesson CRUD + viewer
├── login.php                   # Authentication
├── logout.php                  # Session destroy
├── programs.php                # Program overview
├── quizzes.php                 # Quiz CRUD + take + results
├── sections.php                # Section management
├── settings.php                # System settings (Superadmin)
├── submission-download.php     # Secure file download handler
├── users.php                   # User management
├── installed.lock              # Created after install (prevents re-run)
└── README.md                   # This file
```

---

## Re-installing

To reinstall from scratch:

1. Delete `installed.lock` from the project root
2. Optionally drop the `iscc_lms` database
3. Visit `http://localhost/iscc-lms/install.php`

---

## Security Notes

- All DB queries use **PDO prepared statements**
- Passwords hashed with `password_hash()` (bcrypt)
- CSRF tokens on all POST forms
- Session hardening (`cookie_httponly`, `use_strict_mode`, `samesite=Lax`)
- Role-based access enforced **server-side** on every page
- Input sanitized with `htmlspecialchars()` on output

---

## License

Built for Ilocos Sur Community College academic use.

---

## Changelog

### March 3, 2026 (v2) — Final Grade Computation + Per-Period Attendance

**Formula:** `Final Grade = (Midterm Grade + Tentative Final Grade) ÷ 2`

**Database Changes (via `migrate.php` step #15):**
- `attendance` table — added `grading_period ENUM('midterm','final') DEFAULT 'midterm'` column
- `attendance` unique key updated to `(class_id, student_id, attendance_date, grading_period)` — allows same-day records for different periods

**Modified Files:**
- `helpers/functions.php` — `syncAttendanceToGrades()` rewritten:
  - Now computes attendance rate **per grading period** separately
  - Filters by `grading_period` column instead of syncing same score to both periods
  - Graceful fallback if column doesn't exist yet
- `attendance.php` — Per-period attendance tracking:
  - Added Midterm/Final toggle (btn-group) alongside nav pills
  - Period selector state preserved across all views (mark, records, date picker)
  - All save/mark-all forms include `att_period` hidden field
  - Data loading queries filter by selected period
  - CREATE TABLE fallback updated with `grading_period` column and new unique key
- `grades.php` — Final Grade computation (both views):
  - **Data loading:** Loads grades for BOTH periods into `$allPeriodGrades`; pre-computes `$periodWA` for all students
  - **Attendance auto-compute:** Now filters by `grading_period`, computed per-period
  - **Student view:** New "Final Grade Summary" card showing Midterm WA + Tentative Final WA = Final Grade with pass/fail badges and color-coded formula display
  - **Teacher grade sheet:** 3 new read-only columns (Midterm WA, Tentative Final WA, Final Grade) with color-coded backgrounds
  - **JS `recalcGrades()`:** Computes active period WA from live inputs, other period WA from stored data (`otherPeriodGrades`), Final Grade = average of both — all update in real-time
  - Pass/fail status now based on Final Grade instead of single-period WA
- `migrate.php` — Added idempotent step #15 for `attendance.grading_period` column + unique key update

### March 3, 2026 — Submittable Activities + File Submission + Deadlines + Late Penalties

**New Files:**
- `/activity-submissions.php` — Student submission upload & teacher grading page
- `/submission-download.php` — Secure authorized file download handler
- `/uploads/submissions/.htaccess` — Blocks direct HTTP access to uploaded files

**Database Changes (via `migrate.php` steps #12–14):**
- `graded_activities` table — 11 new columns: `is_submittable`, `allow_late`, `allow_resubmit`, `open_date`, `due_date`, `close_date`, `late_penalty_type`, `late_penalty_amount`, `late_penalty_interval`, `late_penalty_max`, `description`
- `activity_submissions` table (new) — Tracks student submissions with file info, grading (raw score + late penalty + final score), feedback, version, and status
- `submission_history` table (new) — Archives previous submission versions on resubmit

**Modified Files:**
- `helpers/functions.php` — Added ~200 lines of submission helpers:
  - `getActivityStatus()` — Returns `not_open` / `open` / `overdue` / `closed`
  - `activityStatusBadge()` — Bootstrap badge HTML for activity status
  - `canStudentSubmit()` — Checks if student can submit (deadline + resubmit rules)
  - `computeLatePenalty()` — Calculates late penalty based on activity config
  - `computeFinalScore()` — Applies penalty to raw score
  - `getLatePolicySummary()` — Human-readable late policy text
  - `validateSubmissionFile()` — Server-side file validation (25 MB max, allowed types)
  - `storeSubmissionFile()` — Moves upload to `uploads/submissions/` with safe naming
  - `syncSubmissionToActivityScore()` — Syncs graded submission to grade sheet
  - Constants: `SUBMISSION_MAX_SIZE`, `SUBMISSION_ALLOWED_TYPES`, `SUBMISSION_ALLOWED_EXTS`
- `grades.php` — Updated:
  - Fixed form field name mismatch (`name="title"` → `name="act_title"`, etc.)
  - Create activity modal now `modal-lg` with submittable toggle, deadline pickers (open/due/close), late penalty config (type/amount/interval/cap), description field, JS show/hide
  - POST handler saves all new submission fields with date validation
  - Delete handler cleans up submission files + history on activity deletion
  - Activities tab shows submission badges, status pills, and "View/Submit" links
  - Student activity card shows description, due date, late policy, and submit button
- `migrate.php` — Added idempotent migration steps #12–14 for all new schema

**Feature Details:**

| Feature | Description |
|---------|-------------|
| Submittable Toggle | Teacher marks activity as submittable or score-only |
| File Upload | Students upload PDF, DOC, DOCX, PPT, PPTX, XLS, XLSX, TXT, JPG, PNG (max 25 MB) |
| Deadline System | Open date → Due date → Close date with 4 statuses (Not Open / Open / Overdue / Closed) |
| Late Penalty | Configurable per-activity: percentage or fixed points, per day or per hour, optional max cap |
| Resubmission | Optional version history — previous files archived in `submission_history` |
| Teacher Grading | Raw score + auto-computed late penalty → final score, with feedback field |
| Grade Sync | Graded submissions auto-sync to `graded_activity_scores` → component grade sheet |
| Secure Downloads | Files served through `submission-download.php` with role-based authorization + directory traversal protection |
| Stats Dashboard | Teacher sees submitted / graded / late / missing counts at a glance |
