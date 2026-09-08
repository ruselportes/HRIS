import { ROLES } from '../config/nav'
import { useAuth } from '../auth/AuthContext'
import { Icon } from './icons'

function roleKey(slug) {
  return slug === 'executive' ? 'exec' : slug
}

export function TopBar({ title, subtitle, searchHint }) {
  const { user } = useAuth()
  const role = ROLES[roleKey(user?.role?.slug)] ?? ROLES.hr

  return (
    <div className="flex items-center gap-4 border-b border-neutral-300 px-[22px] py-3.5">
      <div className="min-w-0">
        <div className="truncate font-heading text-[22px] leading-tight">{title}</div>
        {subtitle ? <div className="truncate text-[11px] text-neutral-700">{subtitle}</div> : null}
      </div>

      <div className="ml-3 hidden h-[34px] w-[240px] items-center gap-2 border border-neutral-300 px-3 text-[13px] text-neutral-600 lg:flex">
        <Icon.search />
        <span className="truncate">{searchHint ?? 'Search'}</span>
      </div>

      <div className="ml-auto flex items-center gap-3.5">
        <span className="inline-flex items-center gap-1.5 border border-[#2F7A4D] px-2.5 py-1">
          <span className="h-[7px] w-[7px] rounded-full bg-[#2F7A4D]" />
          <span className="font-heading text-xs uppercase tracking-[.04em] text-[#1F5334]">Online</span>
        </span>

        <button
          type="button"
          aria-label="Notifications"
          className="grid h-8 w-8 place-items-center border border-neutral-300 text-ink hover:bg-neutral-200"
        >
          <Icon.bell />
        </button>

        <div className="flex items-center gap-2">
          <span className="grid h-7 w-7 place-items-center border border-neutral-300 font-heading text-xs">
            {role.user.initials}
          </span>
          <span className="text-[13px]">{role.user.short}</span>
        </div>
      </div>
    </div>
  )
}