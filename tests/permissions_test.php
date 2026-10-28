<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/permissions.php';
function permission_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$empty = portal_permission_matrix();
permission_check(!portal_can($empty, 'records', 'view'), 'New roles must deny by default');
permission_check(!portal_can($empty, 'unknown', 'delete'), 'Unknown page must deny');
$view = portal_validate_permissions(['records' => ['view' => true]]);
permission_check(portal_can($view, 'records', 'view') && !portal_can($view, 'records', 'delete'), 'View-only must not permit mutations');
foreach ([['records' => ['create' => true]], ['unknown' => ['view' => true]], ['dashboard' => ['delete' => true]], ['records' => ['view' => 'false']], ['records' => true]] as $invalid) {
    $failed = false;
    try { portal_validate_permissions($invalid); } catch (InvalidArgumentException $e) { $failed = true; }
    permission_check($failed, 'Malformed or unsupported grants accepted');
}
$admin = portal_permission_matrix(true);
foreach(['dashboard'=>'dashboard','range-dashboard'=>'oee-range','range-export'=>'oee-range','report'=>'oee-report','export'=>'oee-report','logs'=>'scada-logs','downtime'=>'downtime','masters'=>'oee-masters'] as $action=>$page){
    permission_check(portal_api_requirement('oee',$action,'GET')===[$page,in_array($action,['export','range-export'],true)?'export':'view'],'GET permission missing: '.$action);
}
permission_check(portal_api_requirement('chemicals','list','GET')===['chemical-registration','view'],'Chemical list permission missing');
permission_check(portal_api_requirement('chemicals','item-save','POST',[])===['chemical-registration','create'],'Chemical item create permission missing');
permission_check(portal_api_requirement('chemicals','rate-save','POST',['id'=>5])===['chemical-registration','update'],'Chemical rate update permission missing');
permission_check(portal_api_requirement('chemical-consumption','list','GET')===['chemical-consumption','view'],'Chemical consumption list permission missing');
permission_check(portal_api_requirement('chemical-consumption','save','POST',[])===['chemical-consumption','create'],'Chemical consumption create permission missing');
permission_check(portal_api_requirement('chemical-consumption','save','POST',['id'=>5])===['chemical-consumption','update'],'Chemical consumption update permission missing');
permission_check(portal_api_requirement('chemical-consumption','report','GET')===['chemical-consumption-report','view'],'Chemical consumption report permission missing');
permission_check(portal_api_requirement('chemical-consumption','cost-report','GET')===['chemical-cost-report','view'],'Chemical cost report permission missing');
permission_check(portal_api_requirement('breakdowns','list','GET')===['breakdown-registration','view'],'Breakdown list permission missing');
permission_check(portal_api_requirement('breakdowns','by-type','GET')===['downtime','view'],'Downtime reason lookup permission missing');
permission_check(portal_api_requirement('breakdowns','save','POST')===['breakdown-registration','create'],'Breakdown create permission missing');
permission_check(portal_api_requirement('breakdowns','save','POST',['id'=>5])===['breakdown-registration','update'],'Breakdown update permission missing');
$deleteRouteMissing=false;try{portal_api_requirement('breakdowns','delete','POST');}catch(DomainException $e){$deleteRouteMissing=$e->getCode()===404;}
permission_check($deleteRouteMissing,'Breakdown delete route must be unavailable');
permission_check(portal_api_requirement('appearance','save','POST')===null,'User appearance must be scoped to the signed-in user without profile edit permission');
foreach(['records'=>'record','users'=>'user','roles'=>'role'] as $resource=>$prefix){
    permission_check(portal_api_requirement($resource,$prefix.($resource==='roles'?'-save':'-create'),'POST')===[$resource,'create'],'Create permission missing');
}
permission_check(portal_api_requirement('oee','master-save','POST',['master_type'=>'threshold'])===['oee-masters','update'],'Threshold must require Update, not Create');
permission_check(portal_api_requirement('oee','downtime-save','POST',['event_id'=>5])===['downtime','update'],'Tag existing downtime requires Update');
permission_check(portal_api_requirement('oee','downtime-save','POST',[])===['downtime','create'],'Manual stop requires Create');
$failed=false;try{portal_api_requirement('oee','user-create','POST');}catch(DomainException $e){$failed=true;}
permission_check($failed,'Forged resource may bypass policy');
permission_check(portal_can($admin, 'roles', 'delete'), 'Administrator missing supported grant');
permission_check(portal_can($admin, 'chemical-registration', 'update'), 'Administrator missing chemical update grant');
permission_check(portal_can($admin, 'chemical-consumption', 'update'), 'Administrator missing chemical consumption update grant');
permission_check(portal_can($admin, 'chemical-consumption-report', 'view'), 'Administrator missing chemical consumption report grant');
permission_check(portal_can($admin, 'chemical-cost-report', 'view'), 'Administrator missing chemical cost report grant');
permission_check(!portal_can($admin, 'dashboard', 'delete'), 'Unsupported operations are not permissions');
permission_check(portal_grants_within($view, $admin) && !portal_grants_within($admin, $view), 'Assignment must not permit escalation');
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE portal_access_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE, is_builtin INTEGER DEFAULT 0, created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE portal_role_permissions (role_id INTEGER, page_code TEXT, action_code TEXT, PRIMARY KEY(role_id,page_code,action_code))');
$pdo->exec('CREATE TABLE portal_user_roles (user_id INTEGER PRIMARY KEY, role_id INTEGER)');
$pdo->exec('CREATE TABLE portal_access_audit (id INTEGER PRIMARY KEY, actor_id INTEGER, event_type TEXT, target_id INTEGER, details TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec("CREATE TABLE portal_users (id INTEGER PRIMARY KEY, role TEXT, auth_version INTEGER DEFAULT 1, is_active INTEGER DEFAULT 1)");
$pdo->exec("INSERT INTO portal_users(id,role) VALUES (1,'admin'),(2,'user'),(3,'user')");
$actor = ['id' => 1, 'role' => 'admin'];
$pdo->exec("INSERT INTO portal_access_roles(name,is_builtin) VALUES ('Standard user',1)");
$standard=(int)$pdo->lastInsertId();
$insert=$pdo->prepare('INSERT INTO portal_role_permissions VALUES (?,?,?)');
foreach(portal_standard_permissions() as $page=>$actions)foreach($actions as $action=>$allow)if($allow)$insert->execute([$standard,$page,$action]);
permission_check(portal_can(portal_user_access($pdo,['id'=>3,'role'=>'user'])['permissions'],'records','delete'),'Legacy user compatibility');
permission_check(!portal_can(portal_user_access($pdo,['id'=>3,'role'=>'user'])['permissions'],'chemical-registration','view'),'Standard user must not receive chemical access implicitly');
permission_check(!portal_can(portal_user_access($pdo,['id'=>3,'role'=>'user'])['permissions'],'chemical-consumption','view'),'Standard user must not receive chemical consumption access implicitly');
permission_check(!portal_can(portal_user_access($pdo,['id'=>3,'role'=>'user'])['permissions'],'chemical-consumption-report','view'),'Standard user must not receive chemical report access implicitly');
permission_check(!portal_can(portal_user_access($pdo,['id'=>3,'role'=>'user'])['permissions'],'chemical-cost-report','view'),'Standard user must not receive chemical cost report access implicitly');
$id = portal_save_role($pdo, $actor, null, 'Report viewer', ['oee-report' => ['view' => true]]);
portal_assign_role($pdo, $actor, 2, $id);
$insert->execute([$id,'retired-page','view']);
$listedRole=array_values(array_filter(portal_list_roles($pdo),static fn(array $role):bool=>(int)$role['id']===$id))[0]??null;
permission_check($listedRole!==null && !isset($listedRole['permissions']['retired-page']) && portal_grants_within($listedRole['permissions'],portal_permission_matrix(true)),'Stale permission rows must not hide valid assignable roles');
$pdo->prepare("DELETE FROM portal_role_permissions WHERE role_id=? AND page_code='retired-page'")->execute([$id]);
$access = portal_user_access($pdo, ['id' => 2, 'role' => 'user']);
permission_check(portal_can($access['permissions'], 'oee-report', 'view') && !portal_can($access['permissions'], 'records', 'view'), 'Assignment grants wrong page');
permission_check((int) $pdo->query('SELECT auth_version FROM portal_users WHERE id=2')->fetchColumn() === 2, 'Assignment must invalidate sessions');
portal_save_role($pdo, $actor, $id, 'Report viewer', []);
permission_check(!portal_can(portal_user_access($pdo, ['id'=>2,'role'=>'user'])['permissions'], 'oee-report','view'), 'Permission edit must affect next request');
foreach ([static fn()=>portal_assign_role($pdo,$actor,1,$id), static fn()=>portal_delete_role($pdo,$actor,$id), static fn()=>portal_save_role($pdo,['id'=>2,'role'=>'user'],null,'Escalation',[]) ] as $action) {
    $failed=false; try{$action();}catch(DomainException $e){$failed=true;}
    permission_check($failed,'Protected role operation accepted');
}
foreach([static fn()=>portal_save_role($pdo,$actor,$standard,'Changed',[]),static fn()=>portal_delete_role($pdo,$actor,$standard),static fn()=>portal_save_role($pdo,$actor,null,'Escalated',['roles'=>['view'=>true]])] as $action){
    $failed=false;try{$action();}catch(DomainException $e){$failed=true;}permission_check($failed,'Built-in or admin-only role boundary bypassed');
}
$delegated=portal_save_role($pdo,$actor,null,'Delegated users',['users'=>['view'=>true,'update'=>true]]);
portal_assign_role($pdo,$actor,3,$delegated);
$failed=false;try{portal_assign_role($pdo,['id'=>3,'role'=>'user'],2,$standard);}catch(DomainException $e){$failed=true;}
permission_check($failed,'Delegated user may assign a stronger role');
$failed=false;try{portal_assign_role($pdo,['id'=>3,'role'=>'user'],3,$delegated);}catch(DomainException $e){$failed=true;}
permission_check($failed,'Self assignment accepted');
$pdo->exec('DELETE FROM portal_access_roles WHERE id=' . $id);
permission_check(!portal_can(portal_user_access($pdo,['id'=>2,'role'=>'user'])['permissions'],'dashboard','view'), 'Broken assignment must not fall back to broad user access');
permission_check(portal_can(portal_user_access($pdo,$actor)['permissions'],'roles','update'), 'Admin lockout');
echo "Permission policy, persistence and anti-escalation tests passed\n";
