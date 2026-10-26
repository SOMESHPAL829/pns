<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/oee_dashboard_view.php';
function dashboard_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$window=['window_start'=>'2026-10-22 00:00:00','window_end'=>'2026-10-23 00:00:00'];
$base=['line_code'=>'APP-06','event_type'=>'unplanned','status'=>'proposed','reason_code'=>'UNK','reason_label'=>'Not yet classified','source'=>'gap'];
$events=[
    array_merge($base,['id'=>1,'starts_at'=>'2026-10-21 23:50:00','ends_at'=>'2026-10-22 00:20:00']),
    array_merge($base,['id'=>2,'starts_at'=>'2026-10-22 00:10:00','ends_at'=>'2026-10-22 00:30:00','event_type'=>'planned','reason_code'=>'PLANNED_STOP']),
    array_merge($base,['id'=>3,'starts_at'=>'2026-10-22 00:15:00','ends_at'=>'2026-10-22 00:25:00','status'=>'confirmed','source'=>'manual','reason_code'=>'HOIST','reason_label'=>'Hoist / movement']),
    array_merge($base,['id'=>4,'line_code'=>'APP-07','starts_at'=>'2026-10-22 00:10:00','ends_at'=>'2026-10-22 00:20:00']),
    array_merge($base,['id'=>5,'status'=>'rejected','starts_at'=>'2026-10-22 01:00:00','ends_at'=>'2026-10-22 05:00:00']),
];
$result=oee_dashboard_breakdown($window,$events);
dashboard_check($result['total_minutes']===40.0&&$result['unplanned_minutes']===35.0&&$result['planned_minutes']===5.0,'Breakdown must union overlaps, clip midnight and isolate lines');
$items=[];foreach($result['items'] as $item)$items[$item['reason_code']]=$item;
dashboard_check($items['HOIST']['minutes']===10.0&&$items['UNK']['minutes']===25.0,'Confirmed reason must own overlapping proposed intervals');
dashboard_check(abs(array_sum(array_column($result['items'],'percentage'))-100)<0.001,'Reason percentages must sum to 100');
dashboard_check(oee_dashboard_breakdown($window,[])['items']===[],'Empty window must not invent reasons');
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE oee_fb_runs (line_code TEXT,seq TEXT,part_code TEXT,grade TEXT,used_colour TEXT,report_colour TEXT,loading_at TEXT,unloading_at TEXT,pcs INTEGER,total_surface_area REAL,is_productive INTEGER,si_no INTEGER)');
$pdo->exec('CREATE TABLE oee_downtime_events (id INTEGER,line_code TEXT,starts_at TEXT,ends_at TEXT,event_type TEXT,source TEXT,status TEXT,reason_code TEXT,notes TEXT DEFAULT NULL)');
$pdo->exec('CREATE TABLE oee_reason_codes (code TEXT,label TEXT)');
for($i=1;$i<=8;$i++)$pdo->prepare('INSERT INTO oee_fb_runs VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute(['APP-06','SEQ'.$i,'Part'.$i,'6063','BLACK-1','',sprintf('2026-10-22 10:%02d:00',$i),null,10,20,1,$i]);
$pdo->exec("INSERT INTO oee_downtime_events (id,line_code,starts_at,ends_at,event_type,source,status,reason_code) VALUES (1,'APP-06','2026-10-22 00:00:00','2026-10-22 00:20:00','unplanned','gap','proposed','UNK'),(2,'APP-07','2026-10-22 00:00:00','2026-10-22 01:00:00','unplanned','gap','proposed','UNK')");
$pdo->exec("INSERT INTO oee_reason_codes VALUES ('UNK','Not yet classified')");
$dashboard=['available'=>true,'machine'=>'APP-06','window'=>$window,'metrics'=>['pcs'=>80],'oee'=>['oee'=>0.5]];
$basic=oee_dashboard_view($pdo,$dashboard,portal_validate_permissions(['dashboard'=>['view'=>true]]));
foreach(['recent_batches','recent_downtime','downtime_breakdown'] as $key)dashboard_check(!array_key_exists($key,$basic),'Dashboard-only permission leaks '.$key);
$batchOnly=oee_dashboard_view($pdo,$dashboard,portal_validate_permissions(['oee-report'=>['view'=>true]]));
dashboard_check(count($batchOnly['recent_batches'])===6&&$batchOnly['recent_batches'][0]['seq']==='SEQ8'&&!isset($batchOnly['recent_downtime']),'Batch projection must be limited, newest first and permission scoped');
$stopsOnly=oee_dashboard_view($pdo,$dashboard,portal_validate_permissions(['downtime'=>['view'=>true]]));
dashboard_check(!isset($stopsOnly['recent_batches'])&&$stopsOnly['downtime_breakdown']['total_minutes']===20.0,'Stop projection must respect selected plant and permissions');
dashboard_check($stopsOnly['metrics']===$dashboard['metrics']&&$stopsOnly['oee']===$dashboard['oee'],'Presentation projection changed OEE inputs');
echo "Dashboard downtime projection tests passed\n";
