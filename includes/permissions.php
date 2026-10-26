<?php
declare(strict_types=1);

function portal_permission_catalog(): array
{
    return [
        'dashboard' => ['label'=>'OEE dashboard','actions'=>['view']],
        'oee-range' => ['label'=>'Date-range OEE','actions'=>['view','export']],
        'oee-report' => ['label'=>'Production report / Excel export','actions'=>['view','export']],
        'scada-logs' => ['label'=>'SCADA logs','actions'=>['view']],
        'downtime' => ['label'=>'Downtime review','actions'=>['view','create','update']],
        'records' => ['label'=>'Records','actions'=>['view','create','update','delete']],
        'users' => ['label'=>'Users (deactivate = Update)','actions'=>['view','create','update']],
        'oee-masters' => ['label'=>'OEE master data','actions'=>['view','create','update']],
        'profile' => ['label'=>'My profile / password','actions'=>['view','update']],
        'roles' => ['label'=>'Roles & permissions (administrators only)','actions'=>['view','create','update','delete']],
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
        if($resource==='oee'){
            $pages=['dashboard'=>'dashboard','range-dashboard'=>'oee-range','range-export'=>'oee-range','report'=>'oee-report','export'=>'oee-report','logs'=>'scada-logs','downtime'=>'downtime','masters'=>'oee-masters'];
            if(isset($pages[$action]))return [$pages[$action],in_array($action,['export','range-export'],true)?'export':'view'];
        }
    }
    if($method==='POST'){
        if($resource==='oee'&&$action==='downtime-tag')return ['downtime','update'];
        if($resource==='oee'&&$action==='downtime-save')return ['downtime',filter_var($data['event_id']??null,FILTER_VALIDATE_INT)?'update':'create'];
        if($resource==='oee'&&$action==='master-save')return ['oee-masters',($data['master_type']??null)==='threshold'||filter_var($data['id']??null,FILTER_VALIDATE_INT)?'update':'create'];
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

function portal_user_access(PDO $pdo, array $user): array
{
    if ($user['role'] === 'admin') return ['role_id'=>0,'role_name'=>'Administrator','system_admin'=>true,'permissions'=>portal_permission_matrix(true)];
    $q=$pdo->prepare('SELECT ur.role_id,r.name FROM portal_user_roles ur LEFT JOIN portal_access_roles r ON r.id=ur.role_id WHERE ur.user_id=?'); $q->execute([$user['id']]);
    $role=$q->fetch();
    // An explicitly assigned but missing role must never fall back to broader access.
    if (!$role) $role=$pdo->query("SELECT id AS role_id,name FROM portal_access_roles WHERE is_builtin=1 AND name='Standard user'")->fetch();
    $id=$role ? (int)$role['role_id'] : null;
    return ['role_id'=>$id,'role_name'=>$role['name'] ?? 'No access','system_admin'=>false,
        'permissions'=>$role && $role['name'] !== null ? portal_role_permissions($pdo,$id) : portal_permission_matrix()];
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
    foreach($grants as $grant) $byRole[$grant['role_id']][$grant['page_code']][$grant['action_code']]=true;
    foreach($roles as &$role) $role['permissions']=array_replace_recursive(portal_permission_matrix(),$byRole[$role['id']] ?? []);
    unset($role);
    return $roles;
}

function portal_save_role(PDO $pdo, array $actor, ?int $id, string $name, array $input): int
{
    if ($actor['role'] !== 'admin') throw new DomainException('Only protected administrators may manage roles.',403);
    $name=trim($name);
    if ($name==='' || mb_strlen($name)>80 || preg_match('/[\x00-\x1F\x7F]/u',$name)) throw new InvalidArgumentException('Enter a role name of 1–80 characters.');
    if(strcasecmp($name,'Administrator')===0)throw new InvalidArgumentException('Administrator is a reserved role name.');
    $matrix=portal_validate_permissions($input);
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
            $pdo->prepare('UPDATE portal_access_roles SET name=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$name,$id]);
            $pdo->prepare('DELETE FROM portal_role_permissions WHERE role_id=?')->execute([$id]);
        } else {
            if((int)$pdo->query('SELECT COUNT(*) FROM portal_access_roles')->fetchColumn()>=100)throw new DomainException('Role limit reached (100).',409);
            $pdo->prepare('INSERT INTO portal_access_roles(name,created_by) VALUES (?,?)')->execute([$name,$actor['id']]); $id=(int)$pdo->lastInsertId();
        }
        $insert=$pdo->prepare('INSERT INTO portal_role_permissions(role_id,page_code,action_code) VALUES (?,?,?)');
        foreach($matrix as $page=>$actions)foreach($actions as $action=>$allow)if($allow)$insert->execute([$id,$page,$action]);
        portal_access_audit($pdo,(int)$actor['id'],$before?'role.update':'role.create',$id,['before'=>$before,'name'=>$name,'permissions'=>$matrix]);
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

function portal_assign_role(PDO $pdo, array $actor, int $userId, int $roleId, string $operation='update'): void
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
        $q=$pdo->prepare('SELECT id,role FROM portal_users WHERE id=?'.$lock);$q->execute([$userId]);$target=$q->fetch();
        if(!$target)throw new DomainException('User not found.',404);
        if($target['role']==='admin')throw new DomainException('Administrator accounts are protected.',409);
        if($operation!=='create' && $actor['role']!=='admin' && !portal_grants_within(portal_user_access($pdo,$target)['permissions'],$access['permissions']))throw new DomainException('You cannot manage a higher-privileged account.',403);
        $before=portal_user_access($pdo,$target);
        $sql='INSERT INTO portal_user_roles(user_id,role_id) VALUES (?,?) ';
        $sql.=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'ON CONFLICT(user_id) DO UPDATE SET role_id=excluded.role_id':'ON DUPLICATE KEY UPDATE role_id=VALUES(role_id)';
        $pdo->prepare($sql)->execute([$userId,$roleId]);
        $pdo->prepare('UPDATE portal_users SET auth_version=auth_version+1 WHERE id=?')->execute([$userId]);
        portal_access_audit($pdo,(int)$actor['id'],'user.role',$userId,['before_role'=>$before['role_id'],'role_id'=>$roleId]);
        if($own)$pdo->commit();
    }catch(Throwable $e){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function portal_toggle_user(PDO $pdo, array $actor, int $userId): void
{
    if($userId===(int)$actor['id'])throw new DomainException('You cannot deactivate your own account.',409);
    $access=portal_user_access($pdo,$actor);
    if(!portal_can($access['permissions'],'users','update'))throw new DomainException('User updates are not permitted.',403);
    $own=!$pdo->inTransaction();if($own)$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT id,role,is_active FROM portal_users WHERE id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([$userId]);$target=$q->fetch();
        if(!$target)throw new DomainException('User not found.',404);
        if($target['role']==='admin')throw new DomainException('Administrator accounts are protected.',409);
        if(!portal_grants_within(portal_user_access($pdo,$target)['permissions'],$access['permissions']))throw new DomainException('You cannot manage a higher-privileged account.',403);
        $active=(int)$target['is_active']===1?0:1;
        $pdo->prepare('UPDATE portal_users SET is_active=?,auth_version=auth_version+1 WHERE id=?')->execute([$active,$userId]);
        portal_access_audit($pdo,(int)$actor['id'],'user.status',$userId,['before'=>(int)$target['is_active'],'is_active'=>$active]);
        if($own)$pdo->commit();
    }catch(Throwable $e){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
