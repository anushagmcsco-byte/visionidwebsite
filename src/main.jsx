import React, {useEffect, useState} from 'react';
import {createRoot} from 'react-dom/client';
import legacySource from './legacy.html?raw';
import './admin.css';
import {isSupabaseConfigured, supabaseRequest, supabaseUrl, signIn, restoreSession, signOut} from './supabase.js';

const POST_FIELDS='id,title,slug,excerpt,body,category,author,status,feature_image,seo_title,seo_description,canonical_url,created_at,updated_at';
const ENQUIRY_FIELDS='id,kind,name,email,phone,message,fields_json,status,created_at,updated_at';
function LegacySite(){
 useEffect(()=>{
  const doc=new DOMParser().parseFromString(legacySource,'text/html');
  document.title='VisionID Technologies';
  const desc=doc.querySelector('meta[name="description"]');
  if(desc){let m=document.querySelector('meta[name="description"]');if(!m){m=document.createElement('meta');m.name='description';document.head.append(m)}m.content=desc.content}
  doc.querySelectorAll('style,link[rel="stylesheet"],link[rel="preconnect"]').forEach(n=>document.head.append(n.cloneNode(true)));
  const root=document.getElementById('legacy-site');root.innerHTML=doc.body.innerHTML;
  doc.querySelectorAll('script:not([type="application/ld+json"])').forEach(old=>{const s=document.createElement('script');s.textContent=old.textContent;document.body.append(s);s.remove()});
  return()=>{root.innerHTML=''};
 },[]);
 return <div id="legacy-site"/>;
}
const call=(path,session,options={})=>supabaseRequest(path,{...options,token:session?.access_token});
async function verifyAdmin(session){
 const rows=await call(`/rest/v1/admin_users?select=user_id&user_id=eq.${encodeURIComponent(session.user.id)}&limit=1`,session);
 if(!rows?.length)throw new Error('This account is not enabled as a VisionID admin. Add its user ID to public.admin_users in Supabase.');
 return session;
}
function Admin(){
 const [session,setSession]=useState(null),[checking,setChecking]=useState(true),[section,setSection]=useState('posts');
 const [posts,setPosts]=useState([]),[enquiries,setEnquiries]=useState([]),[error,setError]=useState(''),[busy,setBusy]=useState(false),[editing,setEditing]=useState(null);
 const [login,setLogin]=useState({email:'',password:''});
 const load=async(active=session,tab=section)=>{if(!active)return;try{setError('');if(tab==='posts')setPosts(await call(`/rest/v1/blog_posts?select=${POST_FIELDS}&order=updated_at.desc`,active)||[]);else setEnquiries(await call(`/rest/v1/enquiries?select=${ENQUIRY_FIELDS}&order=created_at.desc`,active)||[])}catch(e){setError(e.message)}};
 useEffect(()=>{let alive=true;restoreSession().then(async s=>{if(s){await verifyAdmin(s);if(alive)setSession(s)}}).catch(e=>{signOut();if(alive)setError(e.message)}).finally(()=>{if(alive)setChecking(false)});return()=>{alive=false}},[]);
 useEffect(()=>{if(session)load(session,section)},[section,session]);
 const submitLogin=async e=>{e.preventDefault();setBusy(true);setError('');try{const s=await signIn(login.email,login.password);await verifyAdmin(s);setSession(s)}catch(e){signOut();setError(e.message)}finally{setBusy(false)}};
 const savePost=async e=>{e.preventDefault();setBusy(true);setError('');try{
  const form=new FormData(e.currentTarget),file=form.get('feature_image'),post=Object.fromEntries(form.entries()),id=post.id;delete post.id;delete post.feature_image;
  post.status=form.has('published')?'published':'draft';post.updated_at=new Date().toISOString();post.seo_title ||= post.title;post.seo_description ||= post.excerpt;
  if(file instanceof File&&file.size){if(file.size>5242880)throw new Error('Feature image must be 5 MB or smaller.');if(!['image/jpeg','image/png','image/webp'].includes(file.type))throw new Error('Use JPG, PNG or WebP.');const key=`${crypto.randomUUID()}-${file.name.replace(/[^a-zA-Z0-9._-]/g,'-')}`;await call(`/storage/v1/object/blog-images/${key}`,session,{method:'POST',body:file,headers:{'Content-Type':file.type,'x-upsert':'true'}});post.feature_image=`${supabaseUrl}/storage/v1/object/public/blog-images/${key}`}
  if(id)await call(`/rest/v1/blog_posts?id=eq.${encodeURIComponent(id)}`,session,{method:'PATCH',body:post,headers:{Prefer:'return=minimal'}});else{post.created_at=new Date().toISOString();await call('/rest/v1/blog_posts',session,{method:'POST',body:post,headers:{Prefer:'return=minimal'}})}
  setEditing(null);await load(session,'posts');
 }catch(e){setError(e.message)}finally{setBusy(false)}};
 const deletePost=async id=>{if(!confirm('Delete this post?'))return;try{await call(`/rest/v1/blog_posts?id=eq.${id}`,session,{method:'DELETE'});await load(session,'posts')}catch(e){setError(e.message)}};
 const setEnquiryStatus=async(id,status)=>{try{await call(`/rest/v1/enquiries?id=eq.${id}`,session,{method:'PATCH',body:{status,updated_at:new Date().toISOString()}});await load(session,'enquiries')}catch(e){setError(e.message)}};
 const deleteEnquiry=async id=>{if(!confirm('Delete this enquiry?'))return;try{await call(`/rest/v1/enquiries?id=eq.${id}`,session,{method:'DELETE'});await load(session,'enquiries')}catch(e){setError(e.message)}};
 const logout=async()=>{try{await call('/auth/v1/logout',session,{method:'POST'})}catch{}signOut();setSession(null)};
 if(checking)return <main className="auth"><p className="login">Connecting to Supabase…</p></main>;
 if(!session)return <main className="auth"><form className="login" onSubmit={submitLogin}><a href="/">← VisionID website</a><p className="eyebrow">VisionID CMS</p><h1>Admin sign in</h1><label>Admin email<input type="email" required value={login.email} onChange={e=>setLogin({...login,email:e.target.value})}/></label><label>Password<input type="password" required value={login.password} onChange={e=>setLogin({...login,password:e.target.value})}/></label>{error&&<p className="error">{error}</p>}{!isSupabaseConfigured&&<p className="error">Set the Supabase URL and publishable key in .env.local, then restart Vite.</p>}<button disabled={busy||!isSupabaseConfigured}>{busy?'Signing in…':'Sign in'}</button><small>Sign in using an account created in Supabase Authentication.</small></form></main>;
 return <div className="admin"><aside><a className="brand" href="/">Vision<b>ID</b></a><p>Content workspace</p><button className={section==='posts'?'selected':''} onClick={()=>setSection('posts')}>Blog posts</button><button className={section==='enquiries'?'selected':''} onClick={()=>setSection('enquiries')}>Contact enquiries</button><button className="logout" onClick={logout}>Sign out</button></aside><main><header><div><p className="eyebrow">Dashboard</p><h1>{section==='posts'?'Blog posts':'Contact enquiries'}</h1></div>{section==='posts'&&<button onClick={()=>setEditing({})}>Create post</button>}</header>{error&&<p className="error">{error}</p>}{section==='posts'?<div className="table-wrap"><table><thead><tr><th>Title</th><th>Status</th><th>Updated</th><th/></tr></thead><tbody>{posts.map(p=><tr key={p.id}><td><b>{p.title}</b><small>/{p.slug}</small></td><td>{p.status}</td><td>{p.updated_at}</td><td><button className="text-button" onClick={()=>setEditing(p)}>Edit</button><button className="text-button danger" onClick={()=>deletePost(p.id)}>Delete</button></td></tr>)}</tbody></table>{!posts.length&&<p className="empty">No blog posts yet.</p>}</div>:<div className="table-wrap"><table><thead><tr><th>Received</th><th>Name</th><th>Email</th><th>Type</th><th>Message</th><th>Status</th><th/></tr></thead><tbody>{enquiries.map(q=><tr key={q.id}><td>{new Date(q.created_at).toLocaleString()}</td><td>{q.name}</td><td>{q.email}</td><td>{q.kind}</td><td>{q.message}</td><td><select aria-label="Enquiry status" value={q.status} onChange={e=>setEnquiryStatus(q.id,e.target.value)}><option value="new">New</option><option value="in_progress">In progress</option><option value="closed">Closed</option></select></td><td><button className="text-button danger" onClick={()=>deleteEnquiry(q.id)}>Delete</button></td></tr>)}</tbody></table>{!enquiries.length&&<p className="empty">No enquiries recorded yet.</p>}</div>}</main>{editing&&<div className="modal-backdrop"><form className="editor" onSubmit={savePost}><button type="button" className="close" onClick={()=>setEditing(null)}>×</button><h2>{editing.id?'Edit post':'New blog post'}</h2><input type="hidden" name="id" value={editing.id||''}/><label>Title<input name="title" required defaultValue={editing.title}/></label><label>Slug<input name="slug" required defaultValue={editing.slug}/></label><label>Summary<textarea name="excerpt" required defaultValue={editing.excerpt}/></label><label>Article body (HTML)<textarea name="body" required rows="8" defaultValue={editing.body}/></label><div className="cols"><label>Category<input name="category" defaultValue={editing.category||'Insights'}/></label><label>Author<input name="author" defaultValue={editing.author||'VisionID Team'}/></label></div><label>Feature image<input type="file" name="feature_image" accept="image/jpeg,image/png,image/webp"/></label>{editing.feature_image&&<img className="preview" src={editing.feature_image} alt="Current feature"/>}<label>SEO title<input name="seo_title" maxLength="70" defaultValue={editing.seo_title}/></label><label>SEO description<textarea name="seo_description" maxLength="170" defaultValue={editing.seo_description}/></label><label>Canonical URL<input name="canonical_url" defaultValue={editing.canonical_url}/></label><label className="check"><input type="checkbox" name="published" value="1" defaultChecked={editing.status==='published'}/> Published</label><button disabled={busy}>{busy?'Saving…':'Save post'}</button></form></div>}</div>;
}
function App(){return location.pathname.startsWith('/admin')?<Admin/>:<LegacySite/>}
createRoot(document.getElementById('root')).render(<App/>);