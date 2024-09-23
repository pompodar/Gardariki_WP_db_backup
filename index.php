<?php
/*
Plugin Name: Gardariki WP DB Backup
Plugin URI: http://example.com/wp-db-backup-plugin
Description: A plugin to backup WordPress database with scheduling and compression
Version: 2.2
Author: Svyatoslav Kachmar
Author URI: http://example.com
License: GPL2
*/

// Prevent direct access to the plugin
if (!defined('ABSPATH')) {
	exit;
}

class Gardariki_WP_DB_Backup
{
	private $options;
	private $log = array();
	private $chunk_size = 1000; // Number of rows to process at once

	public function __construct()
	{
		add_action('admin_menu', array($this, 'create_plugin_settings_page'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('admin_post_run_db_backup', array($this, 'run_db_backup'));
		add_action('admin_post_restore_db_backup', array($this, 'run_restore_db_backup'));
		add_action('wp_db_backup_cron', array($this, 'run_scheduled_backup'));
		add_filter('cron_schedules', array($this, 'add_cron_interval'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
		add_action('wp_ajax_run_db_backup', array($this, 'run_db_backup'));
		add_action('wp_ajax_init_restore', array($this, 'init_restore'));
		add_action('wp_ajax_process_restore_chunk', array($this, 'process_restore_chunk'));

		$this->options = get_option('gardariki_wp_db_backup_options');

		if (isset($this->options['enable_scheduled_backups']) && $this->options['enable_scheduled_backups']) {
			if (!wp_next_scheduled('wp_db_backup_cron')) {
				wp_schedule_event(time(), 'daily', 'wp_db_backup_cron');
			}
		} else {
			wp_clear_scheduled_hook('wp_db_backup_cron');
		}
	}

	public function enqueue_admin_scripts($hook)
	{
		if ('settings_page_gardariki_wp_db_backup' !== $hook) {
			return;
		}

		wp_enqueue_script('gardariki-wp-db-backup-admin', plugins_url('admin.js', __FILE__), array('jquery'), '1.0', true);
		wp_localize_script('gardariki-wp-db-backup-admin', 'gardariki_ajax', array(
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('gardariki_db_restore_nonce'),
			'plugins_dir' => plugins_url('', __FILE__),
		));
	}

	public function create_plugin_settings_page()
	{
		add_options_page('Gardariki WP DB Backup', 'Gardariki DB Backup', 'manage_options', 'gardariki_wp_db_backup', array($this, 'plugin_settings_page_content'));
	}

	public function register_settings()
	{
		register_setting('gardariki_wp_db_backup_options', 'gardariki_wp_db_backup_options', array($this, 'validate_options'));
	}

	public function validate_options($input)
	{
		if (!isset($input['tables']) || empty($input['tables'])) {
			global $wpdb;
			$tables = $wpdb->get_results('SHOW TABLES', ARRAY_N);
			$input['tables'] = array_column($tables, 0);
		}
		$input['enable_scheduled_backups'] = isset($input['enable_scheduled_backups']);
		$input['chunk_size'] = isset($input['chunk_size']) ? intval($input['chunk_size']) : 1000;
		return $input;
	}

	public function plugin_settings_page_content()
	{
?>
		<div class="wrap">
			<style>
				.header {
					font-size: 2rem;
					font-weight: bold;
					margin-bottom: 1rem;
				}

				.container-gardariki-db-restore {
					display: flex;
					justify-content: space-between;
					flex-wrap: wrap;
				}

				.container-gardariki-db-restore {
					a {
						text-decoration: none;
						color: black;
					}
				}

				.section-gardariki-bd-restore {
					width: 28%;
					display: inline-block;
					background-color: #ffffff;
					border: 1px solid #e1e1e1;
					border-radius: 0.5rem;
					padding: 1.5rem;
					box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
					margin-bottom: 8px;
				}

				.form-table {
					width: 100%;
				}

				.form-table th {
					text-align: left;
					padding: 0.5rem 0;
				}

				.form-table td {
					padding: 0.5rem 0;
				}

				.accordion {
					border: 1px solid #e1e1e1;
					border-radius: 0.5rem;
					margin-top: 1rem;
				}

				.accordion-item {
					border-top: 1px solid #e1e1e1;
				}

				.accordion-header {
					cursor: pointer;
					padding: 0.75rem;
					cursor: pointer;
					font-weight: bold;
					position: relative;
				}

				.accordion-arrow {
					float: right;
					transition: transform 0.3s;
				}

				.accordion-content {
					display: none;
					padding: 10px;
					border: 1px solid #ccc;
					border-top: none;
					background-color: #fff;
				}

				.input-field {
					border: 1px solid #ccc;
					border-radius: 0.25rem;
					padding: 0.5rem;
					width: calc(100% - 1rem);
				}

				.button {
					background-color: #0073aa;
					color: #ffffff;
					padding: 0.5rem 1rem;
					border: none;
					border-radius: 0.25rem;
					cursor: pointer;
					transition: background-color 0.3s;
				}

				.button:hover {
					background-color: #005177;
				}

				.select-all {
					display: inline-block;
					cursor: pointer;
					padding: 0.5rem;
					border: 1px solid #ccc;
					border-radius: 0.25rem;
					margin-bottom: 1rem;
					transition: background-color 0.3s;
				}

				.section-header {
					font-size: 1.5rem;
					font-weight: 600;
					margin: 1rem 0;
				}

				.section-header--tables {
					padding: 0;
					margin: 0;
					margin-inline: 4px;
				}

				#backup-message,
				.result-text {
					margin-bottom: 8px;
				}

				#restore-info {
					margin-bottom: 1rem;
				}

				.result-text,
				.progress-text {
					margin: 0.5rem 0;
				}

				.spinner-gardariki-db-backup {
					display: none;
					border: 8px solid #f3f3f3;
					/* Light grey */
					border-top: 8px solid #5bc0de;
					/* Blue */
					border-radius: 50%;
					width: 40px;
					/* Size of the spinner */
					height: 40px;
					/* Size of the spinner */
					animation: spin 1s linear infinite;
					/* Spin animation */
					margin: 20px auto;
					/* Center the spinner */
				}

				@keyframes spin {
					0% {
						transform: rotate(0deg);
					}

					100% {
						transform: rotate(360deg);
					}
				}
			</style>
			<h2 class="header">Gardariki Database Backup</h2>
			<div class="container-gardariki-db-restore">
				<form class="section-gardariki-bd-restore tables-gardariki-db-restore" method="post" action="options.php">
					<?php settings_fields('gardariki_wp_db_backup_options'); ?>
					<?php $options = get_option('gardariki_wp_db_backup_options'); ?>
					<table class="form-table">
						<tr>
							<th>
								<h3 class="section-header section-header--tables">Tables to backup</h3>
							</th>
							<td>
								<div class="accordion">
									<div class="accordion-item">
										<div class="accordion-header accordion-header--tables">
											Tables
											<span class="accordion-arrow">
												<svg xmlns="http://www.w3.org/2000/svg" class="svg-icon" style="width: 1em; height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1">
													<path d="M96 512c0 229.8 186.2 416 416 416s416-186.2 416-416S741.8 96 512 96 96 282.2 96 512z m578.2-86.8c15-15 39.6-15 54.6 0 7.6 7.6 11.2 17.4 11.2 27.2s-3.8 19.8-11.4 27.4l-188.6 188c-15.2 13.8-38.6 13.4-53.2-1.2l-191.4-190.8c-15-15-15.2-39.4 0-54.6 15-15 39.4-15.2 54.6 0l162.2 163.8 162-159.8z" />
												</svg>
											</span>
										</div>
										<div class="accordion-content">
											<div id="select-all-tables">Select all/none</div>
											<?php
											global $wpdb;
											$tables = $wpdb->get_results('SHOW TABLES', ARRAY_N);
											foreach ($tables as $table) {
												$table_name = $table[0];
												$checked = !isset($options['tables']) || (isset($options['tables']) && in_array($table_name, $options['tables']));
												echo '<label><input type="checkbox" name="gardariki_wp_db_backup_options[tables][]" value="' . $table_name . '" ' . ($checked ? 'checked' : '') . '> ' . esc_html($table_name) . '</label><br>';
											}
											?>
										</div>
									</div>
								</div>
							</td>

						</tr>
						<tr>
							<th>Chunk size for large tables (in rows):</th>
							<td><input type="number" name="gardariki_wp_db_backup_options[chunk_size]" value="<?php echo isset($options['chunk_size']) ? esc_attr($options['chunk_size']) : '1000'; ?>" class="input-field" /></td>
						</tr>
						<tr>
							<th>Enable scheduled backups:</th>
							<td><input type="checkbox" name="gardariki_wp_db_backup_options[enable_scheduled_backups]" value="1" <?php checked(isset($options['enable_scheduled_backups']) && $options['enable_scheduled_backups']); ?>></td>
						</tr>
					</table>
					<input type="submit" value="Save Settings" class="button">
				</form>

				<section class="section-gardariki-bd-restore manual-restore-gardariki-db-backup">
					<h3 class="section-header">Manual Backup</h3>
					<div class="spinner-gardariki-db-backup"></div>
					<div id="backup-message"></div>
					<button id="run-backup" class="button">Run Backup</button>
				</section>

				<section class="section-gardariki-bd-restore">
					<h3 class="section-header">Restore Database Backup</h3>
					<form id="restore-form" method="post" enctype="multipart/form-data">
						<div id="restore-info">
							<div class="result-text"></div>
							<div class="spinner-gardariki-db-backup"></div>
						</div>

						<?php wp_nonce_field('restore_db_backup_nonce', 'db_restore_nonce'); ?>
						<input type="file" name="db_backup_file" required class="input-field" />
						<input type="submit" value="Restore Backup" class="button">
					</form>
				</section>

				<section class="section-gardariki-bd-restore manual-restore-gardariki-db-backup">
					<h3 class="section-header accordion-header accordion-header--download-db" id="download-backups">
						Download Backups
						<span class="accordion-arrow">
							<svg xmlns="http://www.w3.org/2000/svg" class="svg-icon" style="width: 1em; height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1">
								<path d="M96 512c0 229.8 186.2 416 416 416s416-186.2 416-416S741.8 96 512 96 96 282.2 96 512z m578.2-86.8c15-15 39.6-15 54.6 0 7.6 7.6 11.2 17.4 11.2 27.2s-3.8 19.8-11.4 27.4l-188.6 188c-15.2 13.8-38.6 13.4-53.2-1.2l-191.4-190.8c-15-15-15.2-39.4 0-54.6 15-15 39.4-15.2 54.6 0l162.2 163.8 162-159.8z" />
							</svg>
						</span>
					</h3>
					<div class="accordion-content" style="display: none;">
						<?php $this->list_backups(); ?>
					</div>
				</section>
			</div>
		</div>
<?php
	}

	public function run_db_backup()
	{
		if (!current_user_can('manage_options')) {
			wp_die('You do not have sufficient permissions to access this page.');
		}

		//check_ajax_referer('run_db_backup_nonce', 'security');

		$result = $this->perform_backup();

		if ($result === true) {
			wp_send_json_success(['message' => 'Backup completed successfully.']);
		} else {
			wp_send_json_error(['message' => 'Backup failed: ' . $result]);
		}
	}

	private function perform_backup()
	{
		set_time_limit(3000);
		ini_set('memory_limit', '2560M');
		ini_set('max_input_time', 3000);
		ini_set('post_max_size', '700M');
		ini_set('upload_max_filesize', '700M');

		global $wpdb;

		$this->log[] = "Starting backup process...";
		$options = get_option('gardariki_wp_db_backup_options');
		$tables_to_backup = isset($options['tables']) ? $options['tables'] : array();
		$this->chunk_size = isset($options['chunk_size']) ? intval($options['chunk_size']) : 1000;

		if (empty($tables_to_backup)) {
			$this->log[] = "No tables selected for backup. Defaulting to all tables.";
			$tables_to_backup = $wpdb->get_col('SHOW TABLES');
		}

		$filename = 'db-backup-' . date('Y-m-d-H-i-s') . '.sql.gz';
		$upload_dir = wp_upload_dir();
		$file_path = $upload_dir['basedir'] . '/' . $filename;

		// Open a gzip file for writing
		$gz = gzopen($file_path, 'w9');
		if ($gz === false) {
			$this->log[] = "Error: Could not open gzip file for writing: {$file_path}";
			return "Could not open gzip file for writing.";
		}

		foreach ($tables_to_backup as $table_name) {
			$this->log[] = "Processing table: {$table_name}";

			$create_table = $wpdb->get_row("SHOW CREATE TABLE {$table_name}", ARRAY_N);
			if ($create_table === null) {
				$this->log[] = "Error getting CREATE TABLE statement for {$table_name}";
				continue;
			}
			gzwrite($gz, "\n\nDROP TABLE IF EXISTS {$table_name};\n");
			gzwrite($gz, "\n\n" . $create_table[1] . ";\n\n");

			$offset = 0;
			do {
				$rows = $wpdb->get_results($wpdb->prepare(
					"SELECT * FROM {$table_name} LIMIT %d OFFSET %d",
					$this->chunk_size,
					$offset
				), ARRAY_A);

				if ($rows === null) {
					$this->log[] = "Error getting rows from {$table_name}";
					break;
				}

				foreach ($rows as $row) {
					$values = array();
					foreach ($row as $value) {
						$values[] = is_null($value) ? 'NULL' : "'" . $this->sql_addslashes($value) . "'";
					}
					gzwrite($gz, "INSERT INTO {$table_name} VALUES(" . implode(', ', $values) . ");\n");
				}

				$offset += $this->chunk_size;
				$this->log[] = "Processed " . $offset . " rows from {$table_name}.";
			} while (count($rows) === $this->chunk_size);
		}

		gzclose($gz);
		$this->log[] = "Backup completed successfully. File created at: {$file_path}";

		return true;
	}

	private function sql_addslashes($string)
	{
		return str_replace(
			array('\\', "\0", "\n", "\r", "'", '"', "\x1a"),
			array('\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'),
			$string
		);
	}

	public function init_restore()
	{
		check_ajax_referer('gardariki_db_restore_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error('Insufficient permissions');
		}

		if (!isset($_FILES['db_backup_file'])) {
			wp_send_json_error('No file uploaded');
		}

		$file = $_FILES['db_backup_file'];

		if ($file['error'] !== UPLOAD_ERR_OK) {
			wp_send_json_error('File upload error');
		}

		$upload_dir = wp_upload_dir();
		$backup_dir = $upload_dir['basedir'] . '/db_backups';
		if (!file_exists($backup_dir)) {
			mkdir($backup_dir, 0755, true);
		}

		$backup_file = $backup_dir . '/' . basename($file['name']);
		move_uploaded_file($file['tmp_name'], $backup_file);

		update_option('gardariki_db_restore_file', $backup_file);
		update_option('gardariki_db_restore_position', 0);
		update_option('gardariki_db_restore_queries_executed', 0);

		wp_send_json_success('Restoration process initialized');
	}

	public function process_restore_chunk()
	{
		check_ajax_referer('gardariki_db_restore_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_die('You do not have sufficient permissions to access this page.');
		}

		$file_path = get_option('gardariki_db_restore_file', 0);
		$position = get_option('gardariki_db_restore_position') ?? 0;
		$queries_executed = get_option('gardariki_db_restore_queries_executed') ?? 0;

		$result = $this->perform_restore($file_path, $position, $queries_executed);

		if ($result === true) {
			// Remove the options from the database after the restore process is completed
			delete_option('gardariki_db_restore_file');
			delete_option('gardariki_db_restore_position');
			delete_option('gardariki_db_restore_total_size');
			delete_option('gardariki_db_restore_queries_executed');

			wp_send_json_success('Restoration completed successfully');
		} elseif (is_array($result)) {
			update_option('gardariki_db_restore_position', $result['position']);
			update_option('gardariki_db_restore_queries_executed', $result['queries_executed']);

			$progress = min(100, ($result['queries_executed'] / $result['total_queries']) * 100);

			wp_send_json_success(array(
				'progress' => $progress,
				'queries_executed' => $result['queries_executed'],
				'total_queries' => $result['total_queries'],
				'message' => 'In progress'
			));
		} else {
			wp_send_json_error($result);
		}
	}


	private function perform_restore($file_path, $position, $queries_executed)
	{
		if (!file_exists($file_path)) {
			return "Backup file does not exist!.";
		}

		if (!current_user_can('manage_options')) {
			wp_die('You do not have sufficient permissions to access this page.');
		}

		$gz = @gzopen($file_path, 'r');
		if ($gz === false) {
			return "Could not open gzip file for reading.";
		}

		$total_queries = 0;
		$query = '';

		while (!gzeof($gz)) {
			$line = gzgets($gz);
			if ($line === false) {
				// End of file or error
				break;
			}

			if (trim($line) == '' || strpos($line, '--') === 0) {
				continue; // Skip empty lines and comments
			}

			$query .= $line;

			if (substr(trim($line), -1) == ';') {
				$total_queries++; // Count the query
				$query = ''; // Reset the query
			}
		}

		gzseek($gz, $position); // Go back to where you need to start processing the file

		$this->log[] = "Total queries to be executed: $total_queries";
		update_option('gardariki_db_restore_total_size', $total_queries);

		$this->log[] = "Starting restoration process...";
		global $wpdb;
		$wpdb->query("SET foreign_key_checks = 0");

		$query = '';
		$max_execution_time = ini_get('max_execution_time');
		$start_time = time();
		$completed = true;

		while (!gzeof($gz)) {
			$line = gzgets($gz);
			if ($line === false) {
				// End of file or error
				break;
			}

			if (trim($line) == '' || strpos($line, '--') === 0) {
				continue; // Skip empty lines and comments
			}

			$query .= $line;

			if (substr(trim($line), -1) == ';') {
				$result = $wpdb->query($query);
				if ($result === false) {
					$this->log[] = "Error executing query: " . $query . " - " . $wpdb->last_error;
				}
				$query = '';
				$queries_executed++;

				if ($queries_executed === 1000) {
					$position = gztell($gz);  // Get current position in the file
					update_option('gardariki_db_restore_queries_executed', $queries_executed);
					$completed = false;
					break;
				}

				// Check if we're approaching the max execution time
				if ($max_execution_time > 0 && (time() - $start_time) > ($max_execution_time - 5)) {
					$this->log[] = "Approaching max execution time. Pausing restoration.";
					$completed = false;
					break;
				}

				// Optionally, you can add a small delay to prevent server overload
				if ($queries_executed % 100 == 0) {
					usleep(10000); // Sleep for 10ms every 100 queries
				}
			}
		}

		gzclose($gz);
		$wpdb->query("SET foreign_key_checks = 1");

		if ($completed) {
			$this->log[] = "Restoration completed successfully. $queries_executed queries executed.";
			return true;
		} else {
			$this->log[] = "Restoration paused due to time constraints. $queries_executed queries executed.";

			return array(
				'position' => $position,
				'queries_executed' => $queries_executed,
				'total_queries' => $total_queries
			);
		}
	}

	public function list_backups()
	{
		$upload_dir = wp_upload_dir();
		$files = glob($upload_dir['basedir'] . '/db-backup-*.sql.gz');

		if (empty($files)) {
			echo '<p>No backups found.</p>';
			return;
		}

		echo '<ul>';
		foreach ($files as $file) {
			echo '<li><a href="' . esc_url($upload_dir['baseurl'] . '/' . basename($file)) . '" download>' . basename($file) . '</a></li>';
		}
		echo '</ul>';
	}

	public function run_scheduled_backup()
	{
		$this->perform_backup();
	}

	public function add_cron_interval($schedules)
	{
		$schedules['daily'] = array(
			'interval' => 86400,
			'display' => __('Once Daily')
		);
		return $schedules;
	}
}

new Gardariki_WP_DB_Backup();
