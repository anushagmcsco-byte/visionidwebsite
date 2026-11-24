<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
try {
 $c=require __DIR__.'/config.php';
 $db=new PDO('mysql:host='.$c['db']['host'].';dbname='.$c['db']['name'].';charset=utf8mb4',$c['db']['user'],$c['db']['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $q=$db->query("SELECT id,title,slug,excerpt,body,category,author,status,feature_image,seo_title,seo_description,canonical_url,DATE_FORMAT(created_at,'%Y-%m-%d') AS date FROM blog_posts WHERE status='published' ORDER BY created_at DESC");
 echo json_encode(['items'=>$q->fetchAll()],JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
} catch(Throwable $e) { http_response_code(500); echo json_encode(['error'=>'Blog service unavailable']); }
