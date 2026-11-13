// Иконка резки: отрезной круг режет заготовку, летят искры (вместо ножниц).
export function CutDiscIcon({ className = '' }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={2}
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
      aria-hidden="true"
    >
      <circle cx="10" cy="9.5" r="7.5" />
      <circle cx="10" cy="9.5" r="2" />
      <path d="M2 20.5h20" />
      <path d="M16 15l4-2.5M16.5 17.5l5-1" strokeWidth={1.8} />
    </svg>
  );
}
