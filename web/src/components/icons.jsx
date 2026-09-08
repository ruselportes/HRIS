function Svg({ size = 17, vb = '0 0 24 24', children, ...rest }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox={vb}
      aria-hidden="true"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.5}
      strokeLinecap="round"
      strokeLinejoin="round"
      {...rest}
    >
      {children}
    </svg>
  )
}

const ICON_MAP = {
  dash: (p) => (
    <Svg {...p}>
      <rect x="3.5" y="3.5" width="7" height="7" />
      <rect x="13.5" y="3.5" width="7" height="7" />
      <rect x="3.5" y="13.5" width="7" height="7" />
      <rect x="13.5" y="13.5" width="7" height="7" />
    </Svg>
  ),
  people: (p) => (
    <Svg {...p}>
      <path d="M4 20v-1a5 5 0 015-5h1" />
      <circle cx="9" cy="8" r="3.5" />
      <path d="M15 20v-1a5 5 0 015-5" />
    </Svg>
  ),
  clock: (p) => (
    <Svg {...p}>
      <circle cx="12" cy="12" r="8.5" />
      <path d="M12 7.5V12l3 2" />
    </Svg>
  ),
  crew: (p) => (
    <Svg {...p}>
      <path d="M3 20v-1a4 4 0 014-4h2" />
      <circle cx="8" cy="9" r="3" />
      <path d="M13 20v-1a4 4 0 014-4h1" />
      <circle cx="17" cy="9" r="3" />
    </Svg>
  ),
  leave: (p) => (
    <Svg {...p}>
      <rect x="3.5" y="5" width="17" height="15" />
      <path d="M3.5 10h17" />
      <path d="M8 3v4" />
      <path d="M16 3v4" />
    </Svg>
  ),
  flag: (p) => (
    <Svg {...p}>
      <path d="M5 21V4h11l-1.5 4L16 12H5" />
    </Svg>
  ),
  card: (p) => (
    <Svg {...p}>
      <rect x="3.5" y="5.5" width="17" height="13" />
      <path d="M3.5 10h17" />
    </Svg>
  ),
  doc: (p) => (
    <Svg {...p}>
      <path d="M6 3h8l4 4v14H6z" />
      <path d="M14 3v4h4" />
    </Svg>
  ),
  site: (p) => (
    <Svg {...p}>
      <path d="M4 20h16" />
      <path d="M6 20V9l6-4 6 4v11" />
    </Svg>
  ),
  shield: (p) => (
    <Svg {...p}>
      <path d="M12 3l8 4v6c0 4-3.5 7-8 8-4.5-1-8-4-8-8V7z" />
    </Svg>
  ),
  chart: (p) => (
    <Svg {...p}>
      <path d="M4 20h16" />
      <path d="M7 20v-6" />
      <path d="M12 20V8" />
      <path d="M17 20v-9" />
    </Svg>
  ),
  users: (p) => (
    <Svg {...p}>
      <circle cx="12" cy="8" r="3.5" />
      <path d="M5 20v-1a7 7 0 0114 0v1" />
    </Svg>
  ),
  gear: (p) => (
    <Svg {...p}>
      <circle cx="12" cy="12" r="3" />
      <path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1" />
    </Svg>
  ),
  sync: (p) => (
    <Svg {...p}>
      <path d="M4 12a8 8 0 018-8 8 8 0 015.7 2.4" />
      <path d="M20 12a8 8 0 01-8 8 8 8 0 01-5.7-2.4" />
      <path d="M18 3v4h-4" />
      <path d="M6 21v-4h4" />
    </Svg>
  ),
  megaphone: (p) => (
    <Svg {...p}>
      <path d="M4 10v4l12 5V5z" />
      <path d="M16 9a3 3 0 010 6" />
    </Svg>
  ),
  search: (p) => (
    <Svg {...p}>
      <circle cx="11" cy="11" r="6.5" />
      <path d="M16 16l4 4" />
    </Svg>
  ),
  bell: (p) => (
    <Svg {...p}>
      <path d="M6 10a6 6 0 1112 0c0 4 1.5 5.5 1.5 5.5H4.5S6 14 6 10z" />
      <path d="M10 19a2 2 0 004 0" />
    </Svg>
  ),
  logout: (p) => (
    <Svg {...p}>
      <path d="M14 4h4v16h-4" />
      <path d="M10 8l-4 4 4 4" />
      <path d="M6 12h8" />
    </Svg>
  ),
  chevron: (p) => (
    <Svg {...p}>
      <path d="M6 9l6 6 6-6" />
    </Svg>
  ),
  caretRight: (p) => (
    <Svg {...p}>
      <path d="M9 6l6 6-6 6" />
    </Svg>
  ),
  plus: (p) => (
    <Svg {...p}>
      <path d="M12 5v14" />
      <path d="M5 12h14" />
    </Svg>
  ),
  download: (p) => (
    <Svg {...p}>
      <path d="M12 3v12" />
      <path d="M7 11l5 5 5-5" />
      <path d="M4 20h16" />
    </Svg>
  ),
  close: (p) => (
    <Svg {...p}>
      <path d="M6 6l12 12" />
      <path d="M18 6L6 18" />
    </Svg>
  ),
  info: (p) => (
    <Svg {...p}>
      <circle cx="12" cy="12" r="8.5" />
      <path d="M12 11v6" />
      <path d="M12 8v.5" />
    </Svg>
  ),
  alert: (p) => (
    <Svg {...p}>
      <path d="M12 4l8 15H4z" />
      <path d="M12 10v4" />
      <path d="M12 16.5v.5" />
    </Svg>
  ),
  eye: (p) => (
    <Svg {...p}>
      <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z" />
      <circle cx="12" cy="12" r="3" />
    </Svg>
  ),
  arrowRight: (p) => (
    <Svg {...p}>
      <path d="M5 12h14" />
      <path d="M13 6l6 6-6 6" />
    </Svg>
  ),
  arrowLeft: (p) => (
    <Svg {...p}>
      <path d="M19 12H5" />
      <path d="M11 6l-6 6 6 6" />
    </Svg>
  ),
  grip: (p) => (
    <Svg {...p}>
      <path d="M9 6h.01M15 6h.01M9 12h.01M15 12h.01M9 18h.01M15 18h.01" />
    </Svg>
  ),
  check: (p) => (
    <Svg {...p}>
      <path d="M4 12l5 5L20 6" />
    </Svg>
  ),
  calendar: (p) => (
    <Svg {...p}>
      <rect x="3.5" y="5" width="17" height="15" />
      <path d="M3.5 10h17" />
      <path d="M8 3v4" />
      <path d="M16 3v4" />
    </Svg>
  ),
}

export function Icon({ name, ...rest }) {
  const Render = ICON_MAP[name]
  return Render ? <Render {...rest} /> : null
}

Object.assign(Icon, ICON_MAP)