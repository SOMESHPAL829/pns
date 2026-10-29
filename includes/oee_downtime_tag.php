<?php
declare(strict_types=1);
require_once __DIR__.'/permissions.php';
require_once __DIR__.'/oee_settings.php';

/** Classify an existing stop without rewriting its interval or SCADA evidence. */
function oee_tag_downtime(PDO $pdo,array $data,array $permissions): array
{
    if(!portal_can($permissions,'downtime','update'))throw new DomainException('You do not have update permission for downtime.',403);
    $id=filter_var($data['event_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!$id || is_bool($data['event_id']??null))throw new InvalidArgumentException('Choose an existing downtime event.');
    $fields=[];
    foreach(['machine'=>20,'event_type'=>20,'reason_code'=>40,'notes'=>500] as $key=>$limit){
        $value=$data[$key]??'';
        if(!is_string($value)||mb_strlen(trim($value),'UTF-8')>$limit)throw new InvalidArgumentException('Invalid '.str_replace('_',' ',$key).'.');
        $fields[$key]=trim($value);
    }
    $machine=strtoupper($fields['machine']);$reason=strtoupper($fields['reason_code']);
    if(!oee_line_code_ok($machine))throw new InvalidArgumentException('Choose one valid machine.');
    if(!in_array($fields['event_type'],['planned','unplanned'],true))throw new InvalidArgumentException('Choose planned or unplanned downtime.');
    $q=$pdo->prepare('SELECT code FROM oee_reason_codes WHERE code=? AND loss_type=? AND is_active=1');$q->execute([$reason,$fields['event_type']]);
    if($q->fetchColumn()===false)throw new InvalidArgumentException('Choose an active registered downtime reason.');
    $own=!$pdo->inTransaction();if($own)$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT id FROM oee_downtime_events WHERE id=? AND line_code=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
        $q->execute([$id,$machine]);
        if(!$q->fetchColumn())throw new DomainException('Downtime event not found for this machine.',404);
        $pdo->prepare("UPDATE oee_downtime_events SET event_type=?,reason_code=?,notes=?,status=CASE WHEN status='proposed' THEN 'confirmed' ELSE status END,updated_at=CURRENT_TIMESTAMP WHERE id=? AND line_code=?")
            ->execute([$fields['event_type'],$reason,$fields['notes'],$id,$machine]);
        if($own)$pdo->commit();
        return ['ok'=>true,'event_id'=>(int)$id,'updated'=>true];
    }catch(Throwable $e){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
