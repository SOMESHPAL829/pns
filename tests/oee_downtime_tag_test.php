<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/permissions.php';
require_once dirname(__DIR__).'/includes/oee_downtime_tag.php';
function tag_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function tag_fails(callable $call,int $status):void{
    try{$call();}catch(InvalidArgumentException|DomainException $e){tag_check(($e instanceof InvalidArgumentException?422:$e->getCode())===$status,'Wrong error status: '.$e->getMessage());return;}
    throw new RuntimeException('Expected rejection '.$status);
}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE oee_reason_codes(code TEXT,label TEXT,is_active INTEGER)');
$pdo->exec("INSERT INTO oee_reason_codes VALUES ('UNK','Not classified',1),('CUSTOM_STOP','Custom stop',1),('INACTIVE','Inactive',0)");
$pdo->exec('CREATE TABLE oee_downtime_events(id INTEGER PRIMARY KEY,line_code TEXT,report_date TEXT,starts_at TEXT,ends_at TEXT,event_type TEXT,source TEXT,status TEXT,reason_code TEXT,notes TEXT,evidence_key TEXT,updated_at TEXT)');
$pdo->exec("INSERT INTO oee_downtime_events VALUES (1,'APP-07','2026-10-22','2026-10-22 23:55:17','2026-10-23 00:15:43','unplanned','gap','proposed','UNK','Original','evidence-1','2026-10-22 23:55:17')");
$before=$pdo->query('SELECT * FROM oee_downtime_events')->fetch();
$permissions=portal_validate_permissions(['dashboard'=>['view'=>true],'downtime'=>['view'=>true,'update'=>true]]);
$input=['event_id'=>1,'machine'=>'APP-07','event_type'=>'planned','reason_code'=>'CUSTOM_STOP','notes'=>'Reviewed <text>','starts_at'=>'2099-01-01','ends_at'=>'2099-01-02','source'=>'manual','status'=>'approved'];
tag_fails(fn()=>oee_tag_downtime($pdo,$input,portal_standard_permissions()),403);
tag_check(portal_api_requirement('oee','downtime-tag','POST',$input)===['downtime','update'],'Tag route must always require Update');
foreach([['event_id'=>null],['event_id'=>-1],['machine'=>'ALL'],['machine'=>[]],['event_type'=>'unknown'],['reason_code'=>'MISSING'],['reason_code'=>'INACTIVE'],['notes'=>str_repeat('a',501)],['notes'=>[]]] as $bad){tag_fails(fn()=>oee_tag_downtime($pdo,array_replace($input,$bad),$permissions),422);}
tag_fails(fn()=>oee_tag_downtime($pdo,array_replace($input,['machine'=>'APP-06']),$permissions),404);
tag_fails(fn()=>oee_tag_downtime($pdo,array_replace($input,['event_id'=>999]),$permissions),404);
tag_check($pdo->query('SELECT * FROM oee_downtime_events')->fetch()===$before,'Rejected calls must not write');
$result=oee_tag_downtime($pdo,$input,$permissions);
tag_check($result===['ok'=>true,'event_id'=>1,'updated'=>true],'Success contract');
$after=$pdo->query('SELECT * FROM oee_downtime_events')->fetch();
foreach(['line_code','report_date','starts_at','ends_at','source','evidence_key'] as $field)tag_check($before[$field]===$after[$field],'Tagging changed '.$field);
tag_check($after['status']==='confirmed'&&$after['reason_code']==='CUSTOM_STOP'&&$after['event_type']==='planned'&&$after['notes']==='Reviewed <text>','Tag fields not saved');
foreach(['confirmed','approved','rejected'] as $status){$pdo->prepare('UPDATE oee_downtime_events SET status=? WHERE id=1')->execute([$status]);oee_tag_downtime($pdo,$input,$permissions);tag_check($pdo->query('SELECT status FROM oee_downtime_events')->fetchColumn()===$status,'Existing status changed: '.$status);}
tag_check((int)$pdo->query('SELECT COUNT(*) FROM oee_downtime_events')->fetchColumn()===1,'Tagging must never insert stops');
echo "Downtime tagging tests passed: permissions, dynamic reasons, validation, exact intervals and status preservation\n";
