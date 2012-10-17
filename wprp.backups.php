<?php

class WPRP_Backups {

	private static $instance;

	public static function getInstance() {

		if ( empty( self::$instance ) )
			self::$instance = new WPRP_Backups();

		return self::$instance;
	}

	/**
	 * Do a backup of the site
	 * 
	 * @return true|WP_Error
	 */
	public function doBackup() {
		@ignore_user_abort( true );

		$schedule = $this->getManualBackupSchedule();
		
		$schedule->run();		
		
		$filepath = $schedule->get_archive_filepath();

		if ( ! file_exists( $filepath ) ) {
			return new WP_Error( 'backup-failed', implode(', ', $schedule->get_errors() ) );
		}
		
		return true;
	}

	/**
	 * Get the backup once it has run, will return status running as a WP Error
	 * 
	 * @return WP_Error|string
	 */
	public function getBackup() {

		$schedule = $this->getManualBackupSchedule();

		if ( $status = $schedule->get_status() )
			return new WP_Error( 'error-status', $status );

		$backup = reset( $schedule->get_backups() );

		if ( file_exists( $backup ) )
			return str_replace( WP_CONTENT_DIR, WP_CONTENT_URL, $backup );

		return new WP_Error( 'backup-failed', 'No backup was found' );
	}

	public function cleanBackup() {
		$backup = reset( $schedule->get_backups() );

		if ( file_exists( $backup ) )
			unlink( $backup );
	}

	public function getEstimatedSize() {
		
		if ( $size = get_transient( 'hmbkp_schedule_manual_filesize' ) )
			return HMBKP_Scheduled_Backup::human_filesize( $size, null, '%01u %s' );

		// we dont know the size yet, fire off a remote request to get it for later
		// it can take some time so we have a small timeout then return "Calculating"
		wp_remote_get( 
			$url = add_query_arg( array( 'action' => 'wprp_calculate_backup_size' ), admin_url( 'admin-ajax.php' ) ), 
			array( 'timeout' => 0.1, 'sslverify' => false )
		);

		return 'Calculating';

	}

	public function calculateEstimatedSize() {
		$this->getManualBackupSchedule()->get_filesize();
	}

	/**
	 * Enabled automatic backups for this install
	 * 
	 * @param  array  $options { 'id' => string, type' => 'complete|files|database', 'reoccurance' => 'daily|...', 'excludes' => array() } 
	 * @return [type]          [description]
	 */
	public function addSchedule( $options = array() ) {

		$schedules = new HMBKP_Schedules();

		if ( $schedules->get_schedule( $options['id'] ) )
			return;

		$schedule = new HMBKP_Scheduled_Backup( $options['id'] );
		$schedule->set_type( $options['type'] );
		$schedule->set_excludes( $options['excludes'], true );
		$schedule->set_max_backups( 1 );

		if ( $options['start_date'] )
			$schedule->set_schedule_start_time( $options['start_date'] );

		$schedule->set_reoccurrence( $options['reoccurance'] );

		$schedule->save();
	}

	/**
	 * Remove a schedule
	 * 
	 * @param  int $id
	 */
	public function removeSchedule( $id ) {
		$schedules = new HMBKP_Schedules();

		if ( ! $schedules->get_schedule( $id ) )
			return;

		$schedules->get_schedule( $id )->cancel();
	}

	/**
	 * Get the manual backup schedule from BackupWordPress
	 * @return HMBKP_Scheduled_Backup
	 */
	private function getManualBackupSchedule() {

		$schedule = new HMBKP_Scheduled_Backup( 'manual' );
		$schedule->set_type( 'complete' );
		$schedule->set_max_backups( 1 );

		// Excludes
		if ( ! empty( $_REQUEST['backup_excludes'] ) ) {
		
			$excludes = array_map( 'urldecode', (array) $_REQUEST['backup_excludes'] );
			$schedule->set_excludes( $excludes, true );
		}

		return $schedule;
	}
}

class WPRP_Backup_Service extends HMBKP_Service {
	
	/**
	 * Fire the email notification on the hmbkp_backup_complete
	 *
	 * @see  HM_Backup::do_action
	 * @param  string $action The action received from the backup
	 * @return void
	 */
	public function action( $action ) {
		
		if ( $action == 'hmbkp_backup_complete' && strpos(  $this->schedule->get_id(), 'wpremote' ) !== false ) {

			$file = $this->schedule->get_archive_filepath();
			$file_url = str_replace( WP_CONTENT_DIR, WP_CONTENT_URL, $file );
			$api_url = 'http://local.wpremote.com/api/json/backups/upload';

			$args = array( 
				'api_key' 	=> get_option( 'wpr_api_key' ),
				'backup_url'=> $file_url
			);

			wp_remote_post( $api_url, array( 'timeout' => 2, 'body' => $args ) );
		}
	}

	/**
	 * Abstract methods must be implemented
	 */
	public function form() {}

	public function field() {}

	public function update( &$new_data, $old_data ) {}

	public function display() {}
}
HMBKP_Services::register( __FILE__, 'WPRP_Backup_Service' );

/**
 * Handle the backups API calls
 *
 * @param string $call
 * @return mixed
 */
function _wprp_backups_api_call( $action ) {

	switch( $action ) :
		
		// TODO in the future we should do some check here to make sure they do support backups
		case 'supports_backups' :
			return true;
			
		case 'do_backup' :

			return WPRP_Backups::getInstance()->doBackup();
					
		case 'get_backup' :
				
			return WPRP_Backups::getInstance()->getBackup();
										
		case 'delete_backup' :

			return WPRP_Backups::getInstance()->cleanBackup();
		
		case 'add_backup_schedule' :
			return WPRP_Backups::getInstance()->addSchedule( $_GET );

		case 'remove_backup_schedule' :
			return WPRP_Backups::getInstance()->removeSchedule( $_GET['id'] );

		break;

	endswitch;

}


function _wprp_get_backups_info() {

	$hm_backup = new HM_Backup();

	$info = array(
		'mysqldump_path' 	=> $hm_backup->get_mysqldump_command_path(),
		'zip_path' 			=> $hm_backup->get_zip_command_path(),
		'estimated_size'	=> WPRP_Backups::getInstance()->getEstimatedSize()
	);

	return $info;
}