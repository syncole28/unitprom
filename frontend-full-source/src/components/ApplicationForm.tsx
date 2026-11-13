import { useState, useRef } from 'react';
import { Paperclip, Phone, Mail, MapPin, Clock, CheckCircle2, AlertCircle, Send, Trash2, Pencil, Plus } from 'lucide-react';
import { CutDiscIcon } from './CutDiscIcon';
import { submitApplication } from '../lib/api';
import { ymGoal, ymEcommercePurchase } from '../lib/ym';
import type { CartItem } from '../App';
import { itemQtyText, itemEcommerce } from '../lib/cartFormat';

const RATE_LIMIT_KEY = 'smg_last_submit';
const RATE_LIMIT_MS = 60_000;

export function ApplicationForm({
  cartItems,
  onRemoveItem,
  onEditItem,
  onAddMore,
  onClear,
}: {
  cartItems: CartItem[];
  onRemoveItem: (id: string) => void;
  onEditItem: (item: CartItem) => void;
  onAddMore: () => void;
  onClear: () => void;
}) {
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [email, setEmail] = useState('');
  const [org, setOrg] = useState('');
  const [comment, setComment] = useState('');
  const [fileName, setFileName] = useState('');
  const [honeypot, setHoneypot] = useState('');
  const [consent, setConsent] = useState(false);
  const fileInput = useRef<HTMLInputElement>(null);
  const [submitting, setSubmitting] = useState(false);
  const [status, setStatus] = useState<'idle' | 'ok' | 'err' | 'rate'>('idle');
  const [errMsg, setErrMsg] = useState('');

  const cartTotal = cartItems.reduce((sum, i) => sum + i.totalPrice, 0);

  function handleFile(e: React.ChangeEvent<HTMLInputElement>) {
    const f = e.target.files?.[0];
    setFileName(f ? f.name : '');
    if (f) ymGoal('file_attach');
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();

    // Client-side rate limit
    const lastTs = Number(localStorage.getItem(RATE_LIMIT_KEY) ?? 0);
    if (Date.now() - lastTs < RATE_LIMIT_MS) {
      setStatus('rate');
      return;
    }

    if (!name.trim() || !phone.trim()) {
      setStatus('err');
      setErrMsg('Имя и телефон обязательны');
      return;
    }

    if (!consent) {
      setStatus('err');
      setErrMsg('Отметьте согласие на обработку персональных данных');
      return;
    }

    setSubmitting(true);
    setStatus('idle');
    setErrMsg('');

    // Honeypot triggered — fake success silently
    if (honeypot.trim() !== '') {
      setSubmitting(false);
      setStatus('ok');
      localStorage.setItem(RATE_LIMIT_KEY, String(Date.now()));
      resetForm();
      return;
    }

    const fd = new FormData();
    fd.append('name', name.trim());
    fd.append('phone', phone.trim());
    fd.append('email', email.trim());
    fd.append('org', org.trim());
    fd.append('comment', comment.trim());
    fd.append('consent', '1');
    if (cartItems.length > 0) {
      const payload = cartItems.map(({ id: _id, ...rest }) => rest);
      fd.append('calc_payload', JSON.stringify(payload));
    }
    const file = fileInput.current?.files?.[0];
    if (file) fd.append('file', file);

    try {
      const res = await submitApplication(fd);
      localStorage.setItem(RATE_LIMIT_KEY, String(Date.now()));
      ymGoal('application_submit');
      if (cartItems.length > 0) {
        const total = cartItems.reduce((sum, i) => sum + i.totalPrice, 0);
        ymEcommercePurchase(
          res.id || String(Date.now()),
          cartItems.map((i) => ({
            id: i.id,
            name: i.productLabel,
            ...itemEcommerce(i),
          })),
          total,
        );
      }
      setStatus('ok');
      resetForm();
    } catch {
      setStatus('err');
      setErrMsg('Не удалось отправить заявку. Попробуйте позже или позвоните нам.');
    } finally {
      setSubmitting(false);
    }
  }

  function resetForm() {
    setName('');
    setPhone('');
    setEmail('');
    setOrg('');
    setComment('');
    setFileName('');
    setConsent(false);
    if (fileInput.current) fileInput.current.value = '';
    onClear();
  }

  return (
    <section id="contact" className="relative py-20 md:py-28">
      <div className="absolute inset-0 -z-10">
        <div className="absolute bottom-0 left-0 h-[300px] w-[500px] rounded-full bg-accent-500/10 blur-[120px]" />
      </div>

      <div className="container-wide">
        <div className="grid grid-cols-1 gap-10 lg:grid-cols-12">
          <div className="lg:col-span-5">
            <span className="eyebrow">Заявка на расчёт</span>
            <h2 className="mt-5 font-display text-4xl font-semibold text-white md:text-5xl">
              Пришлём коммерческое предложение с доставкой до объекта
            </h2>
            <p className="mt-4 text-steel-300">
              Оставьте контакты или прикрепите спецификацию — менеджер пришлёт расчёт с учётом
              доставки, разгрузки и условий оплаты.
            </p>

            <ul className="mt-10 space-y-4">
              {[
                { Icon: Phone, t: '+7 (391) 214-00-00', s: 'отдел продаж · пн–пт 9:00–18:00', href: 'tel:+73912140000' },
                { Icon: Mail, t: 'sales@sibmetal.group', s: 'заявки и спецификации', href: 'mailto:sales@sibmetal.group' },
                { Icon: MapPin, t: 'Красноярск, Свердловская, 15/4', s: 'офис и склад · самовывоз', href: null },
                { Icon: Clock, t: 'Быстрая отгрузка', s: 'после оплаты · резка в размер', href: null },
              ].map(({ Icon, t, s, href }) => (
                <li key={t} className="flex items-start gap-4">
                  <div className="grid h-11 w-11 shrink-0 place-items-center rounded-md border border-white/[0.08] bg-white/[0.03]">
                    <Icon className="h-4.5 w-4.5 text-accent-500" />
                  </div>
                  <div>
                    {href ? (
                      <a
                        href={href}
                        onClick={() => ymGoal('contact_click')}
                        className="font-display text-base font-semibold text-white hover:text-accent-500"
                      >
                        {t}
                      </a>
                    ) : (
                      <div className="font-display text-base font-semibold text-white">{t}</div>
                    )}
                    <div className="text-[12px] text-steel-400">{s}</div>
                  </div>
                </li>
              ))}
            </ul>
          </div>

          <div className="lg:col-span-7">
            <form
              onSubmit={handleSubmit}
              className="glass-panel relative overflow-hidden p-6 shadow-panel md:p-8"
            >
              <div className="absolute -top-32 -right-24 h-64 w-64 rounded-full bg-accent-500/10 blur-3xl" />
              <div className="relative">
                {cartItems.length > 0 && (
                  <div className="mb-6 overflow-hidden rounded-lg border border-accent-500/30 bg-accent-500/10">
                    <div className="divide-y divide-accent-500/15">
                      {cartItems.map((item) => (
                        <div
                          key={item.id}
                          role="button"
                          tabIndex={0}
                          title="Изменить количество"
                          onClick={() => onEditItem(item)}
                          onKeyDown={(e) => {
                            if (e.target === e.currentTarget && (e.key === 'Enter' || e.key === ' ')) {
                              e.preventDefault();
                              onEditItem(item);
                            }
                          }}
                          className="group/item flex cursor-pointer items-start justify-between gap-3 px-4 py-2.5 transition hover:bg-accent-500/10 focus-visible:bg-accent-500/10 focus-visible:outline-none"
                        >
                          <div className="min-w-0 text-sm text-accent-300">
                            <div className="line-clamp-2 font-semibold leading-snug group-hover/item:text-white" title={item.productLabel}>{item.productLabel}</div>
                            <div className="text-[12px]">
                              {itemQtyText(item)} · {item.weightKg.toFixed(1)} кг ·{' '}
                              {item.totalPrice.toLocaleString('ru-RU', { maximumFractionDigits: 0 })} ₽
                            </div>
                            {item.cut && (
                              <div className="mt-0.5 flex items-start gap-1.5 text-[12px] text-white/80">
                                <CutDiscIcon className="mt-[3px] h-3 w-3 shrink-0" />
                                <span className="line-clamp-2">{item.cut.text}</span>
                              </div>
                            )}
                          </div>
                          <div className="flex shrink-0 flex-col items-center gap-2">
                            <button
                              type="button"
                              onClick={(e) => { e.stopPropagation(); ymGoal('cart_remove'); onRemoveItem(item.id); }}
                              className="text-accent-300/70 hover:text-red-400"
                              aria-label="Убрать позицию"
                            >
                              <Trash2 className="h-4 w-4" />
                            </button>
                            <button
                              type="button"
                              onClick={(e) => { e.stopPropagation(); onEditItem(item); }}
                              className="text-accent-300/70 hover:text-white group-hover/item:text-white"
                              aria-label="Изменить количество"
                            >
                              <Pencil className="h-4 w-4" />
                            </button>
                          </div>
                        </div>
                      ))}
                      <div className="px-4 py-2.5">
                        <button
                          type="button"
                          onClick={() => { ymGoal('cart_add_more'); onAddMore(); }}
                          className="flex min-h-[72px] w-full items-center justify-center gap-2 rounded-md border border-dashed border-accent-500/40 text-sm font-medium text-accent-300 transition hover:border-accent-500/70 hover:bg-accent-500/10 hover:text-white focus-visible:border-accent-500/70 focus-visible:outline-none"
                        >
                          <Plus className="h-5 w-5" />
                          Добавить товар
                        </button>
                      </div>
                    </div>
                    <div className="border-t border-accent-500/15 px-4 py-2.5">
                      <div className="flex items-center justify-between text-sm">
                        <span className="text-accent-300/80">Предварительная стоимость</span>
                        <span className="font-display font-semibold text-white">
                          ~{cartTotal.toLocaleString('ru-RU', { maximumFractionDigits: 0 })} ₽
                        </span>
                      </div>
                      <div className="mt-0.5 text-[11px] text-accent-300/60">
                        окончательная цена — после согласования с менеджером
                      </div>
                    </div>
                  </div>
                )}
                {cartItems.length === 0 && (
                  <button
                    type="button"
                    onClick={() => { ymGoal('cart_add_more'); onAddMore(); }}
                    className="mb-6 flex min-h-[60px] w-full items-center justify-center gap-2 rounded-lg border border-dashed border-accent-500/40 bg-accent-500/[0.04] text-sm font-medium text-accent-300 transition hover:border-accent-500/70 hover:bg-accent-500/10 hover:text-white focus-visible:border-accent-500/70 focus-visible:outline-none"
                  >
                    <Plus className="h-5 w-5" />
                    Выбрать товары в каталоге
                  </button>
                )}

                {/* Honeypot — hidden from real users, visible to bots */}
                <div
                  aria-hidden="true"
                  style={{
                    position: 'absolute',
                    width: '1px',
                    height: '1px',
                    padding: 0,
                    margin: '-1px',
                    overflow: 'hidden',
                    clip: 'rect(0,0,0,0)',
                    whiteSpace: 'nowrap',
                    borderWidth: 0,
                  }}
                >
                  <label htmlFor="hp-website">Website</label>
                  <input
                    id="hp-website"
                    type="text"
                    name="website"
                    tabIndex={-1}
                    autoComplete="off"
                    value={honeypot}
                    onChange={(e) => setHoneypot(e.target.value)}
                  />
                </div>

                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                  <div className="md:col-span-1">
                    <label className="mb-2 block text-xs font-medium uppercase tracking-wider text-steel-400">
                      Имя <span className="text-accent-500">*</span>
                    </label>
                    <input
                      type="text"
                      value={name}
                      onChange={(e) => setName(e.target.value)}
                      placeholder="Иван Петров"
                      className="field"
                      required
                    />
                  </div>
                  <div>
                    <label className="mb-2 block text-xs font-medium uppercase tracking-wider text-steel-400">
                      Телефон <span className="text-accent-500">*</span>
                    </label>
                    <input
                      type="tel"
                      value={phone}
                      onChange={(e) => setPhone(e.target.value)}
                      placeholder="+7 (___) ___-__-__"
                      className="field font-mono"
                      required
                    />
                  </div>
                  <div>
                    <label className="mb-2 block text-xs font-medium uppercase tracking-wider text-steel-400">
                      Email
                    </label>
                    <input
                      type="email"
                      value={email}
                      onChange={(e) => setEmail(e.target.value)}
                      placeholder="zakupki@company.ru"
                      className="field"
                    />
                  </div>
                  <div>
                    <label className="mb-2 block text-xs font-medium uppercase tracking-wider text-steel-400">
                      Организация или ИНН
                    </label>
                    <input
                      type="text"
                      value={org}
                      onChange={(e) => setOrg(e.target.value)}
                      placeholder="ООО «Компания» или 2460000000"
                      maxLength={255}
                      autoComplete="organization"
                      className="field"
                    />
                  </div>
                  <div className="md:col-span-2">
                    <label className="mb-2 block text-xs font-medium uppercase tracking-wider text-steel-400">
                      Комментарий / спецификация
                    </label>
                    <textarea
                      value={comment}
                      onChange={(e) => setComment(e.target.value)}
                      placeholder="Опишите позиции и объёмы, или вставьте список из спецификации..."
                      rows={5}
                      className="field resize-none"
                    />
                  </div>
                  <div className="md:col-span-2">
                    <label
                      htmlFor="spec-file"
                      className="flex cursor-pointer items-center justify-between gap-3 rounded-md border border-dashed border-white/15 bg-white/[0.02] px-4 py-3.5 text-sm text-steel-300 transition hover:border-accent-500/40 hover:bg-white/[0.04]"
                    >
                      <span className="flex items-center gap-3">
                        <Paperclip className="h-4 w-4 text-accent-500" />
                        {fileName ? (
                          <span className="truncate text-white">{fileName}</span>
                        ) : (
                          <span>Прикрепить спецификацию (PDF, XLSX, DOCX)</span>
                        )}
                      </span>
                      <span className="text-[11px] uppercase tracking-wider text-steel-500">
                        обзор
                      </span>
                    </label>
                    <input
                      ref={fileInput}
                      id="spec-file"
                      type="file"
                      onChange={handleFile}
                      className="hidden"
                      accept=".pdf,.xlsx,.xls,.docx,.doc,.csv,.txt"
                    />
                  </div>
                </div>

                <div className="mt-7 flex flex-col-reverse items-stretch gap-4 sm:flex-row sm:items-center sm:justify-between">
                  <label className="flex cursor-pointer items-start gap-3 text-[12px] leading-relaxed text-steel-400">
                    <input
                      type="checkbox"
                      checked={consent}
                      onChange={(e) => setConsent(e.target.checked)}
                      required
                      className="mt-0.5 h-4 w-4 shrink-0 cursor-pointer accent-accent-500"
                    />
                    <span>
                      Я даю{' '}
                      <a href="/soglasie/" target="_blank" className="text-steel-300 underline decoration-white/20 underline-offset-2 hover:text-accent-500">
                        согласие на обработку персональных данных
                      </a>{' '}
                      и ознакомлен с{' '}
                      <a href="/privacy/" target="_blank" className="text-steel-300 underline decoration-white/20 underline-offset-2 hover:text-accent-500">
                        политикой обработки персональных данных
                      </a>
                    </span>
                  </label>
                  <button type="submit" className="btn-primary" disabled={submitting}>
                    {submitting ? 'Отправляем...' : 'Отправить заявку'}
                    <Send className="h-4 w-4" />
                  </button>
                </div>

                {status === 'ok' && (
                  <div className="mt-5 flex items-start gap-3 rounded-md border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300">
                    <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />
                    <div>
                      <div className="font-semibold">Заявка принята</div>
                      <div className="text-[12px] text-emerald-200/80">
                        Менеджер свяжется с вами в рабочее время.
                      </div>
                    </div>
                  </div>
                )}
                {status === 'rate' && (
                  <div className="mt-5 flex items-start gap-3 rounded-md border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-300">
                    <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />
                    <div>Вы уже отправили заявку, мы скоро свяжемся.</div>
                  </div>
                )}
                {status === 'err' && (
                  <div className="mt-5 flex items-start gap-3 rounded-md border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                    <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
                    <div>{errMsg}</div>
                  </div>
                )}
              </div>
            </form>
          </div>
        </div>
      </div>
    </section>
  );
}
