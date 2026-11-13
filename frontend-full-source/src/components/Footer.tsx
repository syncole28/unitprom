import { ymGoal } from '../lib/ym';

export function Footer() {
  return (
    <footer className="border-t border-white/[0.06] bg-graphite-950">
      <div className="container-wide grid grid-cols-2 gap-8 py-14 md:grid-cols-4">
        <div className="col-span-2">
          <div className="flex items-center gap-3">
            <div className="grid h-10 w-10 place-items-center rounded-md bg-accent-500/10 ring-1 ring-accent-500/40">
              <svg viewBox="0 0 32 32" className="h-5 w-5 text-accent-500" fill="none">
                <path
                  d="M7 22V10l5 6 4-6 4 6 5-6v12"
                  stroke="currentColor"
                  strokeWidth="2.4"
                  strokeLinejoin="round"
                />
              </svg>
            </div>
            <div>
              <div className="font-display text-base font-semibold text-white">Юнитпром</div>
              <div className="font-mono text-[10px] uppercase tracking-[0.18em] text-steel-500">
                поставки металлопроката
              </div>
            </div>
          </div>
          <p className="mt-5 max-w-md text-sm text-steel-400">
            Поставка чёрного и оцинкованного металлопроката оптом и в розницу. Склад в Красноярске,
            резка в размер, доставка по краю и соседним регионам.
          </p>
        </div>

        <div>
          <h4 className="font-mono text-[11px] uppercase tracking-[0.2em] text-steel-500">
            Каталог
          </h4>
          <ul className="mt-4 space-y-2 text-sm text-steel-300">
            <li><a href="#catalog" className="hover:text-accent-500">Арматура</a></li>
            <li><a href="#catalog" className="hover:text-accent-500">Трубы</a></li>
            <li><a href="#catalog" className="hover:text-accent-500">Лист</a></li>
            <li><a href="#catalog" className="hover:text-accent-500">Уголок</a></li>
            <li><a href="#catalog" className="hover:text-accent-500">Швеллер · Балка</a></li>
          </ul>
        </div>

        <div>
          <h4 className="font-mono text-[11px] uppercase tracking-[0.2em] text-steel-500">
            Контакты
          </h4>
          <ul className="mt-4 space-y-2 text-sm">
            <li>
              <a href="tel:+73912140000" onClick={() => ymGoal('contact_click')} className="font-mono font-semibold text-white hover:text-accent-500">
                +7 (391) 214-00-00
              </a>
            </li>
            <li>
              <a href="mailto:sales@sibmetal.group" onClick={() => ymGoal('contact_click')} className="text-steel-300 hover:text-accent-500">
                sales@sibmetal.group
              </a>
            </li>
            <li className="text-steel-300">Красноярск, Свердловская, 15/4</li>
            <li className="text-steel-500">пн–пт · 9:00–18:00</li>
          </ul>
        </div>
      </div>

      <div className="border-t border-white/[0.06]">
        <div className="container-wide flex flex-col items-start justify-between gap-3 py-6 md:flex-row md:items-center">
          <div className="text-[11px] text-steel-500">
            © {new Date().getFullYear()} ООО «Юнитпром». ИНН 2463125441. Все права защищены.
          </div>
          <div className="flex flex-wrap gap-x-5 gap-y-2 text-[11px] text-steel-500">
            <a href="/privacy/" className="hover:text-accent-500">Политика обработки персональных данных</a>
            <a href="/soglasie/" className="hover:text-accent-500">Согласие на обработку персональных данных</a>
          </div>
        </div>
      </div>
    </footer>
  );
}
