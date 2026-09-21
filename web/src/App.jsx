import { useEffect } from 'react'
import { BrowserRouter, Navigate, Outlet, Route, Routes, useLocation } from 'react-router-dom'
import { AuthProvider, useAuth } from './auth/AuthContext'
import { Sidebar } from './components/Sidebar'
import { TopBar } from './components/TopBar'
import { LoginPage } from './pages/LoginPage'
import { ActivatePage } from './pages/ActivatePage'
import { ForgotPasswordPage } from './pages/ForgotPasswordPage'
import { DashboardPage } from './pages/DashboardPage'
import { EmployeesPage } from './pages/EmployeesPage'
import { LeavePage } from './pages/LeavePage'
import { ManpowerPage } from './pages/ManpowerPage'
import { OverridesPage } from './pages/OverridesPage'
import { PayrollPage } from './pages/PayrollPage'
import { RecoveryPage } from './pages/RecoveryPage'
import { ReportsPage } from './pages/ReportsPage'
import { AttendancePage } from './pages/AttendancePage'
import { SyncHealthPage } from './pages/SyncHealthPage'
import { ComingSoonPage } from './pages/ComingSoonPage'
import { PortalLayout } from './pages/portal/PortalLayout'
import { PortalAttendancePage } from './pages/portal/PortalAttendancePage'
import { PortalPayslipsPage } from './pages/portal/PortalPayslipsPage'
import { PortalPayslipDetailPage } from './pages/portal/PortalPayslipDetailPage'
import { PortalAccountPage } from './pages/portal/PortalAccountPage'
import { NAV, ROLES, isPortalSlug } from './config/nav'

function roleKey(slug) {
  return slug === 'executive' ? 'exec' : slug
}

function RequireAuth() {
  const { user } = useAuth()
  const location = useLocation()
  if (!user) {
    return <Navigate to="/login" replace state={{ from: location }} />
  }
  return <Outlet />
}

function Shell() {
  const { user, signOut } = useAuth()
  const location = useLocation()
  const role = ROLES[roleKey(user?.role?.slug)]
  // Portal roles have their own tree: a worker landing on a staff page goes
  // to /portal below instead of being signed out. This has to be known to
  // the effect too — ROLES has no worker entry, so without the guard the
  // effect would sign the worker out in the same breath as the redirect.
  const portal = isPortalSlug(user?.role?.slug)
  // A signed-in role with no nav surface anywhere must not be left signed in:
  // revoke the token before bouncing to /login.
  useEffect(() => {
    if (!role && !portal) {
      signOut()
    }
  }, [role, portal, signOut])
  // After the hooks, so the hook order never changes between renders.
  if (portal) {
    return <Navigate to="/portal" replace />
  }
  if (!role) {
    return <Navigate to="/login" replace />
  }
  const key = location.pathname.split('/').filter(Boolean)[0] ?? 'dash'
  const item = NAV.find((entry) => entry.key === key)

  const header =
    key === 'dash' || key === 'employees'
      ? {
          title: key === 'employees' ? 'Employees' : role.page.title,
          subtitle: key === 'employees' ? '201 files, contracts, rates' : role.page.subtitle,
          searchHint: key === 'employees' ? 'Search employee or ID' : role.page.searchHint,
        }
      : {
          title: item?.label ?? 'HRIS',
          subtitle: item?.note,
          searchHint: role.page.searchHint,
        }

  const access = item ? item.access[roleKey(user?.role?.slug)] : 'full'

  return (
    <div className="flex h-screen overflow-hidden">
      <Sidebar />
      <div className="flex min-w-0 flex-1 flex-col">
        <TopBar {...header} />
        <main className="min-h-0 flex-1 overflow-auto">
          {key !== 'dash' && key !== 'employees' && access === 'none' ? (
            <Navigate to="/" replace />
          ) : null}
          <Outlet />
        </main>
      </div>
    </div>
  )
}

function EmployeeRoute() {
  const { user } = useAuth()
  const access = NAV.find((n) => n.key === 'employees').access[roleKey(user?.role?.slug)]
  if (access === 'none') {
    return <Navigate to="/" replace />
  }
  return <EmployeesPage />
}

