<?php
declare(strict_types=1);

function portal_permission_catalog(): array
{
    return [
        'dashboard' => ['label'=>'OEE Dashboard','actions'=>['view']],
        'oee-range' => ['label'=>'Date-Range OEE','actions'=>['view','export']],
        'oee-report' => ['label'=>'Production Report / Excel Export','actions'=>['view','export']],
        'scada-logs' => ['label'=>'SCADA Logs','actions'=>['view']],
        'downtime' => ['label'=>'Downtime Review','actions'=>['view','create','update']],
        'records' => ['label'=>'Records','actions'=>['view','create','update','delete']],
        'chemical-consumption' => ['label'=>'Chemical Consumption','actions'=>['view','create','update']],
        'chemical-consumption-report' => ['label'=>'Chemical Consumption Report','actions'=>['view']],
        'chemical-cost-report' => ['label'=>'Chemical Cost Analytics','actions'=>['view']],
        'users' => ['label'=>'Users (Account Status = Update)','actions'=>['view','create','update']],
        'oee-masters' => ['label'=>'OEE Master Data','actions'=>['view','create','update']],
        'chemical-registration' => ['label'=>'Chemical Registration','actions'=>['view','create','update']],
        'breakdown-registration' => ['label'=>'Downtime Registration','actions'=>['view','create','update']],
        'profile' => ['label'=>'My Profile / Password','actions'=>['view','update']],
        'roles' => ['label'=>'Roles & Permissions (Administrators Only)','actions'=>['view','create','update','delete']],
    ];
}

function portal_permission_matrix(bool $allow = false): array
{
    $matrix = [];
    foreach (portal_permission_catalog() as $page=>$item) $matrix[$page] = array_fill_keys($item['actions'], $allow);
    return $matrix;
}

function portal_standard_permissions(): array
{
    $matrix = portal_permission_matrix();
    foreach (['dashboard','oee-range','oee-report','scada-logs','downtime','oee-masters','profile','records'] as $page) $matrix[$page]['view'] = true;
    foreach (['create','update','delete'] as $action) $matrix['records'][$action] = true;
    foreach (['oee-report','oee-range'] as $page) $matrix[$page]['export'] = true;
    $matrix['profile']['update'] = true;
    return $matrix;
}

function portal_can(array $permissions, string $page, string $action = 'view'): bool
{
    return ($permissions[$page]['view'] ?? false) === true && ($permissions[$page][$action] ?? false) === true
        && in_array($action, portal_permission_catalog()[$page]['actions'] ?? [], true);
}

function portal_validate_permissions(array $input): array
{
    $matrix = portal_permission_matrix();
    foreach ($input as $page=>$grants) {
        if (!isset($matrix[$page]) || !is_array($grants)) throw new InvalidArgumentException('Unknown permission page or invalid grant list.');
        foreach ($grants as $action=>$allow) {
            if (!array_key_exists($action,$matrix[$page]) || !is_bool($allow)) throw new InvalidArgumentException('Permissions must be supported actions with true/false values.');
            $matrix[$page][$action] = $allow;
        }
        if (!$matrix[$page]['view'] && in_array(true,$matrix[$page],true)) throw new InvalidArgumentException('View is required before Create, Update, Delete or Export.');
    }
    return $matrix;
}

function portal_grants_within(array $target, array $actor): bool
{
    foreach ($target as $page=>$actions) foreach ($actions as $action=>$allow) {
        if ($allow === true && !portal_can($actor,$page,$action)) return false;
    }
    return true;
}

