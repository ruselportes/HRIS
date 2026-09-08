import { NavLink, useNavigate } from 'react-router-dom'
import { GROUP_ORDER, NAV, ROLES } from '../config/nav'
import { useAuth } from '../auth/AuthContext'
import { Icon } from './icons'

function roleKey(slug) {
  return slug === 'executive' ? 'exec' : slug
}

export function Sidebar() {
  const { user, signOut } = useAuth()
  const navigate = useNavigate()
  const role = ROLES[roleKey(user?.role?.slug)] ?? ROLES.hr
  const visible = NAV.filter((item) => item.access[roleKey(user?.role?.slug)] !== 'none')

  const handleSignOut = async () => {
    await signOut()
    navigate('/login', { replace: true })
  }

  return (
    <aside className="flex h-full w-[252px] flex-none flex-col bg-primary-900 text-canvas">
      <div className="flex items-center gap-2.5 border-b border-canvas/14 px-[18px] py-[18px]">
        <span className="grid h-7 w-7 place-items-center border border-canvas/50 font-heading text-sm">
          A
        </span>
        <div className="font-heading text-[17px]">
          ADC <span className="opacity-55">HRIS</span>
        </div>
      </div>

      <div className="border-b border-canvas/14 px-[18px] py-4">
        <div className="mb-2 text-[10px] uppercase tracking-[.12em] opacity-50">Signed in as</div>
        <div className="text-sm">{role.user.name}</div>
        <div className="mb-2.5 text-xs opacity-65">{role.user.detail}</div>
        <span className="inline border border-canvas/45 px-2 py-0.5 text-xs">{role.user.role}</span>
      </div>

      <nav className="flex-1 overflow-auto pb-3">
        {GROUP_ORDER.map((group) => {
          const items = visible.filter((item) => item.group === group)
          if (!items.length) return null
          return (
            <div key={group} className="pb-2.5">
              <div className="px-[18px] pb-1.5 pt-2.5 text-[10px] uppercase tracking-[.12em] opacity-45">
                {group}
              </div>
              {items.map((item) => {
                const Ico = Icon[item.icon]
                const access = item.access[roleKey(user?.role?.slug)]
                const badge = role.badges[item.key]
                const view = access === 'view'
                return (
                  <NavLink
                    key={item.key}
                    to={item.key === 'dash' ? '/' : `/${item.key}`}
                    end={item.key === 'dash'}
                    className={({ isActive }) =>
                      `flex min-h-[44px] items-center gap-2.5 px-[18px] py-2.5 text-sm ${
                        isActive
                          ? 'bg-primary-800 shadow-[inset_3px_0_0_#b5d9fd]'
                          : view
                            ? 'opacity-60'
                            : 'opacity-85 hover:bg-primary-800/40'
                      }`
                    }
                  >
                    <Ico className="flex-none" />
                    <span className="truncate">{item.label}</span>
                    {badge ? (
                      <span className="ml-auto bg-[#E2681C] px-1.5 py-0.5 text-[10px] text-white">
                        {badge}
                      </span>
                    ) : view ? (
                      <span className="ml-auto text-[10px] uppercase tracking-[.08em] opacity-60">view</span>
                    ) : null}
                  </NavLink>
                )
              })}
            </div>
          )
        })}
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
  )
}