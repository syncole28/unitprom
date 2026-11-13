import { Award } from 'lucide-react';

const certs = [
  { name: 'Сертификат качества', sub: 'Завода-изготовителя · на каждую партию' },
  { name: 'Соответствие ГОСТ', sub: 'Арматура 34028-2016, трубы 10704 и 3262, уголок 8509, швеллер 8240' },
  { name: 'Марка стали', sub: 'Ст3пс/сп, 09Г2С, А500С — указываем в счёте и УПД' },
  { name: 'Закрывающие документы', sub: 'Договор, счёт, УПД · работаем с НДС' },
];

export function Certificates() {
  return (
    <section className="relative border-y border-white/[0.06] bg-graphite-900/40 py-20 md:py-24">
      <div className="container-wide">
        <div className="flex flex-col items-start justify-between gap-6 md:flex-row md:items-end">
          <div className="max-w-2xl">
            <span className="eyebrow">Сертификаты и документы</span>
            <h2 className="mt-5 font-display text-3xl font-semibold text-white md:text-4xl">
              Подтверждённое качество на каждой поставке
            </h2>
          </div>
          <p className="max-w-md text-sm text-steel-400">
            Все документы передаём вместе с отгрузкой. По запросу пришлём копии сертификатов
            качества заранее, до оплаты.
          </p>
        </div>

        <div className="mt-10 grid grid-cols-2 gap-3 sm:grid-cols-4">
          {certs.map((c) => (
            <div
              key={c.name}
              className="group relative flex flex-col rounded-xl border border-white/[0.08] bg-graphite-900/70 p-5 transition hover:border-accent-500/40 hover:bg-graphite-800"
            >
              <div className="grid h-10 w-10 place-items-center rounded-md bg-accent-500/10 ring-1 ring-accent-500/30">
                <Award className="h-5 w-5 text-accent-500" />
              </div>
              <div className="mt-4 font-display text-sm font-semibold text-white">{c.name}</div>
              <div className="mt-1 text-[11px] leading-snug text-steel-400">{c.sub}</div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