/** Exact allowlist: no action may bypass its page by forging a resource name. */
function portal_api_requirement(string $resource, string $action, string $method, array $data=[]): ?array
{
    if($method==='GET'){
        if($resource==='bootstrap' && $action==='')return null;
        if(in_array($resource,['records','users','roles'],true)&&$action==='')return [$resource,'view'];
        if($resource==='chemicals'&&$action==='list')return ['chemical-registration','view'];
        if($resource==='chemical-consumption'&&$action==='list')return ['chemical-consumption','view'];
        if($resource==='chemical-consumption'&&$action==='report')return ['chemical-consumption-report','view'];
        if($resource==='chemical-consumption'&&$action==='cost-report')return ['chemical-cost-report','view'];
        if($resource==='breakdowns'&&$action==='list')return ['breakdown-registration','view'];
        if($resource==='breakdowns'&&$action==='by-type')return ['downtime','view'];
        if($resource==='oee'){
            $pages=['dashboard'=>'dashboard','duration-setting'=>'dashboard','range-dashboard'=>'oee-range','range-export'=>'oee-range','report'=>'oee-report','export'=>'oee-report','logs'=>'scada-logs','downtime'=>'downtime','masters'=>'oee-masters'];
            if(isset($pages[$action]))return [$pages[$action],in_array($action,['export','range-export'],true)?'export':'view'];
        }
    }
    if($method==='POST'){
        if($resource==='appearance'&&$action==='save')return null;
        if($resource==='oee'&&$action==='duration-setting-save')return ['dashboard','view'];
        if($resource==='oee'&&$action==='downtime-tag')return ['downtime','update'];
        if($resource==='oee'&&$action==='downtime-save')return ['downtime',filter_var($data['event_id']??null,FILTER_VALIDATE_INT)?'update':'create'];
        if($resource==='oee'&&$action==='master-save')return ['oee-masters',($data['master_type']??null)==='threshold'||filter_var($data['id']??null,FILTER_VALIDATE_INT)?'update':'create'];
        if($resource==='chemicals'&&in_array($action,['item-save','rate-save'],true))return ['chemical-registration',filter_var($data['id']??null,FILTER_VALIDATE_INT)?'update':'create'];
        if($resource==='chemical-consumption'&&$action==='save')return ['chemical-consumption',filter_var($data['id']??null,FILTER_VALIDATE_INT)?'update':'create'];
        if($resource==='breakdowns'&&$action==='save')return ['breakdown-registration',filter_var($data['id']??null,FILTER_VALIDATE_INT)?'update':'create'];
        if($resource==='roles'&&$action==='role-save')return ['roles',filter_var($data['id']??null,FILTER_VALIDATE_INT)?'update':'create'];
        if($resource==='roles'&&$action==='role-delete')return ['roles','delete'];
        if($resource==='users'&&in_array($action,['user-create','user-toggle','user-role'],true))return ['users',$action==='user-create'?'create':'update'];
        if($resource==='records'&&in_array($action,['record-create','record-update','record-delete'],true))return ['records',substr($action,7)];
        if($resource===''&&in_array($action,['profile','password'],true))return ['profile','update'];
    }
    throw new DomainException('Resource/action not found.',404);
}

function portal_role_permissions(PDO $pdo, int $id): array
{
    $matrix = portal_permission_matrix();
    $query=$pdo->prepare('SELECT page_code,action_code FROM portal_role_permissions WHERE role_id=?'); $query->execute([$id]);
    foreach ($query->fetchAll() as $row) {
        if (isset($matrix[$row['page_code']]) && array_key_exists($row['action_code'],$matrix[$row['page_code']])) $matrix[$row['page_code']][$row['action_code']] = true;
    }
    return $matrix;
}

const PORTAL_ALL_PLANTS = '*';

function portal_plant_tables_ready(PDO $pdo): bool
{
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('portal_role_plants','portal_user_plants')")->fetchColumn() === 2;
        }
        return (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('portal_role_plants','portal_user_plants')")->fetchColumn() === 2;
    } catch (Throwable) { return false; }
}

