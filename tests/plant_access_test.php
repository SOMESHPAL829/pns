<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/permissions.php';

function plant_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE portal_users(id INTEGER PRIMARY KEY,role TEXT,is_active INTEGER DEFAULT 1,auth_version INTEGER DEFAULT 0);CREATE TABLE portal_access_roles(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT UNIQUE,is_builtin INTEGER DEFAULT 0,created_by INTEGER,created_at TEXT,updated_at TEXT);CREATE TABLE portal_role_permissions(role_id INTEGER,page_code TEXT,action_code TEXT,PRIMARY KEY(role_id,page_code,action_code));CREATE TABLE portal_user_roles(user_id INTEGER PRIMARY KEY,role_id INTEGER);CREATE TABLE portal_access_audit(id INTEGER PRIMARY KEY,actor_id INTEGER,event_type TEXT,target_id INTEGER,details TEXT,created_at TEXT);CREATE TABLE portal_role_plants(role_id INTEGER,plant_code TEXT,PRIMARY KEY(role_id,plant_code));CREATE TABLE portal_user_plants(user_id INTEGER,plant_code TEXT,PRIMARY KEY(user_id,plant_code));CREATE TABLE oee_import_batches(line_code TEXT);');
$pdo->exec("INSERT INTO oee_import_batches VALUES('APP-06'),('APP-07'),('APP-10');INSERT INTO portal_users(id,role) VALUES(1,'admin'),(2,'user');");
$admin=['id'=>1,'role'=>'admin'];$matrix=portal_permission_matrix();$matrix['dashboard']['view']=true;
$roleId=portal_save_role($pdo,$admin,null,'Manager',$matrix,['APP-06','APP-07']);
portal_assign_role($pdo,$admin,2,$roleId,'update',['APP-10']);
$access=portal_user_access($pdo,['id'=>2,'role'=>'user']);
plant_assert(!$access['all_plants'],'The restricted role must not become All Plants.');
plant_assert($access['plants']===['APP-06','APP-07','APP-10'],'Role plants and user additions must be combined.');
plant_assert(portal_can_plant($access,'APP-10')&&!portal_can_plant($access,'APP-03'),'Plant authorization must enforce the combined scope.');
echo "Role defaults, per-user additions, and plant authorization tests passed\n";
