<?php 
/*
Plugin Name: Grabber for QQWorld Auto Save Images
Plugin URI: https://wordpress.org/plugins/grabber-4-qasi/
Description: Additional grabber for QQWrorld Auto Save Images.
Version: 1.0.2
Author: Michael Wang
Author URI: http://www.qqworld.org
Text Domain: grabber_4_qasi
*/
define('GRABBER_FOR_QQWORLD_AUTO_SAVE_IMAGES_DIR', __DIR__ . DIRECTORY_SEPARATOR);
define('GRABBER_FOR_QQWORLD_AUTO_SAVE_IMAGES_URL', plugin_dir_url(__FILE__));

class Grabber_for_QQWorld_auto_save_images {
	var $text_domain = 'grabber_4_qasi';
	var $exclude_domain;
	var $grab_pdf;
	public function __construct() {
		$this->exclude_domain = get_option('qqworld-auto-save-images-exclude-domain');
		$grabber = get_option( 'qqworld-auto-save-images-grabber' );
		$this->grab_pdf = isset($grabber['pdf']) ? $grabber['pdf'] : 'yes';
		add_action( 'plugins_loaded', array($this, 'load_language') );
		add_filter( 'plugin_row_meta', array($this, 'registerPluginLinks'),10,2 );
		add_action( 'admin_init', array($this, 'register_settings') );
		add_filter( 'qqworld-auto-save-images-content-save-pre', array($this, 'content_save_pre'), 10, 2 );
		add_action( 'qqworld-auto-save-images-general-options-form', array($this, 'general_options_form') );
	}

	public function load_language() {
		load_plugin_textdomain( 'grabber_4_qasi', dirname( __FILE__ ) . '/lang' . 'lang', basename( dirname( __FILE__ ) ) . '/lang' );
	}

	public function registerPluginLinks($links, $file) {
		$base = plugin_basename(__FILE__);
		if ($file == $base) {
			$links[] = '<a href="' . menu_page_url( 'qqworld-auto-save-images', 0 ) . '">' . __('Settings') . '</a>';
		}
		return $links;
	}

	function register_settings() {
		register_setting("qqworld_auto_save_images_settings", 'qqworld-auto-save-images-grabber');
	}

	public function outside_language() {
		__( 'Michael Wang', $this->text_domain );
		__( 'Grabber for QQWorld Auto Save Images', $this->text_domain );
		__( 'Additional grabber for QQWrorld Auto Save Images.', $this->text_domain );
	}

	public function get_filename($http_response_header, $url, $type) {
		$filename = '';
		foreach ($http_response_header as $header) {
			if ( preg_match('/content-disposition: filename=/i', $header, $matches) ) {
				$filename = str_replace('content-disposition: filename=', '', $header);
				break;
			}
		}
		if (empty($filename)) {
			$url = explode('/', $url);
			$filename = $url[count($url)-1];
			if (strstr($filename, '.') > -1) {
				$filename = explode('.', $filename);
				$filename = $filename[count($filename)-1] . '.' . $type;
			} else {
				$filename = md5($filename) . '.' . $type;
			}
		}
		return $filename;
	}

	public function download($url) {
		set_time_limit(0);
		$file = '';
		if (function_exists('file_get_contents')) {
			$file = @file_get_contents($url);
			$is_pdf = false;
			if (!empty($http_response_header)) foreach ($http_response_header as $header) {
				if ( preg_match('/Content-Type: application\/pdf/i', $header, $matches) ) {
					$is_pdf = true;
					$filename = $this->get_filename($http_response_header, $url, 'pdf');
				}
			}
		}
		return $is_pdf ? array('filename' => $filename, 'file' => $file) : '';
	}

	public function content_save_pre($content, $post_id) {
		set_time_limit(0);
		if ( preg_match_all('/<a[^>]*href=\"(.*?)\".*?>/i', $content, $matches) ) {
			foreach ($matches[1] as $match) {
				$allow = true;

				if (!empty($this->exclude_domain)) foreach ($this->exclude_domain as $domain) {
					$pos = strpos($match, $domain);
					if($pos) $allow=false;
				}
				if ($allow) {
					$pos = strpos($match, get_bloginfo('url'));
					if($pos===false){
						$file = $this->download($match);
						if ( !empty($file) && $res = $this->save($file['filename'], $file['file'], $post_id) ) {
							$content = $this->format($match, $res, $content);
						}
					}
				}
			}
		}
		return $content;
	}

	public function encode_pattern($str) {
		$str = str_replace('(', '\(', $str);
		$str = str_replace(')', '\)', $str);
		$str = str_replace('+', '\+', $str);
		$str = str_replace('.', '\.', $str);
		$str = str_replace('?', '\?', $str);
		$str = str_replace('*', '\*', $str);
		$str = str_replace('/', '\/', $str);
		$str = str_replace('^', '\^', $str);
		$str = str_replace('$', '\$', $str);
		$str = str_replace('|', '\|', $str);
		return $str;
	}

	public function format($url, $res, $content) {
		$pattern_url = $this->encode_pattern($url);
		$replace = $res['url'];
		$content = preg_replace('/'.$pattern_url.'/i', $replace, $content);
		return $content;
	}

	//insert attachment
	public function insert_attachment($file, $parent){
		$dirs = wp_upload_dir();
		$filetype = wp_check_filetype($file);
		$attachment = array(
			'guid' => $dirs['baseurl'].'/'._wp_relative_upload_path($file),
			'post_mime_type' => $filetype['type'],
			'post_title' => preg_replace('/\.[^.]+$/','',basename($file)),
			'post_content' => '',
			'post_status' => 'inherit'
		);
		$attach_id = wp_insert_attachment($attachment, $file, $parent);
		$attach_data = wp_generate_attachment_metadata($attach_id, $file);
		wp_update_attachment_metadata($attach_id, $attach_data);
		return $attach_id;
	}

	public function save($filename, $file, $post_id) {
		set_time_limit(0);
		$res = wp_upload_bits($filename, null, $file);
		if (isset( $res['error'] ) && !empty($res['error'])) return false;
		$attachment_id = $this->insert_attachment($res['file'], $post_id);
		$res['id'] = $attachment_id;
		$meta_data = wp_get_attachment_metadata($attachment_id);
		if (!empty($meta_data)) $res = array_merge($res, $meta_data);
		return $res;
	}

	public function general_options_form() {
?>
	<h2><?php _e('Additional Grabber Options', $this->text_domain); ?></h2>
	<table class="form-table">
		<tbody>
			<tr valign="top">
				<th scope="row"><label><?php _e('Grab PDF', $this->text_domain); ?></label></th>
				<td><fieldset>
					<legend class="screen-reader-text"><span><?php _e('Grab PDF', $this->text_domain); ?></span></legend>
						<label for="qqworld_auto_save_images_grab_pdf">
							<input name="qqworld-auto-save-images-grabber[pdf]" type="checkbox" id="qqworld_auto_save_images_grab_pdf" value="yes" <?php checked('yes', $this->grab_pdf); ?> />
						</label>
				</fieldset></td>
			</tr>
		</tbody>
	</table>
<?php
	}
}
new Grabber_for_QQWorld_auto_save_images;