function portal_available_plants(PDO $pdo): array
{
    $plants=[];
    foreach ([
        'SELECT DISTINCT line_code AS plant FROM oee_import_batches',
        'SELECT DISTINCT line_code AS plant FROM oee_fb_runs',
        'SELECT DISTINCT line_code AS plant FROM oee_cycle_time_standards',
        'SELECT DISTINCT machine_code AS plant FROM chemical_consumption_entries',
    ] as $sql) {
        try { foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $plant) {
            $plant=strtoupper(trim((string)$plant));
            if($plant!=='' && $plant!=='ALL')$plants[$plant]=true;
        }} catch(Throwable) {}
    }
    $plants=array_keys($plants); sort($plants,SORT_NATURAL); return $plants;
}

function portal_normalize_plants(PDO $pdo, mixed $input, bool $allowAll=true): array
{
    if(!is_array($input))throw new InvalidArgumentException('Plant access must be a list.');
    $available=array_fill_keys(portal_available_plants($pdo),true); $selected=[];
    foreach($input as $value){
        if(!is_string($value))throw new InvalidArgumentException('Choose valid plants.');
        $plant=strtoupper(trim($value));
        if($allowAll && in_array($plant,['ALL',PORTAL_ALL_PLANTS],true))return [PORTAL_ALL_PLANTS];
        if(!isset($available[$plant]))throw new InvalidArgumentException('Unknown plant: '.$plant);
        $selected[$plant]=true;
    }
    return array_keys($selected);
}

function portal_role_plant_scope(PDO $pdo, ?int $roleId): array
{
    if(!$roleId || !portal_plant_tables_ready($pdo))return ['all_plants'=>true,'plants'=>[]];
    $q=$pdo->prepare('SELECT plant_code FROM portal_role_plants WHERE role_id=? ORDER BY plant_code');$q->execute([$roleId]);
    $plants=array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));
    // A legacy role without rows predates plant scoping and retains its former access.
    if($plants===[] || in_array(PORTAL_ALL_PLANTS,$plants,true))return ['all_plants'=>true,'plants'=>[]];
    return ['all_plants'=>false,'plants'=>$plants];
}

function portal_user_extra_plants(PDO $pdo, int $userId): array
{
    if(!portal_plant_tables_ready($pdo))return [];
    $q=$pdo->prepare('SELECT plant_code FROM portal_user_plants WHERE user_id=? ORDER BY plant_code');$q->execute([$userId]);
    return array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));
}

function portal_user_override_tables_ready(PDO $pdo): bool
{
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('portal_user_access_overrides','portal_user_permissions')")->fetchColumn() === 2;
        }
        return (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('portal_user_access_overrides','portal_user_permissions')")->fetchColumn() === 2;
    } catch (Throwable) { return false; }
}

function portal_user_has_custom_access(PDO $pdo, int $userId): bool
{
    if(!portal_user_override_tables_ready($pdo))return false;
    $q=$pdo->prepare('SELECT custom_access FROM portal_user_access_overrides WHERE user_id=?');$q->execute([$userId]);
    return (int)$q->fetchColumn()===1;
}

function portal_user_permissions(PDO $pdo, int $userId): array
{
    $matrix=portal_permission_matrix();
    if(!portal_user_override_tables_ready($pdo))return $matrix;
    $q=$pdo->prepare('SELECT page_code,action_code FROM portal_user_permissions WHERE user_id=?');$q->execute([$userId]);
    foreach($q->fetchAll() as $row){
        if(isset($matrix[$row['page_code']])&&array_key_exists($row['action_code'],$matrix[$row['page_code']]))$matrix[$row['page_code']][$row['action_code']]=true;
    }
    return $matrix;
}

function portal_scope_within(array $target, array $actor): bool
{
    if($actor['all_plants']??false)return true;
    if($target['all_plants']??false)return false;
    return array_diff($target['plants']??[],$actor['plants']??[])===[];
}

function portal_can_plant(array $access, string $plant): bool
{
    return ($access['all_plants']??false) || in_array(strtoupper($plant),$access['plants']??[],true);
}

