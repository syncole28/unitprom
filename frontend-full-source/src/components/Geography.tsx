import { MapPin } from 'lucide-react';
import { MAP_CITIES, MAP_KRAI, MAP_LABELS, MAP_NEIGHBOURS, MAP_NORILSK_X } from './geographyMap';

const cities = [
  { name: 'Красноярск', role: 'Офис и склад' },
  { name: 'Норильск', role: '' },
  { name: 'Ачинск', role: '' },
  { name: 'Канск', role: '' },
  { name: 'Минусинск', role: '' },
  { name: 'Лесосибирск', role: '' },
  { name: 'Зеленогорск', role: '' },
];

// Подписи городов на карте: смещение текста от точки, чтобы близкие города не наезжали друг на друга.
const labelPos: Record<string, { dx: number; dy: number; anchor: 'start' | 'end' | 'middle' }> = {
  Красноярск: { dx: -4, dy: 22, anchor: 'middle' },
  Ачинск: { dx: -10, dy: 5, anchor: 'end' },
  Канск: { dx: 10, dy: 5, anchor: 'start' },
  Зеленогорск: { dx: 0, dy: -12, anchor: 'middle' },
  Минусинск: { dx: 10, dy: 5, anchor: 'start' },
  Лесосибирск: { dx: 10, dy: 5, anchor: 'start' },
};

export function Geography() {
  return (
    <section id="geography" className="relative border-y border-white/[0.06] bg-graphite-900/40 py-20 md:py-28">
      <div className="container-wide">
        <div className="grid grid-cols-1 gap-10 lg:grid-cols-12">
          <div className="lg:col-span-4">
            <span className="eyebrow">География работы</span>
            <h2 className="mt-5 font-display text-3xl font-semibold text-white md:text-4xl">
              Поставки по всему Красноярскому краю
            </h2>
            <p className="mt-4 text-steel-400">
              Доставка манипулятором, длинномером, тралом — подберём транспорт под объём и условия
              разгрузки. Возим по Красноярску, всему краю и в соседние регионы: Хакасию, Тыву,
              Иркутскую область.
            </p>

            <ul className="mt-7 grid grid-cols-2 gap-2">
              {cities.map((c) => (
                <li
                  key={c.name}
                  className="flex items-start gap-2 rounded-md border border-white/[0.06] bg-white/[0.02] p-3"
                >
                  <MapPin className="mt-0.5 h-3.5 w-3.5 shrink-0 text-accent-500" />
                  <div>
                    <div className="text-sm font-semibold text-white">{c.name}</div>
                    {c.role && <div className="text-[11px] text-steel-500">{c.role}</div>}
                  </div>
                </li>
              ))}
            </ul>
          </div>

          <div className="lg:col-span-8">
            <div className="relative aspect-[5/4] overflow-hidden rounded-2xl border border-white/[0.08] bg-gradient-to-br from-graphite-900 to-graphite-950 md:aspect-[16/10]">
              <div className="absolute inset-0 hero-grid opacity-40" />

              <svg
                viewBox="0 0 800 500"
                className="absolute inset-0 h-full w-full"
                preserveAspectRatio="xMidYMid slice"
                role="img"
                aria-label="Карта доставки: Красноярский край и соседние регионы"
              >
                <defs>
                  <linearGradient id="krai-fill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="#F2611B" stopOpacity="0.05" />
                    <stop offset="100%" stopColor="#F2611B" stopOpacity="0.2" />
                  </linearGradient>
                </defs>

                {MAP_NEIGHBOURS.map((d, i) => (
                  <path
                    key={i}
                    d={d}
                    fill="#ffffff"
                    fillOpacity="0.025"
                    stroke="#ffffff"
                    strokeOpacity="0.14"
                    strokeWidth="1"
                    strokeLinejoin="round"
                  />
                ))}
                <path
                  d={MAP_KRAI}
                  fill="url(#krai-fill)"
                  stroke="#F2611B"
                  strokeOpacity="0.75"
                  strokeWidth="1.4"
                  strokeLinejoin="round"
                />

                {MAP_LABELS.map((l) => (
                  <text
                    key={l.name}
                    x={l.x}
                    y={l.y}
                    textAnchor="middle"
                    fill={l.name === 'Красноярский край' ? '#F2611B' : '#8b95a5'}
                    fillOpacity={l.name === 'Красноярский край' ? 0.85 : 0.9}
                    fontSize="13"
                    fontWeight="600"
                    letterSpacing="2"
                    style={{ textTransform: 'uppercase' }}
                  >
                    {l.name}
                  </text>
                ))}

                {/* Норильск: за верхним краем карты */}
                <g transform={`translate(${MAP_NORILSK_X}, 22)`}>
                  <path d="M0,-8 L6,2 L-6,2 Z" fill="#F2611B" />
                  <text x="12" y="3" fill="#ffffff" fontSize="13" fontWeight="600">
                    Норильск
                  </text>
                </g>

                {Object.entries(MAP_CITIES).map(([name, [x, y]], i) => {
                  const lp = labelPos[name];
                  const main = name === 'Красноярск';
                  return (
                    <g key={name}>
                      <circle cx={x} cy={y} r={main ? 14 : 10} fill="#F2611B" fillOpacity="0.18">
                        <animate attributeName="r" values={main ? '8;16;8' : '6;11;6'} dur="2.4s" begin={`${i * 0.3}s`} repeatCount="indefinite" />
                      </circle>
                      <circle cx={x} cy={y} r={main ? 6 : 4} fill="#F2611B" stroke="#14171c" strokeWidth="1.5" />
                      <text
                        x={x + lp.dx}
                        y={y + lp.dy}
                        textAnchor={lp.anchor}
                        fill="#ffffff"
                        fontSize={main ? 15 : 13}
                        fontWeight={main ? 700 : 500}
                        paintOrder="stroke"
                        stroke="#14171c"
                        strokeWidth="3"
                      >
                        {name}
                      </text>
                    </g>
                  );
                })}
              </svg>

              <div className="absolute bottom-4 left-4 right-4 flex items-center justify-between gap-3 rounded-xl border border-white/[0.08] bg-graphite-950/70 px-4 py-3 backdrop-blur">
                <div className="text-[11px] uppercase tracking-wider text-steel-500">
                  Зона доставки
                </div>
                <div className="font-display text-sm font-semibold text-white">
                  Красноярский край · Хакасия · Тыва · Иркутская обл.
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
