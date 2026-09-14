<?php
defined( 'ABSPATH' ) || exit;

class MAC_Tracker_Repository {
	private $wpdb; private $projects; private $pins; private $logs;
	public function __construct() { global $wpdb; $this->wpdb=$wpdb; $this->projects=$wpdb->prefix.'mac_tracker_projects'; $this->pins=$wpdb->prefix.'mac_tracker_pinned_projects'; $this->logs=$wpdb->prefix.'mac_tracker_sync_logs'; }
	private function json( $v ) { return wp_json_encode( $v ); }
	public function upsert_snapshot( array $s ) {
		$id=(int)($s['wpm_project_id']??0); $kind=(string)($s['record_kind']??''); $task=(int)($s['wpm_action_task_id']??0);
		if($id<=0 || !in_array($kind,array('domain','csv_pin','action_design'),true)) return new WP_Error('mac_tracker_snapshot','Invalid snapshot.');
		$found=$this->wpdb->get_var($this->wpdb->prepare("SELECT id FROM {$this->projects} WHERE wpm_project_id=%d AND record_kind=%s AND wpm_action_task_id=%d",$id,$kind,$task));
		$now=MAC_Tracker_Time::now_utc();
		$data=array('name'=>(string)($s['name']??''),'zip_code'=>(string)($s['zip_code']??''),'package_json'=>$this->json($s['package']??''),'account_manager_json'=>$this->json($s['account_manager']??null),'assignee_json'=>$this->json($s['assignee']??null),'domain'=>(string)($s['domain']??''),'layout_url'=>(string)($s['layout_url']??''),'website_url'=>(string)($s['website_url']??''),'status'=>(string)($s['status']??''),'template_color_raw'=>(string)($s['template_color_raw']??''),'sync_source'=>(string)($s['sync_source']??'real'),'task_completed_at'=>$s['task_completed_at']??null,'pin_position'=>(int)($s['pin_position']??0),'updated_at'=>$now);
		if($found){
			// Snapshots are immutable. Only display labels may be refreshed; the
			// original domain/layout/task/palette payload stays frozen forever.
			$labels=array('name'=>$data['name'],'zip_code'=>$data['zip_code'],'package_json'=>$data['package_json'],'account_manager_json'=>$data['account_manager_json'],'assignee_json'=>$data['assignee_json'],'updated_at'=>$now);
			$this->wpdb->update($this->projects,$labels,array('id'=>(int)$found));
			return array('id'=>(int)$found,'created'=>false);
		}
		$data['wpm_project_id']=$id;$data['record_kind']=$kind;$data['wpm_action_task_id']=$task;$data['raw_payload']=$this->json($s['raw']??array());$data['created_at']=$now;
		if(false===$this->wpdb->insert($this->projects,$data)) return new WP_Error('mac_tracker_insert',$this->wpdb->last_error);
		return array('id'=>(int)$this->wpdb->insert_id,'created'=>true);
	}
	public function upsert_pin(array $r){$id=(int)($r['wpm_project_id']??0);if($id<=0)return new WP_Error('mac_tracker_pin','Invalid pin ID.');$now=MAC_Tracker_Time::now_utc();$data=array('website_url'=>(string)($r['website_url']??''),'layout_url'=>(string)($r['layout_url']??''),'assignee_name'=>(string)($r['assignee_name']??''),'projects_raw'=>(string)($r['projects_raw']??''),'task_completed_at'=>$r['task_completed_at']??null,'pin_position'=>(int)($r['pin_position']??0),'updated_at'=>$now);$found=$this->wpdb->get_var($this->wpdb->prepare("SELECT id FROM {$this->pins} WHERE wpm_project_id=%d",$id));if($found){$this->wpdb->update($this->pins,$data,array('id'=>(int)$found));}else{$data['wpm_project_id']=$id;$data['created_at']=$now;$this->wpdb->insert($this->pins,$data);}return (int)($found?:$this->wpdb->insert_id);}
	public function pin_ids(){return array_map('intval',(array)$this->wpdb->get_col("SELECT wpm_project_id FROM {$this->pins} ORDER BY pin_position ASC,id ASC"));}
	public function missing_pin_ids(array $seen){$all=$this->pin_ids();$seen=array_map('intval',$seen);return array_values(array_diff($all,$seen));}
	public function cursor(){return (int)get_option('mac_tracker_backfill_cursor',0);}
	public function set_cursor($id){update_option('mac_tracker_backfill_cursor',(int)$id,false);}
	public function list_snapshots($limit=0){$colors=$this->wpdb->prefix.'mac_tracker_color_records';$sql="SELECT p.*,c.status AS color_status,c.colors_json,c.approved_at,c.locked AS color_locked FROM {$this->projects} p LEFT JOIN {$colors} c ON c.project_id=p.id ORDER BY p.pin_position ASC,p.id ASC";if($limit>0)$sql.=$this->wpdb->prepare(' LIMIT %d',(int)$limit);return (array)$this->wpdb->get_results($sql,ARRAY_A);}
	public function count_snapshots(){return (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$this->projects}");}
	public function upsert_color_record($project_id,$source_type,$source_raw,array $colors=array(),$status='pending'){$table=$this->wpdb->prefix.'mac_tracker_color_records';$project_id=(int)$project_id;$existing=$this->wpdb->get_row($this->wpdb->prepare("SELECT id,locked FROM {$table} WHERE project_id=%d",$project_id),ARRAY_A);if($existing&& !empty($existing['locked']))return array('id'=>(int)$existing['id'],'locked'=>true,'changed'=>false);$now=MAC_Tracker_Time::now_utc();$data=array('source_type'=>(string)$source_type,'source_raw'=>(string)$source_raw,'status'=>(string)$status,'colors_json'=>$this->json(array_values($colors)),'updated_at'=>$now);if($existing){$this->wpdb->update($table,$data,array('id'=>(int)$existing['id']));return array('id'=>(int)$existing['id'],'locked'=>false,'changed'=>true);} $data['project_id']=$project_id;$data['created_at']=$now;$this->wpdb->insert($table,$data);return array('id'=>(int)$this->wpdb->insert_id,'locked'=>false,'changed'=>true);}
	public function approve_color_record($id,array $colors=array()){$table=$this->wpdb->prefix.'mac_tracker_color_records';$id=(int)$id;$locked=$this->wpdb->get_var($this->wpdb->prepare("SELECT locked FROM {$table} WHERE id=%d",$id));if(null===$locked)return new WP_Error('mac_tracker_color','Color record not found.');if((int)$locked)return true;$this->wpdb->update($table,array('status'=>'approved','colors_json'=>$this->json(array_values($colors)),'approved_at'=>MAC_Tracker_Time::now_utc(),'locked'=>1,'updated_at'=>MAC_Tracker_Time::now_utc()),array('id'=>$id));return true;}
	public function log($status,$processed,$message='',$started=null){$this->wpdb->insert($this->logs,array('status'=>$status,'processed'=>(int)$processed,'message'=>$message,'started_at'=>$started?:MAC_Tracker_Time::now_utc(),'finished_at'=>MAC_Tracker_Time::now_utc()));}
}