function portal_user_access(PDO $pdo, array $user): array
{
    if ($user['role'] === 'admin') return ['role_id'=>0,'role_name'=>'Administrator','system_admin'=>true,'permissions'=>portal_permission_matrix(true),'all_plants'=>true,'plants'=>[],'extra_plants'=>[],'custom_access'=>false];
    $q=$pdo->prepare('SELECT ur.role_id,r.name FROM portal_user_roles ur LEFT JOIN portal_access_roles r ON r.id=ur.role_id WHERE ur.user_id=?'); $q->execute([$user['id']]);
    $role=$q->fetch();
    // An explicitly assigned but missing role must never fall back to broader access.
    if (!$role) $role=$pdo->query("SELECT id AS role_id,name FROM portal_access_roles WHERE is_builtin=1 AND name='Standard user'")->fetch();
    $id=$role ? (int)$role['role_id'] : null;
    $custom=portal_user_has_custom_access($pdo,(int)$user['id']);
    $extra=portal_user_extra_plants($pdo,(int)$user['id']);
    if($custom){
        $scope=['all_plants'=>in_array(PORTAL_ALL_PLANTS,$extra,true),'plants'=>array_values(array_filter($extra,static fn(string $plant):bool=>$plant!==PORTAL_ALL_PLANTS))];
    }else{
        $scope=portal_role_plant_scope($pdo,$id);
        if(!$scope['all_plants']){$scope['plants']=array_values(array_unique(array_merge($scope['plants'],$extra)));sort($scope['plants'],SORT_NATURAL);}
    }
    return ['role_id'=>$id,'role_name'=>$role['name'] ?? 'No access','system_admin'=>false,
        'permissions'=>$custom?portal_user_permissions($pdo,(int)$user['id']):($role && $role['name'] !== null ? portal_role_permissions($pdo,$id) : portal_permission_matrix()),
        'all_plants'=>$scope['all_plants'],'plants'=>$scope['plants'],'extra_plants'=>$custom?[]:$extra,'custom_access'=>$custom];
}

function portal_access_audit(PDO $pdo, int $actor, string $event, int $target, array $details): void
{
    $pdo->prepare('INSERT INTO portal_access_audit (actor_id,event_type,target_id,details) VALUES (?,?,?,?)')->execute([$actor,$event,$target,json_encode($details,JSON_THROW_ON_ERROR)]);
}

function portal_list_roles(PDO $pdo): array
{
    $roles=$pdo->query("SELECT r.*, (SELECT COUNT(*) FROM portal_user_roles ur WHERE ur.role_id=r.id) + CASE WHEN r.is_builtin=1 AND r.name='Standard user' THEN (SELECT COUNT(*) FROM portal_users u WHERE u.role='user' AND NOT EXISTS(SELECT 1 FROM portal_user_roles ur WHERE ur.user_id=u.id)) ELSE 0 END AS assigned_users FROM portal_access_roles r ORDER BY r.is_builtin DESC,r.name LIMIT 100")->fetchAll();
    $grants=$pdo->query('SELECT role_id,page_code,action_code FROM portal_role_permissions')->fetchAll();
    $byRole=[];
    $catalog=portal_permission_catalog();
    foreach($grants as $grant) {
        $page=(string)$grant['page_code'];
        $action=(string)$grant['action_code'];
        // Old deployments can retain grants for pages/actions that were removed.
        // Never let those stale rows hide an otherwise valid role from assignment lists.
        if(!isset($catalog[$page]) || !in_array($action,$catalog[$page]['actions'],true))continue;
        $byRole[$grant['role_id']][$page][$action]=true;
    }
    foreach($roles as &$role) {
        $role['permissions']=array_replace_recursive(portal_permission_matrix(),$byRole[$role['id']] ?? []);
        $scope=portal_role_plant_scope($pdo,(int)$role['id']);$role=array_merge($role,$scope);
    }
    unset($role);
    return $roles;
}

