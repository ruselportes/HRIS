/*
 * The Arcenas building mark, recreated from the client's logo. Master copy:
 * docs/assets/brand/arcenas-mark.svg — keep the paths identical if it changes.
 * Fills with currentColor, so it takes the text colour of wherever it sits
 * (light on the navy panels).
 */
export function ArcenasMark({ className, title }) {
  return (
    <svg
      viewBox="0 0 134 140"
      fill="currentColor"
      className={className}
      role={title ? 'img' : undefined}
      aria-label={title}
      aria-hidden={title ? undefined : 'true'}
    >
      <path d="M0 64H22V76H12V140H0Z" />
      <path d="M30 20H52V32H42V140H30Z" />
      <path d="M61 0H73V140H61Z" />
      <path d="M82 20H104V140H92V32H82Z" />
      <path d="M112 64H134V140H122V76H112Z" />
    </svg>
  )
}
