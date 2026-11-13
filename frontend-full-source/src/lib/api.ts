// Клиент для собственного PHP-бэкенда (заменяет Supabase).
// VITE_API_URL можно оставить относительным ("/api") — тогда сборка
// работает на любом домене без пересборки под конкретный хост.
const API_BASE = (import.meta.env.VITE_API_URL as string) || '/api';

export type CategoryRow = Record<string, unknown>;
export type ProductRow = Record<string, unknown>;

export async function fetchCatalog(): Promise<{
  categories: CategoryRow[];
  products: ProductRow[];
  featured: Record<string, unknown>[];
}> {
  const res = await fetch(`${API_BASE}/catalog.php`);
  if (!res.ok) throw new Error('Failed to load catalog');
  return res.json();
}

export async function submitApplication(formData: FormData): Promise<{ ok: boolean; id?: string }> {
  const res = await fetch(`${API_BASE}/applications.php`, {
    method: 'POST',
    body: formData,
  });
  if (!res.ok) {
    const data = await res.json().catch(() => ({}) as { error?: string });
    throw new Error(data.error || 'Failed to submit application');
  }
  return res.json();
}
