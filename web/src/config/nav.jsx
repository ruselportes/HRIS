export const GROUP_ORDER = ['Overview', 'Workforce', 'Payroll', 'Sites', 'Insight', 'System']

export const ROLE_KEYS = ['hr', 'foreman', 'engineer', 'admin', 'exec']

export const ROLE_LABELS = {
  hr: 'HR Personnel',
  foreman: 'Site Foreman',
  engineer: 'Site Engineer / CM',
  admin: 'System Administrator',
  exec: 'Executive',
  executive: 'Executive',
}

export const NAV = [
  { key: 'dash', label: 'Dashboard', icon: 'dash', group: 'Overview', note: 'Role-specific landing', access: { hr: 'full', foreman: 'full', engineer: 'full', admin: 'full', exec: 'full' } },
  { key: 'employees', label: 'Employees', icon: 'people', group: 'Workforce', note: '201 files, contracts, rates', access: { hr: 'full', foreman: 'none', engineer: 'view', admin: 'full', exec: 'view' } },
  { key: 'attendance', label: 'Attendance & DTR', icon: 'clock', group: 'Workforce', note: 'Daily time records', access: { hr: 'full', foreman: 'full', engineer: 'full', admin: 'view', exec: 'view' } },
  { key: 'rollcall', label: 'Roll Call', icon: 'crew', group: 'Workforce', note: 'Crew capture, offline-first', access: { hr: 'none', foreman: 'full', engineer: 'view', admin: 'none', exec: 'none' } },
  { key: 'leave', label: 'Leave Requests', icon: 'leave', group: 'Workforce', note: 'File, endorse, approve', access: { hr: 'full', foreman: 'full', engineer: 'full', admin: 'none', exec: 'view' } },
  { key: 'overrides', label: 'Overrides & Audit', icon: 'flag', group: 'Workforce', note: 'Manual time edits for review', access: { hr: 'full', foreman: 'view', engineer: 'view', admin: 'view', exec: 'none' } },
  { key: 'payroll', label: 'Payroll Runs', icon: 'card', group: 'Payroll', note: 'Cut-off, computation, release', access: { hr: 'full', foreman: 'none', engineer: 'none', admin: 'none', exec: 'view' } },
  { key: 'gov', label: "Gov't Remittances", icon: 'doc', group: 'Payroll', note: 'SSS, PhilHealth, Pag-IBIG, BIR', access: { hr: 'full', foreman: 'none', engineer: 'none', admin: 'none', exec: 'view' } },
  { key: 'sites', label: 'Project Sites', icon: 'site', group: 'Sites', note: '11 sites, Cebu & Bohol', access: { hr: 'view', foreman: 'none', engineer: 'full', admin: 'full', exec: 'view' } },
  { key: 'manpower', label: 'Manpower Allocation', icon: 'crew', group: 'Sites', note: 'Assign crews to sites', access: { hr: 'view', foreman: 'none', engineer: 'full', admin: 'none', exec: 'view' } },
  { key: 'compliance', label: 'Compliance & Docs', icon: 'shield', group: 'Sites', note: 'Clearances, DOLE, safety certs', access: { hr: 'full', foreman: 'view', engineer: 'full', admin: 'none', exec: 'view' } },
  { key: 'reports', label: 'Reports & Analytics', icon: 'chart', group: 'Insight', note: 'Cost, headcount, absence', access: { hr: 'full', foreman: 'none', engineer: 'view', admin: 'none', exec: 'full' } },
  { key: 'announce', label: 'Announcements', icon: 'megaphone', group: 'Insight', note: 'Company-wide notices', access: { hr: 'full', foreman: 'view', engineer: 'view', admin: 'full', exec: 'view' } },
  { key: 'users', label: 'Users & Roles', icon: 'users', group: 'System', note: 'Provisioning, RBAC', access: { hr: 'none', foreman: 'none', engineer: 'none', admin: 'full', exec: 'none' } },
  { key: 'synchealth', label: 'Device & Sync Health', icon: 'sync', group: 'System', note: 'Biometrics, field devices', access: { hr: 'view', foreman: 'none', engineer: 'none', admin: 'full', exec: 'none' } },
  { key: 'settings', label: 'System Settings', icon: 'gear', group: 'System', note: 'Cut-offs, holidays, integrations', access: { hr: 'none', foreman: 'none', engineer: 'none', admin: 'full', exec: 'none' } },
]

