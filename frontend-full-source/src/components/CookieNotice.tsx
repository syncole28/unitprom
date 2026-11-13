import { useEffect, useState } from 'react';

const COOKIE_NOTICE_KEY = 'unitprom_cookie_notice';

// Уведомление об использовании cookie (Яндекс Метрика). Скрывается навсегда после «Понятно».
export function CookieNotice() {
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    try {
      if (!localStorage.getItem(COOKIE_NOTICE_KEY)) setVisible(true);
    } catch {
      setVisible(true);
    }
  }, []);

  function accept() {
    try {
      localStorage.setItem(COOKIE_NOTICE_KEY, '1');
    } catch {
      // без localStorage уведомление просто покажется снова при следующем визите
    }
    setVisible(false);
  }

  if (!visible) return null;

  return (
    <div className="fixed bottom-4 left-4 right-4 z-40 sm:right-auto sm:max-w-sm">
      <div className="flex items-start gap-4 rounded-lg border border-white/10 bg-graphite-900/95 p-4 text-[12px] leading-relaxed text-steel-300 shadow-2xl backdrop-blur">
        <p>
          Сайт использует cookie и сервис Яндекс Метрика для статистики посещений. Продолжая пользоваться
          сайтом, вы соглашаетесь с этим. Подробнее в{' '}
          <a href="/privacy/" className="text-accent-400 hover:underline">
            политике обработки персональных данных
          </a>
          .
        </p>
        <button type="button" onClick={accept} className="btn-primary shrink-0 px-4 py-2 text-xs">
          Понятно
        </button>
      </div>
    </div>
  );
}
