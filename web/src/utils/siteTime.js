// Site time (Asia/Manila) formatting, independent of the viewer's own timezone.

const SITE_TZ = 'Asia/Manila'

const untilFmt = new Intl.DateTimeFormat('en-GB', { timeZone: SITE_TZ, weekday: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })

/** When an acting foreman cover ends, e.g. "Sat 23:59". */
export const coverUntil = (iso) => (iso ? untilFmt.format(new Date(iso)) : '')
