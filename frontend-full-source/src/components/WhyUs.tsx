const projects = [
  {
    title: 'Поставка арматуры и закладных для жилищного строительства',
    role: 'Жилищное строительство · Красноярский край',
    img: 'https://unit-prom.ru/Images/Doma.jpg',
  },
  {
    title: 'Поставка балки и листа для промышленного объекта',
    role: 'Промышленное строительство · Сибирь',
    img: 'https://unit-prom.ru/Images/Zavod1.jpg',
  },
  {
    title: 'Поставка профильных труб и швеллера для логистического объекта',
    role: 'Коммерческое строительство · регион',
    img: 'https://unit-prom.ru/Images/Zavod2.jpg',
  },
];

export function WhyUs() {
  return (
    <section className="relative py-20 md:py-28">
      <div className="container-wide">
        <div className="flex flex-col items-start justify-between gap-6 md:flex-row md:items-end">
          <div className="max-w-2xl">
            <span className="eyebrow">Почему выбирают нас</span>
            <h2 className="mt-5 font-display text-4xl font-semibold text-white md:text-5xl">
              С 2021 года<br className="hidden md:block" /> на рынке металлопроката
            </h2>
          </div>
          <p className="max-w-md text-steel-400">
            Работаем с частными застройщиками, строительными подрядчиками и промышленными предприятиями.
            Осуществляли поставки для крупных региональных заказчиков.
          </p>
        </div>

        <div className="mt-12 grid grid-cols-1 gap-4 md:grid-cols-3">
          {projects.map((p) => (
            <article
              key={p.title}
              className="group relative overflow-hidden rounded-2xl border border-white/[0.08]"
            >
              <div className="aspect-[5/4] overflow-hidden">
                <img
                  src={p.img}
                  alt={p.title}
                  className="h-full w-full object-cover grayscale transition duration-700 group-hover:scale-105 group-hover:grayscale-0"
                />
              </div>
              <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-graphite-950 via-graphite-950/80 to-transparent p-5">
                <span className="font-mono text-[10px] uppercase tracking-[0.2em] text-accent-500">
                  кейс
                </span>
                <h3 className="mt-1 font-display text-lg font-semibold text-white">{p.title}</h3>
                <p className="mt-1 text-xs text-steel-300">{p.role}</p>
              </div>
            </article>
          ))}
        </div>
      </div>
    </section>
  );
}
