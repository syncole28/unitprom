import { Boxes, Warehouse, Truck, FileCheck2, Percent } from 'lucide-react';

const items = [
  {
    Icon: Boxes,
    title: 'Большой ассортимент',
    desc: 'Лист г/к, х/к, оцинкованный и ПВЛ, трубы ВГП, электросварные и профильные, уголок, швеллер, балка, полоса, квадрат, арматура А240 и А500С.',
  },
  {
    Icon: Warehouse,
    title: 'Быстрая отгрузка',
    desc: 'Склад в Красноярске: отгружаем после оплаты без ожидания на производстве. Резка в размер перед отгрузкой.',
  },
  {
    Icon: Truck,
    title: 'Доставка',
    desc: 'Манипулятор, длинномер, трал. Привозим на объект по Красноярску, всему краю и в соседние регионы.',
  },
  {
    Icon: FileCheck2,
    title: 'Сертификаты качества',
    desc: 'Сертификат качества завода-изготовителя на каждую партию, металл по ГОСТ и ТУ. Работаем с НДС, полный пакет закрывающих документов.',
  },
  {
    Icon: Percent,
    title: 'Условия для крупных заказов',
    desc: 'Индивидуальные условия для оптовых и корпоративных заказчиков — обсуждается с менеджером.',
  },
];

export function Advantages() {
  return (
    <section id="advantages" className="relative border-y border-white/[0.06] bg-graphite-900/40 py-20 md:py-24">
      <div className="container-wide">
        <div className="grid grid-cols-1 gap-10 lg:grid-cols-12">
          <div className="lg:col-span-4">
            <span className="eyebrow">Преимущества</span>
            <h2 className="mt-5 font-display text-3xl font-semibold text-white md:text-4xl">
              Условия, которые ценят строители и заводы
            </h2>
            <p className="mt-4 text-steel-400">
              Мы строим долгие отношения с клиентами через надёжность, прозрачность и скорость.
            </p>
          </div>

          <div className="lg:col-span-8">
            <div className="grid grid-cols-1 gap-px overflow-hidden rounded-2xl border border-white/[0.08] bg-white/[0.03] sm:grid-cols-2">
              {items.map(({ Icon, title, desc }) => (
                <div
                  key={title}
                  className="group relative bg-graphite-900/60 p-6 transition hover:bg-graphite-800 md:p-7"
                >
                  <div className="flex items-center gap-3">
                    <div className="grid h-10 w-10 place-items-center rounded-md bg-accent-500/10 ring-1 ring-accent-500/30 transition group-hover:bg-accent-500/20">
                      <Icon className="h-5 w-5 text-accent-500" />
                    </div>
                    <h3 className="font-display text-lg font-semibold text-white">{title}</h3>
                  </div>
                  <p className="mt-4 text-sm leading-relaxed text-steel-400">{desc}</p>
                </div>
              ))}
              <div className="hidden bg-gradient-to-br from-accent-500/15 via-graphite-900/60 to-graphite-900/60 p-7 sm:flex sm:flex-col sm:justify-between">
                <span className="font-mono text-[11px] uppercase tracking-[0.2em] text-accent-500">
                  индивидуальные условия
                </span>
                <p className="mt-3 font-display text-2xl font-semibold text-white">
                  Посчитаем заказ с резкой и доставкой до вашего объекта.
                </p>
                <a href="#contact" className="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-accent-500 hover:text-accent-400">
                  Оставить заявку
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