function ManpowerRoute() {
  const { user } = useAuth()
  const access = NAV.find((n) => n.key === 'manpower').access[roleKey(user?.role?.slug)]
  if (access === 'none') {
    return <Navigate to="/" replace />
  }
  return <ManpowerPage />
}

function PayrollRoute() {
  const { user } = useAuth()
  const access = NAV.find((n) => n.key === 'payroll').access[roleKey(user?.role?.slug)]
  if (access === 'none') {
    return <Navigate to="/" replace />
  }
  return <PayrollPage />
}

function RecoveryRoute() {
  const { user } = useAuth()
  const access = NAV.find((n) => n.key === 'recovery').access[roleKey(user?.role?.slug)]
  if (access === 'none') {
    return <Navigate to="/" replace />
  }
  return <RecoveryPage />
}

function LeaveRoute() {
  const { user } = useAuth()
  const access = NAV.find((n) => n.key === 'leave').access[roleKey(user?.role?.slug)]
  if (access === 'none') {
    return <Navigate to="/" replace />
  }
  return <LeavePage />
}

function ReportsRoute() {
  const { user } = useAuth()
  const access = NAV.find((n) => n.key === 'reports').access[roleKey(user?.role?.slug)]
  if (access === 'none') {
    return <Navigate to="/" replace />
  }
  return <ReportsPage />
}

function OverridesRoute() {
  const { user } = useAuth()
  const access = NAV.find((n) => n.key === 'overrides').access[roleKey(user?.role?.slug)]
  if (access === 'none') {
    return <Navigate to="/" replace />
  }
  return <OverridesPage />
}

function AttendanceRoute() {
  const { user } = useAuth()
  const access = NAV.find((n) => n.key === 'attendance').access[roleKey(user?.role?.slug)]
  if (access === 'none') {
    return <Navigate to="/" replace />
  }
  return <AttendancePage />
}

function SyncHealthRoute() {
  const { user } = useAuth()
  const access = NAV.find((n) => n.key === 'synchealth').access[roleKey(user?.role?.slug)]
  if (access === 'none') {
    return <Navigate to="/" replace />
  }
  return <SyncHealthPage />
}

function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/activate" element={<ActivatePage />} />
          <Route path="/forgot-password" element={<ForgotPasswordPage />} />
          <Route element={<RequireAuth />}>
            <Route element={<PortalGate />}>
              <Route path="portal" element={<PortalLayout />}>
                <Route index element={<PortalAttendancePage />} />
                <Route path="payslips" element={<PortalPayslipsPage />} />
                <Route path="payslips/:run" element={<PortalPayslipDetailPage />} />
                <Route path="account" element={<PortalAccountPage />} />
              </Route>
            </Route>
            <Route element={<Shell />}>
              <Route index element={<DashboardPage />} />
              <Route path="employees" element={<EmployeeRoute />} />
              <Route path="attendance" element={<AttendanceRoute />} />
              <Route path="manpower" element={<ManpowerRoute />} />
              <Route path="leave" element={<LeaveRoute />} />
              <Route path="overrides" element={<OverridesRoute />} />
              <Route path="recovery" element={<RecoveryRoute />} />
              <Route path="synchealth" element={<SyncHealthRoute />} />
              <Route path="payroll" element={<PayrollRoute />} />
              <Route path="reports" element={<ReportsRoute />} />
              <Route path=":page" element={<ComingSoonPageShell />} />
            </Route>
          </Route>
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  )
}

function PortalGate() {
  const { user } = useAuth()
  // Staff on /portal go to the staff tree; unknown slugs land on / where the
  // Shell signs them out. Only portal roles reach the portal layout.
  if (!isPortalSlug(user?.role?.slug)) {
    return <Navigate to="/" replace />
  }
  return <Outlet />
}

function ComingSoonPageShell() {
  const { user } = useAuth()
  const location = useLocation()
  const key = location.pathname.split('/').filter(Boolean)[0] ?? ''
  const item = NAV.find((entry) => entry.key === key)
  const access = item?.access[roleKey(user?.role?.slug)]
  if (item && access === 'none') {
    return <Navigate to="/" replace />
  }
  return <ComingSoonPage item={item} />
}

export default App