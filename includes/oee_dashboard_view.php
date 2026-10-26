<?php
declare(strict_types=1);
require_once __DIR__.'/permissions.php';

/** Read-only presentation of clipped stop unions; never changes OEE inputs. */
function oee_dashboard_breakdown(array $window,array $events): array
{
    $start=strtotime($window['window_start']);$end=strtotime($window['window_end']);
    $lines=[];$buckets=[];$totals=['planned'=>0.0,'unplanned'=>0.0];
    foreach($events as $index=>$event){
        if(!in_array($event['status'],['proposed','confirmed','approved'],true))continue;
        $a=max($start,strtotime($event['starts_at']));$b=min($end,strtotime($event['ends_at']));if($b<=$a)continue;
        $event['event_type']=$event['event_type']==='planned'?'planned':'unplanned';
        $line=$event['line_code'];$lines[$line][$a]['start'][$index]=$event;$lines[$line][$b]['end'][]=$index;
    }
    foreach($lines as $edges){
        ksort($edges,SORT_NUMERIC);$times=array_keys($edges);$active=[];
        foreach($times as $position=>$time){
            foreach($edges[$time]['end']??[] as $index)unset($active[$index]);
            foreach($edges[$time]['start']??[] as $index=>$event)$active[$index]=$event;
            if(!$active||!isset($times[$position+1]))continue;
            $owner=null;$ownerRank=null;
            foreach($active as $event){
                $rank=[$event['event_type']==='unplanned'?0:1,['approved'=>0,'confirmed'=>1,'proposed'=>2][$event['status']],$event['source']==='manual'?0:1,$event['starts_at'],(int)($event['id']??0),$event['reason_code']];
                if($ownerRank===null||$rank<$ownerRank){$owner=$event;$ownerRank=$rank;}
            }
            $minutes=($times[$position+1]-$time)/60;$type=$owner['event_type'];$code=$owner['reason_code']?:'UNK';$key=$type.'|'.$code;
            $buckets[$key]??=['reason_code'=>$code,'label'=>($owner['reason_label']??'')?:$code,'event_type'=>$type,'minutes'=>0.0];
            $buckets[$key]['minutes']+=$minutes;$totals[$type]+=$minutes;
        }
    }
    $total=array_sum($totals);$items=array_values($buckets);
    usort($items,static fn(array $a,array $b):int=>($b['minutes']<=>$a['minutes'])?:strcmp($a['label'],$b['label']));
    foreach($items as &$item){$item['percentage']=$total>0?round($item['minutes']/$total*100,3):0.0;$item['minutes']=round($item['minutes'],3);}unset($item);
    return ['items'=>$items,'total_minutes'=>round($total,3),'planned_minutes'=>round($totals['planned'],3),'unplanned_minutes'=>round($totals['unplanned'],3)];
}

function oee_dashboard_view(PDO $pdo,array $dashboard,array $permissions): array
{
    if(empty($dashboard['available']))return $dashboard;
    $batches=portal_can($permissions,'oee-report');$downtime=portal_can($permissions,'downtime');
    $dashboard['detail_access']=['batches'=>$batches,'downtime'=>$downtime];
    $lines=$dashboard['machine']==='ALL'?['APP-06','APP-07','APP-10']:[$dashboard['machine']];
    $in=implode(',',array_fill(0,count($lines),'?'));$window=$dashboard['window'];
    if($batches){
        $q=$pdo->prepare('SELECT line_code,seq,part_code,grade,used_colour,report_colour,loading_at,unloading_at,pcs,total_surface_area,is_productive FROM oee_fb_runs WHERE line_code IN ('.$in.') AND loading_at>=? AND loading_at<? ORDER BY loading_at DESC,line_code,si_no DESC LIMIT 6');
        $q->execute([...$lines,$window['window_start'],$window['window_end']]);$dashboard['recent_batches']=$q->fetchAll();
    }
    if($downtime){
        $q=$pdo->prepare("SELECT e.id,e.line_code,e.starts_at,e.ends_at,e.event_type,e.source,e.status,e.reason_code,e.notes,COALESCE(r.label,e.reason_code) AS reason_label FROM oee_downtime_events e LEFT JOIN oee_reason_codes r ON r.code=e.reason_code WHERE e.line_code IN (".$in.') AND e.starts_at<? AND e.ends_at>? ORDER BY e.ends_at DESC,e.id DESC');
        $q->execute([...$lines,$window['window_end'],$window['window_start']]);$events=$q->fetchAll();
        $dashboard['downtime_breakdown']=oee_dashboard_breakdown($window,$events);$dashboard['recent_downtime']=array_slice($events,0,6);
    }
    return $dashboard;
}
