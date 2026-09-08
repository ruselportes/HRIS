export function ComingSoonPage({ item }) {
  if (!item) {
    return (
      <div className="p-[22px] text-sm text-neutral-700">That area is not part of this demo build.</div>
    )
  }
  return (
    <div className="flex flex-col items-center justify-center gap-3 p-[22px] py-24 text-center">
      <div className="font-heading text-2xl">{item.label}</div>
      <p className="max-w-md text-sm text-neutral-700">{item.note}.</p>
      <span className="mt-2 inline-flex items-center gap-2 border border-primary-700 px-3 py-1.5 text-xs uppercase tracking-[.08em] text-primary-800">
        Coming soon
      </span>
      <p className="mt-1 text-xs text-neutral-700">
        This module is scoped to a later capstone phase. The dashboard shows its role-specific preview above.
      </p>
    </div>
  )
}