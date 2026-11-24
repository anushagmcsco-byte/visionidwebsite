import fs from 'node:fs';
import vm from 'node:vm';
const source = fs.readFileSync('src/legacy.html', 'utf8');
const start = source.indexOf('let POSTS = [');
const end = source.indexOf('\n];', start) + 2;
if (start < 0 || end < 2) throw new Error('Could not locate original ZIP blog list');
const posts = vm.runInNewContext('(' + source.slice(start + 'let POSTS = '.length, end) + ')');
const literal = value => "'" + String(value ?? '').replace(/'/g, "''") + "'";
const sql = ['-- Import the original ZIP blog posts into Supabase once. Safe to re-run.'];
for (const post of posts) {
  const timestamp = new Date(`${post.date}T12:00:00+05:30`).toISOString();
  const values = [post.title,post.slug,post.ex,post.body,post.cat || 'Insights','VisionID Team','published','',post.title,post.ex,timestamp,timestamp].map(literal);
  sql.push(`insert into public.blog_posts(title,slug,excerpt,body,category,author,status,feature_image,seo_title,seo_description,created_at,updated_at) values (${values.join(',')}) on conflict (slug) do nothing;`);
}
fs.writeFileSync('database/seed-blogs.sql', sql.join('\n') + '\n', 'utf8');
console.log(`Prepared ${posts.length} original blog posts for Supabase.`);