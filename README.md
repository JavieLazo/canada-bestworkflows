BestWorkflows Employee Management System

The BestWorkflows Employee Management System is a Laravel-based web application for managing daily employee operations in one place.
It supports employee records, electronic daily time records, shift schedules, leave requests, project tracking, payroll, user access, and audit logs. The system is built for BestWorkflows and is designed to run on cPanel shared hosting.

What The System Does
The system helps the company manage:
- Employee 201 records
- eDTR and attendance logs
- Shift rosters
- Leave and business travel requests
- Project assignments and task reviews
- Payroll runs and payslips
- Records and reference lists
- User accounts, roles, and permissions
- Audit trail and activity history
- Employee self-service portal

Main Modules
Employee Records	Manages employee profiles, hiring details, positions, rates, and status
eDTR & Attendance	Handles time-in, time-out, and attendance logs
Shift Roster	Manages employee shift schedules and grace periods
Leave Management	Handles leave and business travel requests
Project Management	Tracks projects, tasks, and reviews
Payroll	Manages payroll runs, payslips, and payroll review
Records Management	Stores system reference lists and options
User Management	Manages accounts, roles, and module access
Audit Trail	Shows a read-only history of system activity
My Portal	Employee self-service area
My Tasks	Shows tasks assigned to the employee
My Profile	Allows users to manage their own profile details

Important security rules include:
- Disabled accounts are automatically signed out on their next request.
- Navigation menus are not used as security enforcement.
- Permissions are checked by the backend.
- Sensitive access, such as viewing all employee data, must be granted per module.
- Employee, payroll, and attendance data are carefully scoped to prevent unauthorized access.

Payroll And Time Handling
Payroll calculations follow the original TypeScript payroll engine to make sure the Laravel version produces the same results.

Money and time are handled carefully:
- All money values use a consistent rounding method.
- Dates and times are stored in UTC.
- Manila time is converted only through the system’s dedicated time-handling logic.
- Attendance records use millisecond-level timestamps for accurate punch pairing.

Production Setup

Production setup uses a separate seeder that creates only system configuration, such as roles, permissions, reference lists, holidays, formulas, and shift types.
It does not create demo employees or demo passwords.
The first admin account is created with a secure command. The password is generated automatically and shown only once.

Scheduled Tasks
A single cron job runs the system scheduler every minute. This powers background tasks such as:
- Reminder checks
- Cache cleanup
- Session cleanup
Reminders run every 30 minutes between 6:00 AM and 8:00 PM Manila time.

Deployment
Releases are handled manually because the production host uses cPanel shared hosting and does not have SSH access.

The deployment process creates two zip files:
- One for the Laravel application
- One for public assets
These are uploaded and extracted through cPanel File Manager. Migrations are run through a temporary cron job.
Before every release, the full test suite should be run on the same PHP version used by the production host.