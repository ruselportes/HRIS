// Shared worker-portal formatting. Lives outside the page components so
// react-refresh (one component per file) stays quiet.

export const peso = (n) =>
  new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(n ?? 0))

const approvedFmt = new Intl.DateTimeFormat('en-GB', {
  timeZone: 'Asia/Manila',
  day: '2-digit',
  month: 'short',
  year: 'numeric',
})

export const approvedDate = (iso) => (iso ? approvedFmt.format(new Date(iso)) : '—')

// Plain Y-m-d site days are anchored at site noon so they cannot shift a day;
// only output formatting goes through a Date, never query building.
const siteDayFmt = new Intl.DateTimeFormat('en-GB', {
  timeZone: 'Asia/Manila',
  weekday: 'short',
  day: '2-digit',
  month: 'short',
})

export const siteDay = (ymd) => (ymd ? siteDayFmt.format(new Date(`${ymd}T12:00:00+08:00`)) : '—')