function portal_save_role(PDO $pdo, array $actor, ?int $id, string $name, array $input, mixed $plantInput=['ALL']): int
{
    if ($actor['role'] !== 'admin') throw new DomainException('Only protected administrators may manage roles.',403);
    $name=trim($name);
    if ($name==='' || mb_strlen($name)>80 || preg_match('/[\x00-\x1F\x7F]/u',$name)) throw new InvalidArgumentException('Enter a role name of 1–80 characters.');
    if(strcasecmp($name,'Administrator')===0)throw new InvalidArgumentException('Administrator is a reserved role name.');
    $matrix=portal_validate_permissions($input);
    $plants=portal_normalize_plants($pdo,$plantInput,true);
    if($plants===[])throw new InvalidArgumentException('Select All Plants or at least one plant.');
    // These permissions are deliberately reserved, even if a forged request asks for them.
    if (in_array(true,$matrix['roles'],true)) throw new DomainException('Role administration is reserved for protected administrators.',403);
    $own=!$pdo->inTransaction(); if($own)$pdo->beginTransaction();
    try {
        $before=null;
        if($id!==null) {
            $q=$pdo->prepare('SELECT * FROM portal_access_roles WHERE id=?' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'')); $q->execute([$id]); $before=$q->fetch();
            if(!$before)throw new DomainException('Role not found.',404);
            if((int)$before['is_builtin']===1)throw new DomainException('Built-in roles are protected. Create a custom role instead.',409);
            $before['permissions']=portal_role_permissions($pdo,$id);
            $before['plant_scope']=portal_role_plant_scope($pdo,$id);
            $pdo->prepare('UPDATE portal_access_roles SET name=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$name,$id]);
            $pdo->prepare('DELETE FROM portal_role_permissions WHERE role_id=?')->execute([$id]);
        } else {
            if((int)$pdo->query('SELECT COUNT(*) FROM portal_access_roles')->fetchColumn()>=100)throw new DomainException('Role limit reached (100).',409);
            $pdo->prepare('INSERT INTO portal_access_roles(name,created_by) VALUES (?,?)')->execute([$name,$actor['id']]); $id=(int)$pdo->lastInsertId();
        }
        $insert=$pdo->prepare('INSERT INTO portal_role_permissions(role_id,page_code,action_code) VALUES (?,?,?)');
        foreach($matrix as $page=>$actions)foreach($actions as $action=>$allow)if($allow)$insert->execute([$id,$page,$action]);
        if(portal_plant_tables_ready($pdo)){
            $pdo->prepare('DELETE FROM portal_role_plants WHERE role_id=?')->execute([$id]);
            $plantInsert=$pdo->prepare('INSERT INTO portal_role_plants(role_id,plant_code) VALUES (?,?)');
            foreach($plants as $plant)$plantInsert->execute([$id,$plant]);
        }
        portal_access_audit($pdo,(int)$actor['id'],$before?'role.update':'role.create',$id,['before'=>$before,'name'=>$name,'permissions'=>$matrix,'plants'=>$plants]);
        if($own)$pdo->commit(); return $id;
    } catch(Throwable $e){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function portal_delete_role(PDO $pdo, array $actor, int $id): void
{
    if($actor['role']!=='admin')throw new DomainException('Only protected administrators may manage roles.',403);
    $own=!$pdo->inTransaction();if($own)$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT * FROM portal_access_roles WHERE id=?' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([$id]);$role=$q->fetch();
        if(!$role)throw new DomainException('Role not found.',404);
        if((int)$role['is_builtin']===1)throw new DomainException('Built-in roles cannot be deleted.',409);
        $q=$pdo->prepare('SELECT COUNT(*) FROM portal_user_roles WHERE role_id=?');$q->execute([$id]);
        if((int)$q->fetchColumn()>0)throw new DomainException('Reassign all users before deleting this role.',409);
        $role['permissions']=portal_role_permissions($pdo,$id);
        $pdo->prepare('DELETE FROM portal_role_permissions WHERE role_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM portal_access_roles WHERE id=?')->execute([$id]);
        portal_access_audit($pdo,(int)$actor['id'],'role.delete',$id,$role);
        if($own)$pdo->commit();
    }catch(Throwable $e){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function portal_assign_role(PDO $pdo, array $actor, int $userId, int $roleId, string $operation='update', mixed $extraPlantInput=null, mixed $customInput=null): void
{
    $access=portal_user_access($pdo,$actor);
    if(!in_array($operation,['create','update'],true) || !portal_can($access['permissions'],'users',$operation))throw new DomainException('User role assignment is not permitted.',403);
    if($userId===(int)$actor['id'])throw new DomainException('You cannot change your own role.',409);
    $own=!$pdo->inTransaction();if($own)$pdo->beginTransaction();
    try{
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $q=$pdo->prepare('SELECT * FROM portal_access_roles WHERE id=?'.$lock);$q->execute([$roleId]);$role=$q->fetch();
        if(!$role)throw new DomainException('Role not found.',404);
        if(!portal_grants_within(portal_role_permissions($pdo,$roleId),$access['permissions']))throw new DomainException('You cannot assign permissions beyond your own.',403);
        $roleScope=portal_role_plant_scope($pdo,$roleId);
        $custom=is_array($customInput)&&($customInput['enabled']??false)===true;
        $customPermissions=null;$customPlants=null;
        if($custom){
            if(!portal_user_override_tables_ready($pdo))throw new DomainException('Install the user access override migration before assigning custom access.',503);
            if(!isset($customInput['permissions'])||!is_array($customInput['permissions']))throw new InvalidArgumentException('Custom permissions must be a page/action object.');
            $customPermissions=portal_validate_permissions($customInput['permissions']);
            if(in_array(true,$customPermissions['roles'],true))throw new DomainException('Role administration is reserved for protected administrators.',403);
            if(!portal_grants_within($customPermissions,$access['permissions']))throw new DomainException('You cannot assign permissions beyond your own.',403);
            $customPlants=portal_normalize_plants($pdo,$customInput['plants']??[],true);
            if($customPlants===[])throw new InvalidArgumentException('Select All Plants or at least one plant for custom access.');
            $targetScope=['all_plants'=>in_array(PORTAL_ALL_PLANTS,$customPlants,true),'plants'=>array_values(array_filter($customPlants,static fn(string $plant):bool=>$plant!==PORTAL_ALL_PLANTS))];
            $extra=[];
        }else{
            $extra=$extraPlantInput===null?null:portal_normalize_plants($pdo,$extraPlantInput,false);
            $targetScope=$roleScope;
            if(!$targetScope['all_plants'] && $extra!==null)$targetScope['plants']=array_values(array_unique(array_merge($targetScope['plants'],$extra)));
        }
        if(!portal_scope_within($targetScope,$access))throw new DomainException('You cannot assign plant access beyond your own.',403);
        $q=$pdo->prepare('SELECT id,role FROM portal_users WHERE id=?'.$lock);$q->execute([$userId]);$target=$q->fetch();
        if(!$target)throw new DomainException('User not found.',404);
        if($target['role']==='admin')throw new DomainException('Administrator accounts are protected.',409);
        if($operation!=='create' && $actor['role']!=='admin'){$currentAccess=portal_user_access($pdo,$target);if(!portal_grants_within($currentAccess['permissions'],$access['permissions'])||!portal_scope_within($currentAccess,$access))throw new DomainException('You cannot manage an account beyond your permissions or plant access.',403);}
        $before=portal_user_access($pdo,$target);
        $sql='INSERT INTO portal_user_roles(user_id,role_id) VALUES (?,?) ';
        $sql.=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'ON CONFLICT(user_id) DO UPDATE SET role_id=excluded.role_id':'ON DUPLICATE KEY UPDATE role_id=VALUES(role_id)';
        $pdo->prepare($sql)->execute([$userId,$roleId]);
        if(($extra!==null||$custom) && portal_plant_tables_ready($pdo)){
            $pdo->prepare('DELETE FROM portal_user_plants WHERE user_id=?')->execute([$userId]);
            $plantInsert=$pdo->prepare('INSERT INTO portal_user_plants(user_id,plant_code) VALUES (?,?)');
            foreach(($custom?$customPlants:$extra) as $plant)$plantInsert->execute([$userId,$plant]);
        }
        if(portal_user_override_tables_ready($pdo)){
            $pdo->prepare('DELETE FROM portal_user_permissions WHERE user_id=?')->execute([$userId]);
            if($custom){
                $permissionInsert=$pdo->prepare('INSERT INTO portal_user_permissions(user_id,page_code,action_code) VALUES (?,?,?)');
                foreach($customPermissions as $page=>$actions)foreach($actions as $action=>$allow)if($allow)$permissionInsert->execute([$userId,$page,$action]);
            }
            $sql='INSERT INTO portal_user_access_overrides(user_id,custom_access) VALUES (?,?) ';
            $sql.=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'ON CONFLICT(user_id) DO UPDATE SET custom_access=excluded.custom_access':'ON DUPLICATE KEY UPDATE custom_access=VALUES(custom_access)';
            $pdo->prepare($sql)->execute([$userId,$custom?1:0]);
        }
        $pdo->prepare('UPDATE portal_users SET auth_version=auth_version+1 WHERE id=?')->execute([$userId]);
        portal_access_audit($pdo,(int)$actor['id'],'user.role',$userId,['before'=>$before,'role_id'=>$roleId,'extra_plants'=>$extra,'custom_access'=>$custom,'custom_plants'=>$customPlants,'custom_permissions'=>$customPermissions]);
        if($own)$pdo->commit();
    }catch(Throwable $e){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function portal_set_user_active(PDO $pdo, array $actor, int $userId, bool $active): bool
{
    if($userId===(int)$actor['id'])throw new DomainException('You cannot change your own account status.',409);
    $access=portal_user_access($pdo,$actor);
    if(!portal_can($access['permissions'],'users','update'))throw new DomainException('User updates are not permitted.',403);
    $own=!$pdo->inTransaction();if($own)$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT id,role,is_active FROM portal_users WHERE id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([$userId]);$target=$q->fetch();
        if(!$target)throw new DomainException('User not found.',404);
        if($target['role']==='admin')throw new DomainException('Administrator accounts are protected.',409);
        $targetAccess=portal_user_access($pdo,$target);
        if(!portal_grants_within($targetAccess['permissions'],$access['permissions'])||!portal_scope_within($targetAccess,$access))throw new DomainException('You cannot manage an account beyond your permissions or plant access.',403);
        $activeValue=$active?1:0;
        $changed=(int)$target['is_active']!==$activeValue;
        if($changed){
            $pdo->prepare('UPDATE portal_users SET is_active=?,auth_version=auth_version+1 WHERE id=?')->execute([$activeValue,$userId]);
            portal_access_audit($pdo,(int)$actor['id'],'user.status',$userId,['before'=>(int)$target['is_active'],'is_active'=>$activeValue]);
        }
        if($own)$pdo->commit(); return $changed;
    }catch(Throwable $e){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function portal_toggle_user(PDO $pdo, array $actor, int $userId): void
{
    $query=$pdo->prepare('SELECT is_active FROM portal_users WHERE id=?');$query->execute([$userId]);
    $current=$query->fetchColumn();
    if($current===false)throw new DomainException('User not found.',404);
    portal_set_user_active($pdo,$actor,$userId,(int)$current!==1);
}
