<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
function out(int $status, array $data): never { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function body(): array { $x=json_decode((string)file_get_contents('php://input'),true); return is_array($x)?$x:[]; }
function slugify(string $s): string { $s=strtolower(trim($s));$s=preg_replace('/[^a-z0-9]+/','-',$s)??'';return trim($s,'-'); }
function clean_text($s,int $max=500): string { return mb_substr(trim(strip_tags((string)$s)),0,$max); }
try {
 $cfg=require __DIR__.'/config.php'; session_name($cfg['session_name']); $isHttps=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https'; session_set_cookie_params(['httponly'=>true,'secure'=>$isHttps,'samesite'=>'Strict','path'=>'/']); session_start();
 $dsn='mysql:host='.$cfg['db']['host'].';dbname='.$cfg['db']['name'].';charset=utf8mb4';$db=new PDO($dsn,$cfg['db']['user'],$cfg['db']['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
} catch(Throwable $e) { out(500,['error'=>'Database is not configured. Follow README-DEPLOY.txt.']); }
set_exception_handler(static function(Throwable $e): void { out(500,['error'=>'The request could not be saved. Check for a duplicate blog slug and verify the database schema.']); });
$action=$_GET['action']??'';$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','HEAD'],true)){$origin=$_SERVER['HTTP_ORIGIN']??'';$host=strtolower(explode(':',$_SERVER['HTTP_HOST']??'')[0]);if($origin!==''&&strtolower((string)parse_url($origin,PHP_URL_HOST))!==$host)out(403,['error'=>'Cross-site request rejected']);}
if($action==='session'&&$method==='GET') out(200,['authenticated'=>!empty($_SESSION['admin']),'admin'=>$_SESSION['admin']??null]);
if($action==='login'&&$method==='POST') { $d=body();if(!hash_equals((string)$cfg['admin_username'],(string)($d['username']??''))||!password_verify((string)($d['password']??''),(string)$cfg['admin_password_hash']))out(401,['error'=>'Invalid username or password']);session_regenerate_id(true);$_SESSION['admin']=$cfg['admin_username'];out(200,['ok'=>true]); }
if($action==='logout'&&$method==='POST'){$_SESSION=[];session_destroy();out(200,['ok'=>true]);}
if(empty($_SESSION['admin']))out(401,['error'=>'Sign in required']);
if($action==='posts'&&$method==='GET'){ $q=$db->query('SELECT id,title,slug,excerpt,body,category,author,status,feature_image,seo_title,seo_description,canonical_url,updated_at FROM blog_posts ORDER BY updated_at DESC');out(200,['items'=>$q->fetchAll()]); }
if($action==='post'&&$method==='POST'){
 $d=$_POST?:body();$id=(int)($_GET['id']??$d['id']??0);$title=clean_text($d['title']??'',180);$slug=slugify((string)($d['slug']??$title));$excerpt=clean_text($d['excerpt']??'',500);$category=clean_text($d['category']??'Insights',80);$author=clean_text($d['author']??'VisionID Team',100);$content=(string)($d['body']??'');$seoTitle=clean_text($d['seo_title']??$title,70);$seoDesc=clean_text($d['seo_description']??$excerpt,170);$canonical=clean_text($d['canonical_url']??'',500);$published=isset($d['published'])&&$d['published']!=='0'?1:0;
 if(!$title||!$slug||!$excerpt||!$content)out(422,['error'=>'Title, slug, summary and article body are required']);
 $image='';if($id){$s=$db->prepare('SELECT feature_image FROM blog_posts WHERE id=?');$s->execute([$id]);$image=(string)$s->fetchColumn();}
 if(isset($_FILES['feature_image'])&&$_FILES['feature_image']['error']!==UPLOAD_ERR_NO_FILE){$f=$_FILES['feature_image'];if($f['error']!==UPLOAD_ERR_OK||$f['size']>5*1024*1024)out(422,['error'=>'Feature image must be under 5 MB']);$mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;if(!$ext)out(422,['error'=>'Use a JPG, PNG or WebP feature image']);$name=bin2hex(random_bytes(16)).'.'.$ext;$dir=dirname(__DIR__).'/uploads/blog';if(!is_dir($dir))mkdir($dir,0755,true);if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$name))out(500,['error'=>'Could not save image']);$image='/uploads/blog/'.$name;}
 if(!$id){$s=$db->prepare('INSERT INTO blog_posts(title,slug,excerpt,body,category,author,status,feature_image,seo_title,seo_description,canonical_url,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');$s->execute([$title,$slug,$excerpt,$content,$category,$author,$published?'published':'draft',$image,$seoTitle,$seoDesc,$canonical]);$id=(int)$db->lastInsertId();}
 else{$s=$db->prepare('UPDATE blog_posts SET title=?,slug=?,excerpt=?,body=?,category=?,author=?,status=?,feature_image=?,seo_title=?,seo_description=?,canonical_url=?,updated_at=NOW() WHERE id=?');$s->execute([$title,$slug,$excerpt,$content,$category,$author,$published?'published':'draft',$image,$seoTitle,$seoDesc,$canonical,$id]);}
 out(200,['ok'=>true,'id'=>$id]);
}
if($action==='post'&&$method==='DELETE'){ $id=(int)($_GET['id']??0);$s=$db->prepare('DELETE FROM blog_posts WHERE id=?');$s->execute([$id]);out(200,['ok'=>true]); }
if($action==='enquiries'&&$method==='GET'){$rows=$db->query('SELECT id,kind,name,email,phone,message,status,fields_json,created_at,updated_at FROM enquiries ORDER BY created_at DESC')->fetchAll();out(200,['items'=>$rows]);}
if($action==='enquiry'&&$method==='POST'){$d=body();$status=(string)($d['status']??'');if(!in_array($status,['new','in_progress','closed'],true))out(422,['error'=>'Invalid enquiry status']);$s=$db->prepare('UPDATE enquiries SET status=?,updated_at=NOW() WHERE id=?');$s->execute([$status,(int)($_GET['id']??0)]);out(200,['ok'=>true]);}
if($action==='enquiry'&&$method==='DELETE'){$s=$db->prepare('DELETE FROM enquiries WHERE id=?');$s->execute([(int)($_GET['id']??0)]);out(200,['ok'=>true]);}
out(404,['error'=>'Not found']);
