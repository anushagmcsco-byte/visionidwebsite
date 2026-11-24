const projectUrl = (import.meta.env.VITE_SUPABASE_URL || '').replace(/\/$/, '');
const publishableKey = import.meta.env.VITE_SUPABASE_PUBLISHABLE_KEY || import.meta.env.VITE_SUPABASE_ANON_KEY || '';
export const isSupabaseConfigured = Boolean(projectUrl && publishableKey);
export const supabaseUrl = projectUrl;

export async function supabaseRequest(path, { token, method = 'GET', body, headers = {} } = {}) {
  if (!isSupabaseConfigured) throw new Error('Supabase is not configured. Set VITE_SUPABASE_URL and VITE_SUPABASE_PUBLISHABLE_KEY in the deployment environment, or in .env.local for local Vite.');
  const h = new Headers({ apikey: publishableKey, ...headers });
  h.set('Authorization', `Bearer ${token || publishableKey}`);
  let payload = body;
  if (body !== undefined && !(body instanceof Blob) && !(body instanceof FormData)) {
    h.set('Content-Type', 'application/json'); payload = JSON.stringify(body);
  }
  const response = await fetch(projectUrl + path, { method, headers: h, body: payload });
  const raw = await response.text();
  let data = null;
  try { data = raw ? JSON.parse(raw) : null; } catch { data = raw; }
  if (!response.ok) throw new Error(data?.msg || data?.message || data?.error_description || data?.error || `Supabase error (${response.status})`);
  return data;
}

export async function signIn(email, password) {
  const session = await supabaseRequest('/auth/v1/token?grant_type=password', { method: 'POST', body: { email, password } });
  session.expires_at ||= Math.floor(Date.now() / 1000) + session.expires_in;
  localStorage.setItem('visionid_supabase_session', JSON.stringify(session));
  return session;
}

export async function restoreSession() {
  try {
    const old = JSON.parse(localStorage.getItem('visionid_supabase_session') || 'null');
    if (!old) return null;
    if (old.expires_at > Math.floor(Date.now() / 1000) + 30) return old;
    const next = await supabaseRequest('/auth/v1/token?grant_type=refresh_token', { method: 'POST', body: { refresh_token: old.refresh_token } });
    next.expires_at ||= Math.floor(Date.now() / 1000) + next.expires_in;
    localStorage.setItem('visionid_supabase_session', JSON.stringify(next));
    return next;
  } catch { localStorage.removeItem('visionid_supabase_session'); return null; }
}

export function signOut() { localStorage.removeItem('visionid_supabase_session'); }

window.visionidSupabase = {
  configured: isSupabaseConfigured,
  async listPublishedBlogs() {
    const rows = await supabaseRequest('/rest/v1/blog_posts?select=id,title,slug,excerpt,body,category,author,status,feature_image,seo_title,seo_description,canonical_url,created_at&status=eq.published&order=created_at.desc');
    return rows.map(post => ({...post, date: post.created_at?.slice(0, 10)}));
  },
  saveEnquiry: (kind, fields) => supabaseRequest('/rest/v1/enquiries', {
    method: 'POST', headers: { Prefer: 'return=minimal' },
    body: { kind, name: fields.Name || '', email: fields.Email || '', phone: fields.Mobile || '', message: fields.Message || fields.Note || JSON.stringify(fields), fields_json: fields },
  }),
};
