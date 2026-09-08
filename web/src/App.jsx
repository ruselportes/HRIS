import { BrowserRouter, Navigate, Outlet, Route, Routes, useLocation } from 'react-router-dom'
import { AuthProvider, useAuth } from './auth/AuthContext'
import { Sidebar } from './components/Sidebar'
import { TopBar } from './components/TopBar'
import { LoginPage } from './pages/LoginPage'
import { ForgotPasswordPage } from './pages/ForgotPasswordPage'
import { DashboardPage } from './pages/DashboardPage'
import { EmployeesPage } from './pages/EmployeesPage'
import { ComingSoonPage } from './pages/ComingSoonPage'
import { NAV, ROLES } from './config/nav'

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
  const { user } = useAuth()
  const role = ROLES[roleKey(user?.role?.slug)] ?? ROLES.hr
  const location = useLocation()
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

function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/forgot-password" element={<ForgotPasswordPage />} />
          <Route element={<RequireAuth />}>
            <Route element={<Shell />}>
              <Route index element={<DashboardPage />} />
              <Route path="employees" element={<EmployeeRoute />} />
              <Route path=":page" element={<ComingSoonPageShell />} />
            </Route>
          </Route>
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  )
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