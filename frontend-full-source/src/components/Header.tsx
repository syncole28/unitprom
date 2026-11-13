import { Phone, Menu, X, MapPin } from 'lucide-react';
import { useState } from 'react';
import { ymGoal } from '../lib/ym';

const nav = [
  { href: '#catalog', label: 'Каталог' },
  { href: '#calculator', label: 'Калькулятор' },
  { href: '#advantages', label: 'Преимущества' },
  { href: '#geography', label: 'География' },
  { href: '#contact', label: 'Контакты' },
];

export function Header() {
  const [open, setOpen] = useState(false);

  return (
    <header className="sticky top-0 z-50 border-b border-white/[0.06] bg-graphite-950/80 backdrop-blur-xl">
      <div className="container-wide flex h-16 items-center justify-between md:h-20">
        <a href="#" className="group flex items-center gap-3">
          <div className="grid h-10 w-10 place-items-center rounded-md bg-accent-500/10 ring-1 ring-accent-500/40 transition group-hover:bg-accent-500/15">
            <svg viewBox="0 0 32 32" className="h-5 w-5 text-accent-500" fill="none">
              <path d="M7 22V10l5 6 4-6 4 6 5-6v12" stroke="currentColor" strokeWidth="2.4" strokeLinejoin="round" />
            </svg>
          </div>
          <div className="leading-tight">
            <div className="font-display text-base font-semibold text-white">Юнитпром</div>
            <div className="font-mono text-[10px] uppercase tracking-[0.18em] text-steel-500">
              металлопрокат · красноярск
            </div>
          </div>
        </a>

        <nav className="hidden items-center gap-1 lg:flex">
          {nav.map((item) => (
            <a
              key={item.href}
              href={item.href}
              className="rounded-md px-3.5 py-2 text-sm text-steel-300 transition hover:bg-white/[0.04] hover:text-white"
            >
              {item.label}
            </a>
          ))}
        </nav>

        <div className="flex items-center gap-3">
          <div className="hidden text-right md:block">
            <a
              href="tel:+73912140000"
              onClick={() => ymGoal('contact_click')}
              className="block font-mono text-sm font-semibold text-white tracking-wide hover:text-accent-500"
            >
              +7 (391) 214-00-00
            </a>
            <div className="flex items-center justify-end gap-1 text-[11px] text-steel-500">
              <MapPin className="h-3 w-3" /> Красноярск, Свердловская, 15/4
            </div>
          </div>
          <a href="#contact" className="hidden btn-primary md:inline-flex !px-4 !py-2.5 !text-xs">
            <Phone className="h-3.5 w-3.5" />
            Заявка
          </a>
          <button
            type="button"
            onClick={() => setOpen((v) => !v)}
            className="grid h-10 w-10 place-items-center rounded-md border border-white/10 text-white lg:hidden"
            aria-label="Меню"
          >
            {open ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
          </button>
        </div>
      </div>

      {open && (
        <div className="border-t border-white/[0.06] bg-graphite-900 lg:hidden">
          <div className="container-wide flex flex-col gap-1 py-4">
            {nav.map((item) => (
              <a
                key={item.href}
                href={item.href}
                onClick={() => setOpen(false)}
                className="rounded-md px-3 py-2.5 text-sm text-steel-200 hover:bg-white/5"
              >
                {item.label}
              </a>
            ))}
            <a
              href="tel:+73912140000"
              onClick={() => ymGoal('contact_click')}
              className="mt-2 rounded-md bg-white/5 px-3 py-2.5 font-mono text-sm font-semibold text-white"
            >
              +7 (391) 214-00-00
            </a>
          </div>
        </div>
      )}
    </header>
  );
}
