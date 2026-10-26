<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/permissions.php';
require_once dirname(__DIR__).'/includes/export_permission_migration.php';
function export_permission_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
export_permission_check(portal_api_requirement('oee','export','GET')===['oee-report','export'],'Daily download must require separate Export permission');
export_permission_check(portal_api_requirement('oee','range-export','GET')===['oee-range','export'],'Range download must require separate Export permission');
foreach(['oee-report','oee-range'] as $page){
    $view=portal_validate_permissions([$page=>['view'=>true]]);
    export_permission_check(portal_can($view,$page)&&!portal_can($view,$page,'export'),'View alone must not export');
    $both=portal_validate_permissions([$page=>['view'=>true,'export'=>true]]);
    export_permission_check(portal_can($both,$page,'export'),'View+Export must allow downloads');
    $failed=false;try{portal_validate_permissions([$page=>['export'=>true]]);}catch(InvalidArgumentException $e){$failed=true;}
    export_permission_check($failed,'Export without View must be rejected');
    export_permission_check(portal_can(portal_standard_permissions(),$page,'export'),'Standard user loses prior export access');
}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE portal_schema_migrations (migration_code TEXT PRIMARY KEY, installed_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE portal_role_permissions (role_id INTEGER,page_code TEXT,action_code TEXT,PRIMARY KEY(role_id,page_code,action_code))');
$pdo->exec("INSERT INTO portal_role_permissions VALUES (1,'oee-report','view'),(1,'oee-range','view'),(2,'oee-report','view'),(3,'records','view')");
export_permission_check(portal_migrate_export_permissions($pdo),'Initial export migration did not run');
export_permission_check((int)$pdo->query("SELECT COUNT(*) FROM portal_role_permissions WHERE action_code='export'")->fetchColumn()===3,'Migration must preserve prior access per report');
$pdo->exec("DELETE FROM portal_role_permissions WHERE role_id=2 AND action_code='export'");
$pdo->exec("INSERT INTO portal_role_permissions VALUES (4,'oee-report','view')");
$before=$pdo->query('SELECT * FROM portal_role_permissions ORDER BY role_id,page_code,action_code')->fetchAll();
export_permission_check(!portal_migrate_export_permissions($pdo),'Migration rerun must be a no-op');
export_permission_check($before===$pdo->query('SELECT * FROM portal_role_permissions ORDER BY role_id,page_code,action_code')->fetchAll(),'Migration rerun restored revoked access or changed a new role');
echo "Independent export permission and one-time migration tests passed\n";
