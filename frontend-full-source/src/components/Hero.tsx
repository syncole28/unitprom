import { ArrowRight, Truck, ShieldCheck, Factory } from 'lucide-react';
import type { FeaturedItem } from '../lib/useCatalog';

export function Hero({ featured, loading }: { featured: FeaturedItem[]; loading: boolean }) {
  return (
    <section className="relative overflow-hidden">
      <div className="absolute inset-0">
        <img
          src="https://unit-prom.ru/Images/HERO.jpg"
          alt="Склад металлопроката"
          className="h-full w-full object-cover opacity-40"
        />
        <div className="absolute inset-0 bg-gradient-to-r from-graphite-950 via-graphite-950/85 to-graphite-950/40" />
        <div className="absolute inset-0 bg-gradient-to-b from-graphite-950/0 via-transparent to-graphite-950" />
      </div>
      <div className="absolute inset-0 hero-grid opacity-60" />

      <div className="container-wide relative grid grid-cols-1 items-center gap-10 pt-12 pb-20 md:pt-20 md:pb-28 lg:grid-cols-12 lg:gap-8 lg:pt-28 lg:pb-36">
        <div className="lg:col-span-7">
          <div className="animate-fade-up">
            <span className="eyebrow">Поставки металла · Красноярский край</span>
          </div>
          <h1 className="mt-6 animate-fade-up font-display text-[40px] font-semibold leading-[1.02] text-white sm:text-5xl md:text-6xl lg:text-[72px]">
            Металлопрокат <span className="text-steel-400">оптом и в розницу</span>{' '}
            <span className="bg-gradient-to-r from-accent-500 to-accent-300 bg-clip-text text-transparent">
              с доставкой
            </span>{' '}
            по Красноярскому краю
          </h1>
          <p className="mt-6 max-w-xl animate-fade-up text-base text-steel-300 sm:text-lg">
            Склад в Красноярске, резка в размер и доставка на объект манипулятором и длинномером.
            Работаем со строительными подрядчиками, заводами и частными застройщиками, с НДС.
          </p>

          <div className="mt-9 flex flex-wrap items-center gap-3 animate-fade-up">
            <a href="#contact" className="btn-primary">
              Получить расчёт
              <ArrowRight className="h-4 w-4" />
            </a>
            <a href="#catalog" className="btn-ghost">
              Каталог продукции
            </a>
          </div>

          <dl className="mt-12 grid max-w-2xl grid-cols-3 gap-px overflow-hidden rounded-2xl border border-white/[0.08] bg-white/[0.02] animate-fade-up">
            {[
              { k: 'Широкий сортамент', v: 'металлопроката' },
              { k: 'Резка в размер', v: 'под ваш объект' },
              { k: 'Быстрая доставка', v: 'по всему региону' },
            ].map((s) => (
              <div key={s.k} className="bg-graphite-900/60 px-4 py-5 text-center md:px-6">
                <dt className="font-display text-base font-semibold text-white md:text-lg">{s.k}</dt>
                <dd className="mt-1 text-[11px] uppercase tracking-wider text-steel-500">{s.v}</dd>
              </div>
            ))}
          </dl>
        </div>

        {(loading || featured.length > 0) && (
          <div className="lg:col-span-5">
            <div className="glass-panel relative overflow-hidden p-6 md:p-8 shadow-panel">
              <div className="absolute -top-24 -right-24 h-64 w-64 rounded-full bg-accent-500/15 blur-3xl" />
              <div className="relative">
                <div className="flex items-center gap-3">
                  <div className="h-2 w-2 rounded-full bg-accent-500 animate-pulse" />
                  <span className="font-mono text-[11px] uppercase tracking-[0.2em] text-steel-300">
                    актуальный прайс · {new Date().toLocaleDateString('ru-RU')}
                  </span>
                </div>
                <h3 className="mt-4 font-display text-xl font-semibold text-white">
                  Цены на ходовые позиции
                </h3>
                {loading ? (
                  <div className="mt-5 space-y-3">
                    {Array.from({ length: 5 }).map((_, i) => (
                      <div key={i} className="h-9 animate-pulse rounded bg-white/5" />
                    ))}
                  </div>
                ) : (
                  <ul className="mt-5 divide-y divide-white/[0.06]">
                    {featured.map((item) => (
                      <li
                        key={item.id}
                        className="flex items-center justify-between py-3 text-sm"
                      >
                        <span className="text-steel-200">{item.label}</span>
                        <span className="font-mono font-semibold text-white">
                          {item.pricePerTon.toLocaleString('ru-RU')} ₽/т
                        </span>
                      </li>
                    ))}
                  </ul>
                )}
                <p className="mt-5 text-[11px] text-steel-500">
                  Цены ориентировочные. Финальная стоимость зависит от объёма и условий доставки.
                </p>
              </div>
            </div>
          </div>
        )}
      </div>

      <div className="relative border-t border-white/[0.06] bg-graphite-900/40">
        <div className="container-wide grid grid-cols-1 gap-px overflow-hidden md:grid-cols-3">
          {[
            { Icon: ShieldCheck, k: 'Сертификаты на каждую партию', v: 'ГОСТ, ТУ, сертификаты качества завода' },
            { Icon: Truck, k: 'Доставка по всему краю', v: 'Норильск · Канск · Ачинск · Минусинск' },
            { Icon: Factory, k: 'Работаем с НДС', v: 'Договор, счёт, закрывающие документы' },
          ].map(({ Icon, k, v }) => (
            <div key={k} className="flex items-center gap-4 px-2 py-5 md:px-6">
              <div className="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-accent-500/10 ring-1 ring-accent-500/30">
                <Icon className="h-5 w-5 text-accent-500" />
              </div>
              <div>
                <div className="text-sm font-semibold text-white">{k}</div>
                <div className="text-xs text-steel-400">{v}</div>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
