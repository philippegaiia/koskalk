# Production Task Organization and Departments Design

## Goal

Extend the existing Production Bench task workflow so a maker can organize generated production tasks by optional departments and employees, manage those tasks from their production, and see work across productions in one task list.

This is an organizational aid for small production units. It is not a timesheet, workforce-monitoring system, capacity scheduler, or legal record of hours worked. A user may stop at generated task names and dates, add departments later, or assign employees only when the additional organization is useful.

## Relationship to the Existing Production Design

This specification extends [Production Planning and Execution Design](2026-08-04-production-planning-execution-design.md). It does not replace the existing production, task-set, working-calendar, stock-preparation, Flash, or execution decisions.

The current implementation already:

- generates production-specific tasks from a selected task set;
- snapshots task name, colour, duration, relative-day offset, and scheduled date;
- keeps automatic, custom-dated, and completed tasks distinct;
- recalculates unfinished automatic tasks when the production date moves;
- supports optional employee assignment and task completion/reopening on the production page.

The work described here reconciles that foundation with optional departments, a clearer employee setup, a cross-production task list, and the application's shared notification system.

## Product Principle: Optional Organizational Depth

The workflow must work at three levels without setup gates:

1. **Tasks only:** generated names, dates, and completion state.
2. **Departments:** optional responsibility grouping and filtering.
3. **Employees:** optional assignment of a person to a task.

A sole maker never has to create an employee or department. A two-person team may assign employees directly without departments. A larger workshop may use both.

Departments and employees never restrict who is allowed to perform work. There is no dependent employee dropdown, automatic assignment, staffing rule, or permission derived from department membership.

## Departments

Departments are user-created workspace records with:

- name;
- active/inactive state;
- timestamps and a public identifier.

Department names must be unique within a workspace after trimming and case normalization. No department is compulsory and no fixed industry enum is stored.

The department empty state offers one-click creation of these editable examples:

- Production;
- Finishing / Filling;
- Packing / Labelling;
- Quality.

The shortcuts are suggestions only. They are never created without confirmation. Planning, Purchasing, Cleaning, Sanitation, and Maintenance are not starter production-task departments:

- purchasing already has its own workflow;
- planning is not a production execution responsibility;
- end-of-day cleaning or equipment maintenance may cover several batches and therefore should not be duplicated as a task belonging to each production.

Materials / Warehouse may be created by a workspace that treats stock staging as a departmental responsibility, but it is not a default suggestion.

Departments have a dedicated Production Setup submenu and a compact index with create, edit, activate/deactivate, and delete actions. An unused department may be deleted. A department referenced by task types, employees, or generated tasks cannot be deleted and is deactivated instead so existing organization and history remain understandable.

## Employees

Employees remain lightweight workspace records rather than login accounts. An employee contains:

- first name;
- last name;
- optional title;
- active/inactive state;
- zero or more departments.

The employee-to-department relationship is many-to-many because people in small workshops commonly work across Production, Finishing, Packing, and Quality.

The employee title is descriptive only. It does not grant permissions, constrain assignment, determine pay, or affect planning calculations.

The Employees setup area becomes a dedicated index/table and create/edit form:

- table columns: name, title, departments, active state, actions;
- long department lists show a compact summary instead of increasing row height without limit;
- the form uses a clear searchable department selection;
- departments remain optional;
- inactive employees remain visible in setup and historical assignments.

Unused employees may be deleted. Employees already referenced by production tasks cannot be deleted and are deactivated instead so existing task organization remains legible.

## Department Defaults on Task Types

A task type may have one optional default department.

Examples:

- Make batch → Production;
- Fill containers → Finishing / Filling;
- Apply labels → Packing / Labelling;
- Release batch → Quality.

The default belongs to the task type, not the task set item. This keeps reusable task sets simple and avoids a second department override during setup.

When production tasks are generated, each task copies the task type's current default department. Later edits to the task type do not rewrite existing production tasks. The department on a generated task remains editable until the production becomes terminal.

An inactive department remains displayed on existing task types and generated tasks but is not offered for new defaults or new assignments.

## Generated Production Tasks

Each generated production task continues to store its existing snapshots and scheduling state. It gains one optional department reference in addition to the existing optional employee reference.

The department and employee are independent:

- a task may have neither;
- a task may have only a department;
- a task may have only an employee;
- a task may have both;
- the employee does not need to belong to the task's department.

Task duration remains an estimated number of minutes used for setup defaults, rough workload display, and Flash simulation totals. Koskalk does not record task start time, stop time, actual time, billable time, or employee productivity.

## Production Page: Primary Task Workspace

The production detail page is the primary place to manage the tasks belonging to that production. Its task section shows:

- task name and display colour;
- scheduled date;
- Automatic date or Custom date state;
- optional department;
- optional employee;
- estimated duration when present;
- completion state.

The user may:

- mark a task complete;
- reopen it while the production lifecycle permits;
- assign, change, or clear its department;
- assign, change, or clear its employee;
- change the date of a non-anchor unfinished task;
- reset a custom task date to its automatic date;
- change the production date through the production-level action.

