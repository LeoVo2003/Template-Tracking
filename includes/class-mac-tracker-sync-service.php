<?php
defined( 'ABSPATH' ) || exit;

class MAC_Tracker_Sync_Service {
	private $repo; private $client;
	public function __construct(MAC_Tracker_Repository $repo, MAC_Tracker_WPM_Client $client){$this->repo=$repo;$this->client=$client;}
	public function full_sync(array $filters=array()){
		$started=MAC_Tracker_Time::now_utc();$result=$this->client->fetch_all($filters);if(is_wp_error($result)){$this->repo->log('failed',0,$result->get_error_message(),$started);return $result;}$seen=array();$processed=0;$created=0;
		foreach($result['items'] as $raw){$p=MAC_Tracker_Normalizer::project($raw);if(!$p||$p['id']<MAC_TRACKER_MIN_WPM_PROJECT_ID)continue;$seen[]=$p['id'];$r=$this->sync_project($p);if(!is_wp_error($r)){$processed++;$created+=(int)$r;}}
		$back=$this->backfill($seen);$this->repo->log('success',$processed,'pages='.$result['pages'].' created='.$created.' backfill='.$back,$started);return array('pages'=>$result['pages'],'processed'=>$processed,'created'=>$created,'backfilled'=>$back,'total'=>$result['total']);
	}
	public function sync_project(array $p){$created=0;$base=array('wpm_project_id'=>$p['id'],'name'=>$p['name'],'zip_code'=>$p['zipcode'],'package'=>$p['package'],'account_manager'=>$p['account_manager'],'assignee'=>$p['assignee'],'domain'=>$p['domain'],'status'=>$p['status'],'sync_source'=>'real','raw'=>$p['raw']);if($p['id']<MAC_TRACKER_ACTION_TASK_ERA_ID){$r=$this->repo->upsert_snapshot($base+array('record_kind'=>'domain','website_url'=>$p['domain'],'layout_url'=>$p['layout'],'pin_position'=>0));if(!is_wp_error($r)&&!empty($r['created']))$created++;}foreach($p['tasks'] as $t){if(!$t['is_action_design']||!$this->done($t['status'])||!$this->after_cutoff($t['completed_at']))continue;$r=$this->repo->upsert_snapshot($base+array('record_kind'=>'action_design','wpm_action_task_id'=>$t['id'],'website_url'=>$t['demo_url']?:$p['domain'],'layout_url'=>$t['layout_url']?:$p['layout'],'template_color_raw'=>$t['color_template'],'task_completed_at'=>MAC_Tracker_Time::normalize_utc($t['completed_at'])));if(!is_wp_error($r)&&!empty($r['created']))$created++;}return $created;}
	private function done($s){return in_array(strtolower(trim((string)$s)),array('done','completed','complete'),true);}
	private function after_cutoff($v){return MAC_Tracker_Time::on_or_after_bangkok_date($v,MAC_TRACKER_AD_COMPLETED_CUTOFF);}
	private function backfill(array $seen){$missing=$this->repo->missing_pin_ids($seen);if(!$missing)return 0;$cursor=$this->repo->cursor();$start=0;foreach($missing as $i=>$id){if($id>$cursor){$start=$i;break;}}$count=0;$n=count($missing);for($i=0;$i<min(150,$n);$i++){$idx=($start+$i)%$n;$id=(int)$missing[$idx];$raw=$this->client->fetch_project_by_id($id);if(!is_wp_error($raw)){$p=MAC_Tracker_Normalizer::project($raw);if($p){$this->sync_project($p);$count++;}}$this->repo->set_cursor($id);}return $count;}
}
