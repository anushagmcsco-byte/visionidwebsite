VisionID website: GoDaddy / Supabase deployment
=================================================

Supabase setup
--------------
The app uses Supabase Auth, Postgres and Storage. The browser uses only the project URL and a publishable/anon key; Row Level Security policies in `database/schema.sql` protect admin content.

1. In Supabase Dashboard > Authentication > Users, create the admin user with your email and a strong password. The admin login is email + password.
2. In the Supabase project SQL Editor, run `database/schema.sql`. It creates the blog/enquiry tables, access policies, image bucket, and adds the admin UUID shown in the original project setup. If your admin has a different UUID, replace the UUID at the bottom of the SQL file first. It also imports the original ZIP blog posts, so they are editable in the admin panel.
3. `.env.local` is configured with the Supabase URL and anon key supplied for this project. Keep it out of source control; it is already ignored. If rotating keys, use the project's publishable key.
4. Run `npm install`, then `npm run dev`. Open `http://localhost:5173/admin` and sign in with the Auth email/password from step 2.
5. For GoDaddy, run `npm run build` while the Supabase environment is configured, then upload the CONTENTS of `dist/` to `public_html`. Do not upload `.env.local` itself. The publishable/anon key is embedded into the browser bundle and is protected by RLS.

Contact Us submissions and admin-created blog posts are stored in Supabase. Published posts load from Supabase on the public blog page across devices; the seed file imports the original ZIP articles into the same dashboard. Feature images upload to the public `blog-images` bucket; admin write/delete access is restricted by RLS.
public_html/          -> upload the CONTENTS of this folder into cPanel > File Manager > public_html
  index.html          the website
  news.json           Industry Pulse feed
  .htaccess           HTTPS redirect, security headers, caching (hidden file: enable "Show Hidden Files")
  api/send.php        sends the contact, partner and careers forms to sales@visionid.in and ViDYA leads to kalpana@visionid.in
                      (change recipients at the top of the file, e.g. careers@visionid.in)
  api/geo.php         tells ViDYA the visitor's state so she can offer their language (needs Cloudflare, see below)

pulse/                -> upload to your HOME folder (next to public_html, NOT inside it)
                         Industry Pulse collector; see pulse/README.md. Optional.

Before going live:
 1. In cPanel create the mailbox or forwarder website@visionid.in (used as the sender address).
 2. If your email is on Microsoft 365 or Google (not cPanel email): cPanel > Email Routing > choose
    "Remote Mail Exchanger", or messages to @visionid.in will never leave the server.
 3. cPanel > SSL/TLS Status > run AutoSSL so https://visionid.in works before the HTTPS redirect.

Optional: ViDYA regional language by state
 - Put visionid.in behind Cloudflare (free plan): add the site in Cloudflare, then change the
   nameservers at GoDaddy to the two Cloudflare gives you.
 - In Cloudflare: Rules > Transform Rules > Managed Transforms > turn on "Add visitor location headers".
 - Without this, ViDYA still offers the visitor's language based on their browser language.
