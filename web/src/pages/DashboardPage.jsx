import { ROLES, tone, statTone } from '../config/nav'
import { useAuth } from '../auth/AuthContext'

function roleKey(slug) {
  return slug === 'executive' ? 'exec' : slug
}

const Badge = ({ tone: variant, children }) => {
  const colors = tone[variant] ?? tone.neutral
  return (
    <span
      className="inline-flex items-center gap-1.5 whitespace-nowrap px-2 py-1 text-xs"
      style={{ background: colors.bg, color: colors.fg }}
    >
      {children}
    </span>
  )
}

export function DashboardPage() {
  const { user } = useAuth()
  const role = ROLES[roleKey(user?.role?.slug)] ?? ROLES.hr
  const page = role.page

  return (
    <div className="flex flex-col gap-5 p-[22px]">
      <div className="grid grid-cols-2 gap-4 xl:grid-cols-4">
        {page.stats.map((stat) => (
          <div key={stat.label} className="border border-transparent bg-surface p-3.5">
            <div className="text-[10px] uppercase tracking-[.1em] text-neutral-700">{stat.label}</div>
            <div
              className="font-heading text-[32px] tabular-nums"
              style={{ color: statTone[stat.tone] ?? statTone.ink }}
            >
              {stat.value}
            </div>
            <div className="text-[11px] text-neutral-700">{stat.note}</div>
          </div>
        ))}
      </div>

      <div className="grid grid-cols-1 gap-5 xl:grid-cols-[1.6fr_1fr]">
        <section className="border border-transparent bg-surface p-4">
          <div className="mb-3 flex items-baseline gap-3">
            <h2 className="font-heading text-lg">{page.panelTitle}</h2>
            <span className="text-[11px] text-neutral-700">{page.panelNote}</span>
            <button
              type="button"
              className="ml-auto border border-neutral-300 px-2.5 py-1 text-xs text-ink hover:bg-neutral-200"
            >
              {page.panelAction}
            </button>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[520px] border-collapse text-sm tabular-nums">
              <thead>
                <tr className="border-b border-neutral-300 text-left text-[11px] uppercase tracking-[.08em] text-neutral-700">
                  {page.tableHead.map((head) => (
                    <th key={head} className="py-2 pr-4 font-normal">
                      {head}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {page.tableRows.map((row) => (
                  <tr key={row.a} className="border-b border-neutral-200 align-top">
                    <td className="py-2 pr-4">
                      {row.a}
                      <div className="text-[11px] text-neutral-700">{row.aSub}</div>
                    </td>
                    <td className="py-2 pr-4">{row.b}</td>
                    <td className="py-2 pr-4">{row.c}</td>
                    <td className="py-2">
                      <Badge tone={row.tone}>{row.badge}</Badge>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>

        <div className="flex flex-col gap-5">
          <section className="border border-transparent bg-surface p-4">
            <h2 className="mb-3 font-heading text-lg">Your actions</h2>
            <div className="flex flex-col gap-2.5">
              <button
                type="button"
                className="h-10 w-full bg-primary font-heading font-semibold text-canvas hover:bg-primary-600"
              >
                {page.primaryAction}
              </button>
              <button
                type="button"
                className="h-10 w-full border border-neutral-300 bg-surface text-ink hover:bg-neutral-200"
              >
                {page.secondaryAction}
              </button>
            </div>
            <p className="mt-3.5 text-xs text-neutral-700">{page.actionNote}</p>
          </section>

          <section className="border border-transparent bg-surface p-4">
            <div className="mb-3 text-[11px] uppercase tracking-[.1em] text-primary-700">Scope of this account</div>
            <ul className="flex flex-col gap-2.5 text-[13px]">
              {page.scope.map((item) => (
                <li key={item} className="flex items-start gap-2.5">
                  <span className="mt-[6px] h-[6px] w-[6px] flex-none bg-primary" />
                  <span>{item}</span>
                </li>
              ))}
            </ul>
          </section>
        </div>
      </div>
    </div>
  )
}