Department and employee assignment remain editable during an In production run. They become read-only when the production is Completed, Cancelled, or Aborted.

Date behavior preserves the approved scheduling rules:

- changing the production date moves unfinished automatic tasks from their stored offsets;
- completed tasks never move;
- custom-dated tasks never move;
- the production-day anchor always equals the production date;
- the anchor never becomes independently custom-dated;
- task dates cannot be changed after production starts.

Assignment and date changes use separate domain actions. Employee assignment must no longer be coupled to the rescheduling action, because assignment may remain useful during production while date mutation is already locked.

## Cross-Production Task List

Production gains a first-class Tasks view using the same generated task records. It is an operational overview, not a second task system.

The default view emphasizes current work and supports:

- Today;
- Upcoming;
- Overdue;
- Completed;
- custom date range;
- search by task, product, production identifier, department, or employee;
- filters for completion state, department, employee, and product.

Each row displays:

- scheduled date;
- task;
- production and product;
- department;
- employee;
- completion state;
- a link to the production.

The list provides quick completion/reopening and quick department/employee assignment. Date changes and production-date changes remain on the production page so the user sees the complete batch context.

The existing production calendar continues to show production and task events. The task list does not introduce time-of-day scheduling, workload capacity, or drag-and-drop mutation.

## Notification Contract

Production Bench must use the authenticated application's existing shared notification component.

Transient mutation outcomes use the shared toast:

- created;
- saved;
- updated;
- assigned;
- completed or reopened;
- deleted or deactivated;
- stock prepared or released;
- productions generated outside Flash.

Success toasts auto-dismiss using the existing four-second behavior. Error toasts remain until dismissed.

Redirect flows set the normal session status consumed by the app-shell notification. Same-page Livewire mutations dispatch the existing app-notification event through the shared notification concern.

Production pages must not also render a permanent success banner for the same mutation. The implementation audit includes current permanent or duplicated confirmations in:

- production creation;
- Flash generation;
- batch-size and task-set indexes;
- Production Setup mutations;
- production task operations;
- opening-stock creation;
- stock preparation and release.

Flash generation keeps its deliberate, automatically disappearing celebration as the success feedback for that action instead of adding a second toast. It must not be accompanied by a persistent success message.

Persistent inline messages are reserved for information that remains relevant while the page is open:

- read-only or inactive entitlement;
- validation errors next to their fields;
- validation and action errors requiring correction;
- shortages and reservation conflicts;
- non-working-date warnings;
- missing data or price warnings;
- durable production, receipt, lot, and stock statuses;
- loading and upload progress.

## Data Relationships

The implementation extends the existing model with:

- a workspace having many departments;
- an employee belonging to many departments;
- a department having many employees;
- a task type having one optional default department;
- a generated production task having one optional department;
- an employee retaining its existing optional production-task assignments.

All references are workspace-safe. Cross-workspace department, employee, task-type, and production-task references are rejected at the action boundary.

Existing employees, task types, and generated production tasks migrate safely with null title and department values. No legacy backfill is required.

## Integrity and Lifecycle Rules

- Departments, employees, and assignments are optional.
- Department membership never restricts employee assignment.
- Only active departments are offered for new defaults or task assignment.
- Only active employees are offered for new task assignment.
- Existing inactive department and employee references remain readable.
- Task-type department changes do not rewrite generated tasks.
- Terminal productions do not accept task organization or completion mutations.
- Completed and custom-dated tasks retain their existing scheduling protection.
- Cancelled or read-only Production Bench blocks all mutations.
- Duplicate Livewire submission must not create duplicate departments or employee memberships.
- Used organizational records are deactivated rather than destructively removed.

## Explicit Exclusions

This design does not add:

- timesheets, timers, or actual labor duration;
- billable hours or payroll;
- employee accounts, authentication, roles, or permissions;
- attendance, shifts, leave, or availability;
- productivity, utilization, or performance metrics;
- production-line or equipment capacity;
- task dependencies;
- automatic employee assignment;
- department-restricted employee dropdowns;
- general cleaning, maintenance, facilities, or purchasing task management.

## Delivery Slices

The implementation is delivered in three reviewable slices:

1. departments, employee titles/memberships, task-type defaults, migrations, setup pages, and shared notification cleanup;
2. generated-task department snapshots plus complete production-page task assignment, date, reset, completion, and reopening controls;
3. the cross-production task list, filters, quick operations, translations, responsive behavior, accessibility, build, and complete regression verification.

## Review Scenario

The review demonstrates:

- a workspace that uses generated tasks without creating departments or employees;
- user-created Production, Packing / Labelling, and Quality departments;
- one employee with a title and membership in two departments;
- a task type with an optional default department;
- a planned production whose generated tasks inherit department defaults;
- department and employee edits on the production page without a restrictive dropdown;
- automatic date movement after changing the production date;
- one custom date remaining unchanged;
- completed tasks remaining unchanged;
- completion and reopening from the production page;
- the cross-production Today, Upcoming, Overdue, and Completed task views;
- filtering by department and employee;
- success mutations appearing as shared temporary toasts rather than permanent banners;
- a sole-maker path with no department or employee setup.