const tone = {
  present: { bg: '#E6F1EA', fg: '#1F5334' },
  late: { bg: '#FBF0E2', fg: '#8A5211' },
  absent: { bg: '#F7E8E5', fg: '#75261C' },
  pending: { bg: '#FCECE0', fg: '#96420E' },
  flagged: { bg: '#EFE9F4', fg: '#4A3260' },
  neutral: { bg: '#e7e7ea', fg: '#2b2b2d' },
}

const statTone = { ink: '#1d1f20', amber: '#8A5211', orange: '#96420E', red: '#75261C' }

// Prototype "02 — Post-login shell" content, keyed by role slug (exec = executive).
export const ROLES = {
  hr: {
    label: 'HR Personnel',
    user: { name: 'Reyes, Marilou A.', detail: 'HR Office · Head Office', role: 'HR', initials: 'MR', short: 'M. Reyes' },
    active: 'attendance',
    badges: { attendance: '12', leave: '5', overrides: '3' },
    page: {
      title: 'Attendance & DTR',
      subtitle: 'All sites · cut-off 21 Aug – 05 Sep 2026',
      searchHint: 'Search employee or ID',
      stats: [
        { label: 'Active employees', value: '201', note: 'across 11 sites', tone: 'ink' },
        { label: 'Present today', value: '184', note: '91.5%', tone: 'ink' },
        { label: 'Late', value: '9', note: 'needs no action', tone: 'amber' },
        { label: 'Unsynced records', value: '12', note: 'from 3 field devices', tone: 'orange' },
      ],
      panelTitle: 'Records needing HR action',
      panelNote: 'before payroll lock on 06 Sep, 12:00',
      panelAction: 'Open full DTR',
      tableHead: ['Employee', 'Site', 'Issue', 'Status'],
      tableRows: [
        { a: 'Bacus, Elmer P.', aSub: 'ADC-0509 · Foreman', b: 'Site 04', c: 'Time-out set manually', badge: 'Override Flagged', tone: 'flagged' },
        { a: 'Abainza, Jomar T.', aSub: 'ADC-0455 · Site Engineer', b: 'Site 04', c: 'No time-out recorded', badge: 'Pending Sync', tone: 'pending' },
        { a: 'Villacruz, Anna Lyn', aSub: 'ADC-0611 · Admin', b: 'Head Office', c: 'No record for 04 Sep', badge: 'Absent', tone: 'absent' },
        { a: 'Dela Cruz, Ronel B.', aSub: 'ADC-0387 · Foreman', b: 'Site 07', c: 'In at 08:41', badge: 'Late', tone: 'late' },
      ],
      primaryAction: 'Approve DTR batch',
      secondaryAction: 'Export for payroll',
      actionNote: 'Approving writes to the audit log under your name.',
      scope: ['All 201 employee files, contracts and rates', 'Payroll runs and government remittances', 'Override review for every site', 'Cannot assign crews or change system settings'],
    },
  },
  foreman: {
    label: 'Site Foreman',
    user: { name: 'Dela Cruz, Ronel B.', detail: 'Site 07 · Structural crew B', role: 'Foreman', initials: 'RD', short: 'R. Dela Cruz' },
    active: 'rollcall',
    badges: { rollcall: '18', leave: '2' },
    page: {
      title: 'Roll Call',
      subtitle: 'Site 07 — Mandaue Viaduct · Crew B · 05 Sep 2026',
      searchHint: 'Search my crew',
      stats: [
        { label: 'My crew', value: '38', note: 'assigned today', tone: 'ink' },
        { label: 'Marked present', value: '34', note: 'by 07:30', tone: 'ink' },
        { label: 'Not yet marked', value: '4', note: 'gate 2 device down', tone: 'amber' },
        { label: 'Held on device', value: '18', note: 'uploads when online', tone: 'orange' },
      ],
      panelTitle: 'Crew B — today',
      panelNote: 'tap a worker to set status',
      panelAction: 'Submit roll call',
      tableHead: ['Worker', 'Trade', 'Time in', 'Status'],
      tableRows: [
        { a: 'Abainza, Jomar T.', aSub: 'ADC-0455', b: 'Steelwork', c: '06:58', badge: 'Pending Sync', tone: 'pending' },
        { a: 'Bacus, Elmer P.', aSub: 'ADC-0509', b: 'Formwork', c: '07:10', badge: 'Present', tone: 'present' },
        { a: 'Ompad, Kevin R.', aSub: 'ADC-0742', b: 'Rebar', c: '08:22', badge: 'Late', tone: 'late' },
        { a: 'Sarmiento, Noel', aSub: 'ADC-0810', b: 'Masonry', c: '—', badge: 'Not marked', tone: 'neutral' },
      ],
      primaryAction: 'Submit roll call',
      secondaryAction: 'Request manpower',
      actionNote: 'Works offline. Records queue and upload on signal.',
      scope: ['Only Crew B at Site 07 — no other crew or site', 'Can set attendance and endorse leave for his crew', 'Manual time edits are flagged for HR review', 'No rates, payroll or employee files'],
    },
  },
  engineer: {
    label: 'Site Engineer / CM',
    user: { name: 'Tabotabo, Grace M.', detail: 'Sites 04, 07, 11 · Construction Manager', role: 'Site Engineer', initials: 'GT', short: 'G. Tabotabo' },
    active: 'manpower',
    badges: { manpower: '6', compliance: '4', attendance: '12' },
    page: {
      title: 'Manpower Allocation',
      subtitle: 'Sites 04, 07, 11 · week of 31 Aug 2026',
      searchHint: 'Search crew or trade',
      stats: [
        { label: 'Manpower on site', value: '117', note: 'across 3 sites', tone: 'ink' },
        { label: 'Required', value: '124', note: 'per schedule', tone: 'ink' },
        { label: 'Short', value: '7', note: 'rebar and masonry', tone: 'amber' },
        { label: 'Expiring certs', value: '4', note: 'within 14 days', tone: 'orange' },
      ],
      panelTitle: 'Crew requests awaiting my approval',
      panelNote: 'raised by foremen',
      panelAction: 'Open allocation board',
      tableHead: ['Request', 'Site', 'Need', 'Status'],
      tableRows: [
        { a: '6 rebar workers', aSub: 'raised by R. Dela Cruz', b: 'Site 07', c: 'Mon 07 Sep', badge: 'Pending', tone: 'pending' },
        { a: '2 formwork carpenters', aSub: 'raised by J. Lim', b: 'Site 11', c: 'Wed 09 Sep', badge: 'Pending', tone: 'pending' },
        { a: 'Transfer — 3 masons', aSub: 'Site 04 → Site 11', b: 'Site 04', c: 'Fri 11 Sep', badge: 'Approved', tone: 'present' },
        { a: 'Safety cert renewal', aSub: '4 workers, scaffolding', b: 'Site 07', c: 'in 14 days', badge: 'Late', tone: 'late' },
      ],
      primaryAction: 'Approve allocation',
      secondaryAction: 'View site attendance',
      actionNote: 'Approval reassigns crews and notifies both foremen.',
      scope: ['Attendance and compliance for his three sites', 'Assigns and transfers crews between those sites', 'Reads employee files, cannot edit rates or contracts', 'No payroll, no user provisioning'],
    },
  },
  admin: {
    label: 'System Administrator',
    user: { name: 'Uy, Francis L.', detail: 'IT · Head Office', role: 'Admin', initials: 'FU', short: 'F. Uy' },
    active: 'users',
    badges: { users: '3', synchealth: '2' },
    page: {
      title: 'Users & Roles',
      subtitle: 'Provisioning and access · 47 accounts',
      searchHint: 'Search account or role',
      stats: [
        { label: 'Active accounts', value: '47', note: 'of 201 employees', tone: 'ink' },
        { label: 'Pending provisioning', value: '3', note: 'requested by HR', tone: 'orange' },
        { label: 'Locked out', value: '1', note: 'failed attempts', tone: 'red' },
        { label: 'Field devices offline', value: '2', note: 'over 4 hours', tone: 'amber' },
      ],
      panelTitle: 'Access requests',
      panelNote: 'no self-registration — every account starts here',
      panelAction: 'Open user list',
      tableHead: ['Account', 'Requested role', 'Requested by', 'Status'],
      tableRows: [
        { a: 'Lim, Joseph A.', aSub: 'ADC-0904 · Site 11', b: 'Site Foreman', c: 'M. Reyes, HR', badge: 'Pending', tone: 'pending' },
        { a: 'Cortes, Divina', aSub: 'ADC-0177 · Head Office', b: 'HR Personnel', c: 'M. Reyes, HR', badge: 'Pending', tone: 'pending' },
        { a: 'Abainza, Jomar T.', aSub: 'ADC-0455 · Site 04', b: 'Site Engineer', c: 'G. Tabotabo', badge: 'Pending', tone: 'pending' },
        { a: 'Ompad, Kevin R.', aSub: 'ADC-0742 · locked 15 min', b: 'Site Foreman', c: '—', badge: 'Absent', tone: 'absent' },
      ],
      primaryAction: 'Provision account',
      secondaryAction: 'Reset password',
      actionNote: 'Roles are assigned here and nowhere else.',
      scope: ['Creates accounts, assigns roles, resets credentials', 'Monitors biometric devices and field-app sync', 'Owns cut-off dates, holidays and integrations', 'No approval authority over attendance or payroll'],
    },
  },
  exec: {
    label: 'Executive',
    user: { name: 'Arcenas, Ma. Teresa', detail: 'Executive Vice President', role: 'Executive', initials: 'MA', short: 'M.T. Arcenas' },
    active: 'reports',
    badges: {},
    page: {
      title: 'Reports & Analytics',
      subtitle: 'Company-wide · August 2026',
      searchHint: 'Search a site or report',
      stats: [
        { label: 'Total headcount', value: '201', note: '+8 vs July', tone: 'ink' },
        { label: 'Labour cost, Aug', value: '₱14.2M', note: '+3.1% vs July', tone: 'ink' },
        { label: 'Absence rate', value: '4.6%', note: 'target under 5%', tone: 'ink' },
        { label: 'Overtime hours', value: '3,180', note: 'Site 07 leads', tone: 'amber' },
      ],
      panelTitle: 'Labour cost by site',
      panelNote: 'August 2026, ₱ thousands',
      panelAction: 'Open report',
      tableHead: ['Site', 'Headcount', 'Cost', 'Trend'],
      tableRows: [
        { a: 'Site 04 — Cebu North', aSub: 'Structural, phase 2', b: '62', c: '₱4,410', badge: 'On budget', tone: 'present' },
        { a: 'Site 07 — Mandaue Viaduct', aSub: 'Overtime heavy', b: '48', c: '₱3,920', badge: 'Watch', tone: 'late' },
        { a: 'Site 11 — Talisay Housing', aSub: 'Ramping up', b: '39', c: '₱2,640', badge: 'On budget', tone: 'present' },
        { a: 'Other 8 sites', aSub: 'aggregate', b: '52', c: '₱3,230', badge: 'On budget', tone: 'present' },
      ],
      primaryAction: 'Export board pack',
      secondaryAction: 'Compare sites',
      actionNote: 'Read-only. Figures are aggregates, never individual pay.',
      scope: ['Company-wide totals: cost, headcount, absence, overtime', 'Drill down to site level, never to a payslip', 'Read-only throughout — no approvals, no edits', 'No user provisioning or system settings'],
    },
  },
}

export { tone, statTone }