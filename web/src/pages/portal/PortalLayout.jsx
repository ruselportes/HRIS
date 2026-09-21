import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useAuth } from '../../auth/AuthContext'
import { PORTAL_LINKS } from '../../config/nav'
import { ArcenasMark } from '../../components/ArcenasMark'
import { Icon } from '../../components/icons'

// Phone-first, one column: on a small screen the identity header, the nav
// and the sign-out stay visible at all times above the content. The sidebar
// is the large-screen treatment of the same links.
export function PortalLayout() {
  const { user, signOut } = useAuth()
  const navigate = useNavigate()

  const handleSignOut = async () => {
    await signOut()
    navigate('/login', { replace: true })
  }

  return (
    <div className="flex h-screen flex-col overflow-hidden lg:flex-row">
      <header className="flex-none bg-primary-900 px-[18px] pb-3 pt-3.5 text-canvas lg:hidden">
        <div className="flex items-center justify-between gap-3">
          <div className="min-w-0">
            <div className="truncate text-sm font-semibold">{user?.full_name}</div>
            <div className="text-xs opacity-65">{user?.employee_code}</div>
          </div>
          <button
            type="button"
            onClick={handleSignOut}
            className="flex flex-none items-center gap-1.5 border border-canvas/45 px-2.5 py-1.5 text-[13px]"
          >
            <Icon.logout />
            Sign out
          </button>
        </div>
        <nav className="mt-2.5 flex gap-1.5 overflow-x-auto" aria-label="Worker portal">
          {PORTAL_LINKS.map((item) => (
            <NavLink
              key={item.key}
              to={item.to}
              end={item.to === '/portal'}
              className={({ isActive }) =>
                `flex-none border px-2.5 py-1.5 text-[13px] ${
                  isActive ? 'border-canvas bg-canvas/15' : 'border-canvas/45 opacity-85'
                }`
              }
            >
              {item.label}
            </NavLink>
          ))}
        </nav>
      </header>

      <aside className="hidden h-full w-[252px] flex-none flex-col bg-primary-900 text-canvas lg:flex">
        <div className="flex items-center gap-2.5 border-b border-canvas/14 px-[18px] py-[18px]">
          <ArcenasMark className="h-7 w-auto flex-none" />
          <div className="font-heading text-[17px]">
            ADC <span className="opacity-55">HRIS</span>
          </div>
        </div>

        <div className="border-b border-canvas/14 px-[18px] py-4">
          <div className="mb-2 text-[10px] uppercase tracking-[.12em] opacity-50">Signed in as</div>
          <div className="text-sm">{user?.full_name}</div>
          <div className="mb-2.5 text-xs opacity-65">{user?.employee_code}</div>
          <span className="inline border border-canvas/45 px-2 py-0.5 text-xs">{user?.role?.role_name ?? ''}</span>
        </div>

        <nav className="flex-1 overflow-auto pb-3 pt-2.5">
          {PORTAL_LINKS.map((item) => (
            <NavLink
              key={item.key}
              to={item.to}
              end={item.to === '/portal'}
              className={({ isActive }) =>
                `flex min-h-[44px] items-center gap-2.5 px-[18px] py-2.5 text-sm ${
                  isActive ? 'bg-primary-800 shadow-[inset_3px_0_0_#b5d9fd]' : 'opacity-85 hover:bg-primary-800/40'
                }`
              }
            >
              <span className="truncate">{item.label}</span>
            </NavLink>
          ))}
        </nav>

        <button
          type="button"
          onClick={handleSignOut}
          className="flex items-center gap-2.5 border-t border-canvas/14 px-[18px] py-3.5 text-[13px] opacity-80 hover:bg-primary-800/40"
        >
          <Icon.logout />
          Sign out
        </button>
      </aside>

      <main className="min-h-0 flex-1 overflow-auto">
        <Outlet />
      </main>
    </div>
  )
}
