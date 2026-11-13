const steps = [
  { t: 'Заявка', s: 'Список позиций или спецификация' },
  { t: 'Расчёт', s: 'Цена с резкой и доставкой' },
  { t: 'Счёт и оплата', s: 'Договор, безналичный расчёт, НДС' },
  { t: 'Доставка', s: 'На объект, с документами' },
];

// Блок «Как мы работаем» (раньше здесь была лента логотипов заводов).
export function Reviews() {
  return (
    <section className="relative py-10 md:py-14">
      <div className="container-wide">
        <div className="rounded-2xl border border-white/[0.08] bg-graphite-900/40 px-6 py-6 md:px-8">
          <div className="mb-5 text-center font-mono text-[11px] uppercase tracking-[0.25em] text-steel-500">
            Как мы работаем
          </div>
          <ol className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {steps.map((step, i) => (
              <li key={step.t} className="flex items-start gap-4">
                <span className="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-accent-500/10 font-mono text-sm font-semibold text-accent-500 ring-1 ring-accent-500/30">
                  {i + 1}
                </span>
                <div>
                  <div className="font-display text-base font-semibold text-white">{step.t}</div>
                  <div className="mt-0.5 text-xs text-steel-400">{step.s}</div>
                </div>
              </li>
            ))}
          </ol>
        </div>
      </div>
    </section>
  );
}
