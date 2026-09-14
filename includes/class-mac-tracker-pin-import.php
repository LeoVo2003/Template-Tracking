<?php
defined( 'ABSPATH' ) || exit;

class MAC_Tracker_Pin_Import {
	private $repo;
	public function __construct( MAC_Tracker_Repository $repo ) { $this->repo=$repo; }
	public function import_file( $path ) {
		if ( ! is_readable( $path ) ) return new WP_Error( 'mac_tracker_csv', 'Pin CSV is not readable.' );
		$fh=fopen($path,'rb'); if(!$fh)return new WP_Error('mac_tracker_csv','Could not open pin CSV.');
		$headers=fgetcsv($fh); if(!$headers){fclose($fh);return new WP_Error('mac_tracker_csv','Pin CSV is empty.');}
		if(isset($headers[0]))$headers[0]=preg_replace('/^\xEF\xBB\xBF/','',$headers[0]);$headers=array_map(array($this,'header'),$headers);$count=0;$errors=array();$row=0;
		while(false!==($values=fgetcsv($fh))){$row++;$data=array();foreach($headers as $i=>$h)$data[$h]=isset($values[$i])?trim($values[$i]):'';$id=(int)($data['project_id']??0);if($id<=0){$errors[]='row '.$row.': missing project_id';continue;}$pin=$this->repo->upsert_pin(array('wpm_project_id'=>$id,'website_url'=>$data['website']??'','projects_raw'=>$data['projects']??'','assignee_name'=>$data['member']??'','task_completed_at'=>MAC_Tracker_Time::csv_bangkok_to_utc($data['date']??'',$data['time']??''),'pin_position'=>$row));$snapshot=$this->repo->upsert_snapshot(array('wpm_project_id'=>$id,'record_kind'=>'csv_pin','name'=>$data['projects']??'','website_url'=>$data['website']??'','assignee'=>array('name'=>$data['member']??''),'task_completed_at'=>MAC_Tracker_Time::csv_bangkok_to_utc($data['date']??'',$data['time']??''),'pin_position'=>$row,'sync_source'=>'csv_pin','raw'=>$data));if(is_wp_error($pin)||is_wp_error($snapshot)){$errors[]='row '.$row.': database write failed';continue;}$count++;}
		fclose($fh);return array('imported'=>$count,'errors'=>$errors);
	}
	private function header($v){$v=strtolower(trim((string)$v));$v=preg_replace('/[^a-z0-9]+/','_', $v);return trim($v,'_');}
}
