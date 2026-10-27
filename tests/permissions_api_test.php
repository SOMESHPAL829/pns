<?php
declare(strict_types=1);
// Opt-in database/HTTP integration test; only uniquely named test rows are removed.
if(PHP_SAPI!=='cli'||!getenv('OEE_TEST_BASE_URL'))exit("Set OEE_TEST_BASE_URL for the permissions API test.\n");
ob_start();
require_once dirname(__DIR__).'/includes/db.php';
require_once dirname(__DIR__).'/includes/security.php';
require_once dirname(__DIR__).'/includes/permissions.php';
$pdo=db();$users=[];$roles=[];$files=[];$tagEvents=[];$tagReasons=[];
$suffix=bin2hex(random_bytes(6));
$check=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$session=static function(int $id)use($pdo,&$files):array{
    $q=$pdo->prepare('SELECT auth_version FROM portal_users WHERE id=?');$q->execute([$id]);
    session_id('');start_app_session();$_SESSION=['user_id'=>$id,'auth_version'=>(int)$q->fetchColumn()];
    $auth=[session_name().'='.session_id(),csrf_token()];$file=(session_save_path()?:sys_get_temp_dir()).'/sess_'.session_id();session_write_close();$files[]=$file;
    if($owner=getenv('OEE_TEST_SESSION_OWNER'))chown($file,$owner);return $auth;
};
$call=static function(string $resource,string $action,?array $auth=null,?array $data=null,array $query=[],bool $csrf=true):array{
    $curl=curl_init(rtrim(getenv('OEE_TEST_BASE_URL'),'/').'/api.php?'.http_build_query(['resource'=>$resource,'action'=>$action,...$query]));
    $headers=['Accept: application/json'];if($data!==null)$headers[]='Content-Type: application/json';if($auth&&$csrf)$headers[]='X-CSRF-Token: '.$auth[1];
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>$headers]);if($auth)curl_setopt($curl,CURLOPT_COOKIE,$auth[0]);
    if($data!==null)curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data)]);
    $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    return [$status,is_string($body)&&str_starts_with($body,'{')?json_decode($body,true,flags:JSON_THROW_ON_ERROR):$body];
};
try{
    $actor=$pdo->query("SELECT id,role FROM portal_users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1")->fetch();$check((bool)$actor,'Active admin required');
    $admin=$session((int)$actor['id']);
    foreach(['viewer','owner'] as $kind){
        $name='rbac_test_'.$suffix.'_'.$kind;
        $pdo->prepare('INSERT INTO portal_users(username,email,full_name,password_hash) VALUES (?,?,?,?)')->execute([$name,$name.'@example.invalid','Temporary RBAC test',password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT)]);$users[$kind]=(int)$pdo->lastInsertId();
    }
    [$status,$created]=$call('roles','role-save',$admin,['name'=>'RBAC viewer '.$suffix,'permissions'=>['oee-report'=>['view'=>true]]]);
    $check($status===200,'Admin role creation failed');$roles[]=$roleId=(int)$created['id'];
    [$status]=$call('users','user-role',$admin,['id'=>$users['viewer'],'role_id'=>$roleId]);$check($status===200,'Role assignment failed');
    $viewer=$session($users['viewer']);
    [$status,$bootstrap]=$call('bootstrap','',$viewer);$check($status===200&&$bootstrap['records']===[]&&$bootstrap['record_count']===0&&$bootstrap['user_count']===null,'Bootstrap leaked restricted data');
    foreach(['dashboard','range-dashboard','range-export','logs','downtime','masters'] as $action)$check($call('oee',$action,$viewer)[0]===403,'Forbidden OEE GET allowed: '.$action);
    foreach(['records','users','roles'] as $resource)$check($call($resource,'',$viewer)[0]===403,'Forbidden page allowed: '.$resource);
    foreach([['oee','downtime-save',[]],['oee','downtime-save',['event_id'=>1]],['oee','master-save',['master_type'=>'threshold']],['oee','master-save',['master_type'=>'cycle']],['oee','master-save',['master_type'=>'reason','id'=>1]],['records','record-create',[]],['records','record-update',[]],['records','record-delete',[]],['users','user-create',[]],['users','user-toggle',[]],['users','user-role',[]],['roles','role-save',[]],['roles','role-delete',[]],['','profile',[]],['','password',[]]] as [$r,$a,$data])$check($call($r,$a,$viewer,$data)[0]===403,'Mutation allowed without permission: '.$a);
    $selection=['date'=>'2026-10-22','machine'=>'APP-06'];
    $check($call('oee','report',$viewer,null,$selection)[0]===200,'Permitted report read failed');
    $check($call('oee','dashboard',$viewer,null,array_merge($selection,['duration_minutes'=>840]))[0]===422,'Unsupported duration must be rejected');
    $check($call('oee','export',$viewer,null,$selection)[0]===403,'View-only role may download daily Excel');
    $grants=['oee-report'=>['view'=>true,'export'=>true],'oee-range'=>['view'=>true]];
    $check($call('roles','role-save',$admin,['id'=>$roleId,'name'=>'RBAC viewer '.$suffix,'permissions'=>$grants])[0]===200,'Export grant failed');
    [$status,$xlsx]=$call('oee','export',$viewer,null,$selection);$check($status===200&&str_starts_with($xlsx,"PK\x03\x04"),'Permitted daily Excel export failed');
    $range=['from_date'=>'2026-10-22','to_date'=>'2026-10-22','machine'=>'APP-06'];
    $check($call('oee','range-dashboard',$viewer,null,$range)[0]===200,'View-only range read failed');
    $check($call('oee','range-export',$viewer,null,$range)[0]===403,'Daily Export improperly permits range export');
    $grants['oee-range']['export']=true;
    $check($call('roles','role-save',$admin,['id'=>$roleId,'name'=>'RBAC viewer '.$suffix,'permissions'=>$grants])[0]===200,'Range Export grant failed');
    [$status,$xlsx]=$call('oee','range-export',$viewer,null,$range);$check($status===200&&str_starts_with($xlsx,"PK\x03\x04"),'Permitted range Excel export failed');
    $grants['oee-report']['export']=false;
    $check($call('roles','role-save',$admin,['id'=>$roleId,'name'=>'RBAC viewer '.$suffix,'permissions'=>$grants])[0]===200,'Export revocation failed');
    $check($call('oee','report',$viewer,null,$selection)[0]===200&&$call('oee','export',$viewer,null,$selection)[0]===403,'Export revocation must block active session without revoking View');
    $dashboardGrants=['dashboard'=>['view'=>true]];
    $check($call('roles','role-save',$admin,['id'=>$roleId,'name'=>'RBAC viewer '.$suffix,'permissions'=>$dashboardGrants])[0]===200,'Dashboard-only role update failed');
    foreach([1440,960,720,600,480] as $duration){
        [$status,$basic]=$call('oee','dashboard',$viewer,null,array_merge($selection,['duration_minutes'=>$duration]));
        $check($status===200,'Permitted dashboard failed');
        foreach(['recent_batches','recent_downtime','downtime_breakdown'] as $key)$check(!array_key_exists($key,$basic),'Dashboard-only API leaked '.$key);
    }
    $dashboardGrants['oee-report']=['view'=>true];
    $call('roles','role-save',$admin,['id'=>$roleId,'name'=>'RBAC viewer '.$suffix,'permissions'=>$dashboardGrants]);
    [$status,$batches]=$call('oee','dashboard',$viewer,null,$selection);
    $check($status===200&&isset($batches['recent_batches'])&&count($batches['recent_batches'])<=6&&!isset($batches['recent_downtime']),'Batch dashboard projection is not permission scoped');
    $dashboardGrants=['dashboard'=>['view'=>true],'downtime'=>['view'=>true]];
    $call('roles','role-save',$admin,['id'=>$roleId,'name'=>'RBAC viewer '.$suffix,'permissions'=>$dashboardGrants]);
    foreach([1440,960,720,600,480] as $duration){
        [$status,$stops]=$call('oee','dashboard',$viewer,null,array_merge($selection,['duration_minutes'=>$duration,'start_time'=>$duration===720?'18:00':'00:00']));
        $check($status===200&&isset($stops['recent_downtime'],$stops['downtime_breakdown'])&&!isset($stops['recent_batches']),'Stop dashboard projection is not permission scoped');
        $check(abs((float)$stops['oee']['unplanned_minutes']-(float)$stops['downtime_breakdown']['unplanned_minutes'])<0.01,'Reason breakdown disagrees with OEE unplanned duration');
        $check(abs((float)$stops['oee']['planned_stop_minutes']-(float)$stops['downtime_breakdown']['planned_minutes'])<0.01,'Reason breakdown disagrees with OEE planned duration');
    }
    // Tag-only mutations use synthetic future events, never production stops.
    $tagCode='TAG_TEST_'.strtoupper($suffix);
    $pdo->prepare('INSERT INTO oee_reason_codes(code,label,is_active) VALUES (?,?,1)')->execute([$tagCode,'Temporary tag test']);$tagReasons[]=$tagCode;
    $pdo->prepare("INSERT INTO oee_downtime_events(line_code,report_date,starts_at,ends_at,event_type,source,status,reason_code,notes) VALUES ('APP-07','2099-11-01','2099-11-01 23:55:17','2099-11-02 00:15:43','unplanned','manual','proposed','UNK',?)")->execute(['Temporary tag test '.$suffix]);$tagEvents[]=$tagId=(int)$pdo->lastInsertId();
    $tag=['event_id'=>$tagId,'machine'=>'APP-07','event_type'=>'planned','reason_code'=>$tagCode,'notes'=>'Reviewed'];
    $check($call('oee','downtime-tag',$viewer,$tag)[0]===403,'Downtime View improperly allows tagging');
    $check($call('oee','downtime-tag',null,$tag)[0]===401,'Anonymous tagging allowed');
    $dashboardGrants['downtime']['create']=true;
    $call('roles','role-save',$admin,['id'=>$roleId,'name'=>'RBAC viewer '.$suffix,'permissions'=>$dashboardGrants]);
    $check($call('oee','downtime-tag',$viewer,$tag)[0]===403,'Downtime Create improperly allows tagging');
    $dashboardGrants['downtime']=['view'=>true,'update'=>true];
    $call('roles','role-save',$admin,['id'=>$roleId,'name'=>'RBAC viewer '.$suffix,'permissions'=>$dashboardGrants]);
    $check($call('oee','downtime-tag',$viewer,$tag,[],false)[0]===419,'Tagging bypassed CSRF');
    $check($call('oee','downtime-tag',$viewer,array_replace($tag,['machine'=>'APP-06']))[0]===404,'Tagging accepted incorrect line');
    $check($call('oee','downtime-tag',$viewer,array_replace($tag,['reason_code'=>'MISSING_'.$suffix]))[0]===422,'Unregistered tag reason allowed');
    $check($call('records','downtime-tag',$viewer,$tag)[0]===404,'Forged tag resource bypassed policy');
    $q=$pdo->prepare('SELECT * FROM oee_downtime_events WHERE id=?');$q->execute([$tagId]);$beforeTag=$q->fetch();
    $check($beforeTag['status']==='proposed'&&$beforeTag['reason_code']==='UNK','Denied tags modified the stop');
    $check($call('oee','downtime-tag',$viewer,$tag)[0]===200,'Update-only role cannot tag dashboard downtime');
    $q->execute([$tagId]);$afterTag=$q->fetch();
    foreach(['line_code','report_date','starts_at','ends_at','source','evidence_key','created_at'] as $field)$check($afterTag[$field]===$beforeTag[$field],'Tag API modified '.$field);
    $check($afterTag['status']==='confirmed'&&$afterTag['reason_code']===$tagCode&&$afterTag['event_type']==='planned'&&$afterTag['notes']==='Reviewed','Tag fields not persisted');
    $check($call('oee','downtime-tag',$viewer,$tag)[0]===200,'Repeated tag save should succeed');
    $dashboardGrants['downtime']['update']=false;
    $call('roles','role-save',$admin,['id'=>$roleId,'name'=>'RBAC viewer '.$suffix,'permissions'=>$dashboardGrants]);
    $check($call('oee','downtime-tag',$viewer,$tag)[0]===403,'Tagging allowed after permission revocation');
    $check($call('oee','user-create',$viewer,[])[0]===404,'Forged resource bypassed policy');
    $check($call('roles','role-save',$admin,['name'=>'bad'],[],false)[0]===419,'Missing CSRF allowed');
    $check($call('roles','role-save',$admin,['name'=>'bad','permissions'=>['records'=>['create'=>true]]])[0]===422,'Create without View allowed');
    $check($call('roles','role-save',$admin,['name'=>'bad','permissions'=>['oee-report'=>['export'=>true]]])[0]===422,'Export without View allowed');
    $check($call('roles','role-save',$admin,['name'=>'bad','permissions'=>['roles'=>['view'=>true]]])[0]===403,'Custom role acquired protected administration');
    $check($call('roles','role-delete',$admin,['id'=>$roleId])[0]===409,'Assigned role deletion allowed');
    $check($call('users','user-role',$admin,['id'=>(int)$actor['id'],'role_id'=>$roleId])[0]===409,'Administrator self-role change allowed');
    $check($call('roles','role-save',$admin,['id'=>$roleId,'name'=>'RBAC viewer '.$suffix,'permissions'=>[]])[0]===200,'Role revocation failed');
    $check($call('oee','report',$viewer,null,$selection)[0]===403,'Permission revocation did not affect active session');
    $check($call('users','user-role',$admin,['id'=>$users['viewer'],'role_id'=>$roleId])[0]===200,'Reassignment failed');
    $check($call('bootstrap','',$viewer)[0]===401,'Assignment did not invalidate existing session');
    [$status,$created]=$call('roles','role-save',$admin,['name'=>'RBAC owner '.$suffix,'permissions'=>['records'=>['view'=>true,'create'=>true,'update'=>true,'delete'=>true]]]);$check($status===200,'Owner role create failed');$roles[]=$ownerRole=(int)$created['id'];
    portal_assign_role($pdo,$actor,$users['owner'],$ownerRole);$owner=$session($users['owner']);
    $data=['record_type'=>'Ticket','organization'=>'Test','category'=>'','priority'=>'Normal','title'=>'RBAC test','description'=>'Temporary integration fixture'];
    [$status,$record]=$call('records','record-create',$owner,$data);$check($status===201,'Permitted Create failed');
    $check($call('records','record-update',$owner,['id'=>$record['id'],'title'=>'Updated','description'=>'Test'])[0]===200,'Permitted Update failed');
    portal_assign_role($pdo,$actor,$users['viewer'],$ownerRole);$other=$session($users['viewer']);
    $check($call('records','record-delete',$other,['id'=>$record['id']])[0]===404,'CRUD permission bypassed record ownership');
    $check($call('records','record-delete',$owner,['id'=>$record['id']])[0]===200,'Permitted Delete failed');
    [$status,$created]=$call('roles','role-save',$admin,['name'=>'RBAC delegated '.$suffix,'permissions'=>['users'=>['view'=>true,'create'=>true,'update'=>true]]]);$check($status===200,'Delegated role create failed');$roles[]=$delegated=(int)$created['id'];
    portal_assign_role($pdo,$actor,$users['viewer'],$delegated);$delegate=$session($users['viewer']);
    $check($call('users','user-role',$delegate,['id'=>$users['owner'],'role_id'=>$ownerRole])[0]===403,'Delegated assignment may escalate privileges');
    $check($call('users','user-toggle',$delegate,['id'=>(int)$actor['id']])[0]===409,'Protected administrator may be disabled');
    $check($call('users','user-toggle',$delegate,['id'=>$users['owner']])[0]===403,'Higher-privileged account may be disabled');
    $newName='rbac_test_'.$suffix.'_created';
    $newUser=['username'=>$newName,'email'=>$newName.'@example.invalid','full_name'=>'Temporary role fixture','password'=>bin2hex(random_bytes(20)),'role_id'=>$delegated];
    $check($call('users','user-create',$delegate,array_merge($newUser,['role'=>'admin']))[0]===422,'Forged administrator flag accepted');
    [$status]=$call('users','user-create',$delegate,$newUser);
    $q=$pdo->prepare('SELECT id FROM portal_users WHERE username=?');$q->execute([$newName]);$createdUser=(int)$q->fetchColumn();if($createdUser)$users['created']=$createdUser;
    $check($status===201&&$createdUser>0,'Delegated Create with assignable role failed');
    $check(portal_user_access($pdo,['id'=>$createdUser,'role'=>'user'])['role_id']===$delegated,'New user did not get selected role');
    $check($call('users','user-toggle',$delegate,['id'=>$createdUser])[0]===200,'Permitted status Update failed');
    $check((int)$pdo->query('SELECT is_active FROM portal_users WHERE id='.$createdUser)->fetchColumn()===0,'Deactivation not persisted');
    [$status,$created]=$call('roles','role-save',$admin,['name'=>'RBAC unused '.$suffix,'permissions'=>[]]);$check($status===200,'Empty role create failed');$roles[]=$unused=(int)$created['id'];
    $check($call('roles','role-delete',$admin,['id'=>$unused])[0]===200,'Permitted role Delete failed');
    echo "Permissions HTTP tests passed: page/action denials, dashboard tagging, export, bootstrap privacy, role CRUD, revocation, session invalidation, CSRF and record ownership.\n";
}finally{
    foreach($tagEvents as $id)$pdo->prepare('DELETE FROM oee_downtime_events WHERE id=?')->execute([$id]);
    foreach($tagReasons as $code)$pdo->prepare('DELETE FROM oee_reason_codes WHERE code=?')->execute([$code]);
    foreach($users as $id){$pdo->prepare('DELETE FROM portal_records WHERE owner_id=?')->execute([$id]);$pdo->prepare('DELETE FROM portal_user_roles WHERE user_id=?')->execute([$id]);$pdo->prepare('DELETE FROM portal_users WHERE id=?')->execute([$id]);}
    foreach($roles as $id){$pdo->prepare('DELETE FROM portal_role_permissions WHERE role_id=?')->execute([$id]);$pdo->prepare('DELETE FROM portal_access_roles WHERE id=?')->execute([$id]);}
    foreach($files as $file)if(is_file($file))unlink($file);
    ob_end_flush();
}
