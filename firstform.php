<?php
/*
Plugin Name: FirstForm
Plugin URI: http://wp.first-notes.com/
Description: inquiryForm
Version: 1.0
Author: fnotes
Author URI: http://first-notes.com/
License: GPL2
*/

class FirstForm {

	var $name = 'firstform';
	var $version = '1.0';
	var $options = array();
	var $table;
	var $id;
	var $dir;

	function __construct(){
		global $wpdb;

		session_start();

		$this->table = $wpdb->prefix . $this->name;

		$this->options = get_option($this->name);

		$this->dir = $this->gOPS('attach_path');
		if( $this->dir=='' ){
			$dir = wp_upload_dir();
			$this->dir = $dir['path'].'/';
		}

		register_activation_hook(__FILE__, array($this, 'install'));
		register_uninstall_hook(__FILE__, array(&$this, 'uninstall'));

		add_action('admin_menu', array($this, 'show_menu'));
		add_action('admin_enqueue_scripts', array($this, 'scripts'), 20);
		add_action('init', array($this, 'init'));

		add_filter('widget_text', 'do_shortcode');

		add_shortcode('fform_multi_area', array($this, 'start_mform'));
		add_shortcode('fform_area', array($this, 'start_form'));
		add_shortcode('fform', array($this, 'items_form'));

		load_plugin_textdomain($this->name, false, dirname(plugin_basename(__FILE__)).'/languages');
	}

	function install(){

		$char = defined("DB_CHARSET") ? DB_CHARSET: "utf8";

		if( $this->options['version'] != $this->version ){

			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

			$sql = "CREATE TABLE IF NOT EXISTS ".$this->table." (
id int unsigned auto_increment primary key,
permalink varchar(255),
email text,
txt text,
files text,
ip varchar(15),
created timestamp
) CHARACTER SET '$char'";
			dbDelta($sql);

			$this->options['version'] = $this->version;
			update_option($this->name, $this->options);
		}
	}

	function uninstall(){
		global $wpdb;

		$wpdb->query("DROP TABLE " . $this->table);

		delete_option($this->name);
	}

	function init(){
		global $locale;

		$this->id = $_SERVER['REQUEST_URI'];

		if( $_SERVER['REQUEST_METHOD']=='POST' ){

			if( empty($_POST['_ffnonce']) ) return true;
			if( $this->getSSN('phase')=='end' ){
				$this->setSSN();
				wp_safe_redirect( site_url() );
				exit;
			}

			if( wp_verify_nonce($_POST['_ffnonce'], 'update-shortcode') ){

				$this->options['base'] = wp_kses_post( $this->gPOST('base') );
				update_option($this->name, $this->options);
				exit;

			}elseif( wp_verify_nonce($_POST['_ffnonce'], 'validate') ){

				$this->validate();

			}elseif( wp_verify_nonce($_POST['_ffnonce'], 'complete') ){

				$this->complete();

			}elseif( isset($_POST['fname']) && wp_verify_nonce($_POST['_ffnonce'], 'inquiry') ){

				$file = preg_replace('/[^\w\.]/', '', $this->gPOST('fname'));
				if( file_exists($this->dir.$file) ){
					$this->setSSN( array('phase'=>'download','fname'=>$this->dir.$file) );

					echo '{"success":"ok"}';

				}else{
					echo '{"error":"file not found"}';
				}

				exit;
			}

		}else{

			if( $this->getSSN('phase')=='download' ){

				$file = $this->getSSN('fname');

				header('Content-Type: application/octet-stream');
				header('Content-Disposition: attachment; filename="'.basename($file).'"');
				header('Content-Length: '.filesize($file));
				readfile($file);

				$this->setSSN();
				exit;
			}
			if( strpos($this->id, $this->name.'.json')!==false ){

				$dir = dirname(__FILE__).'/js/languages/';
				$json = $locale.'.json';
				if( file_exists($dir.$json) ){
					echo file_get_contents($dir.$json);

				}else{
					echo file_get_contents($dir.'en.json');
				}

				exit;
			}

			$this->setSSN();
		}

	}

	function form($atts, $content, $type=''){

		$phase = $this->getSSN('phase');
		if( $phase=='end' ){

			return $this->gOPS('complete_body');
		}

		$start = $type=='multi' ? '<form method="post" enctype="multipart/form-data">': '<form method="post">';
		if( $phase=='complete' ){
			$end = wp_nonce_field('complete', '_ffnonce', false, false).'</form>';
		}else{
			$end = wp_nonce_field('validate', '_ffnonce', false, false).'</form>';
		}

		return $start.do_shortcode( $content ).$end;
	}

	function start_form($atts, $content=null){

		return $this->form($atts, $content);
	}

	function start_mform($atts, $content=null){

		return $this->form($atts, $content, 'multi');
	}

	function items_form($atts){

		foreach($atts as $key => $val){
			if( !is_numeric($key) ) continue;

			switch($val){

				case 'to_mail':case 'tomail':
					if( isset($atts['name']) ) $this->setSSN( array('tomail'=>$atts['name']) );
					break;

				case 'required':case 'email':case 'zip':case 'tel':case 'numeric':case 'alphanumeric':
					if( isset($atts['name']) ) $this->setSSN( array(
						$atts['name'] => array(
							'type' => $val,
							'message' => isset($atts['message']) ? $atts['message']: ''
						)
					));
					break;

				case 'caution':
					$cn = $this->getSSN('CAUTION');
					$html = '';
					if( is_array($cn) ){
						foreach($cn as $msg){
							$html .= '<div class="caution">'.esc_html($msg).'</div>';
						}
					}
					return $html;

			}
		}

		if( isset($atts['type']) ){

			$post = $this->getSSN('post');
			$name = isset($atts['name']) ? $atts['name']: '';

			if( $post!='' ){
				if( !in_array($atts['type'], array('reset','button','submit','hidden')) ){

					if( strpos($name, '*')!==false ){
						$name = str_replace('*', '', $name);
						if( isset($post[ $name ]) && strpos($post[ $name ], $atts['value'])!==false ){
							return $atts['value'];
						}else{
							return '';
						}

					}elseif( isset($post[ $name ]) ){

						return esc_html(stripslashes($post[ $name ]));
					}else{
						return '';
					}
				}

			}elseif( isset($atts['confirm']) ){

				$atts['value'] = $atts['confirm'];
			}
			unset( $atts['confirm'] );

			$attr = '';
			foreach($atts as $key => $val){
				if( $key=='type' || $key=='options' || $key=='message' || $key=='label' ) continue;
				if( $key=='name' && strpos($val, '*')!==false ){
					$val = str_replace('*', '[]', $val);
				}

				$attr .= ' '.preg_replace("/[^\w\-]/", "", $key).'="'.esc_attr($val).'"';
			}

			$label = isset($atts['label']) ? $atts['label']: '';

			switch($atts['type']){
				case 'text':
					if( isset($_POST[ $atts['name'] ]) ) $attr .= ' value="'.esc_attr(stripslashes($_POST[ $atts['name'] ])).'"';
					return '<input type="text"'.$attr.'>';
				case 'radio':
					$ck = isset($_POST[ $atts['name'] ]) ? $_POST[ $atts['name'] ]: '';
					$v = isset($atts['value']) ? $atts['value']: '';
					return '<label><input type="radio"'.$attr.($ck==$v?' checked="checked"':'').'>'.esc_html($label).'</label>';
				case 'checkbox':case 'check':
					$ck = isset($_POST[ $atts['name'] ]) ? $_POST[ $atts['name'] ]: '';
					$v = isset($atts['value']) ? $atts['value']: '';
					return '<label><input type="checkbox"'.$attr.(strpos(':'.$ck.':',':'.$v.':')!==false?' checked="checked"':'').'>'.esc_html($label).'</label>';
				case 'select':
					$html = '<select'.$attr.'>';
					$ops = isset($atts['options']) ? explode(':', $atts['options']): array();
					$sl = isset($_POST[ $atts['name'] ]) ? $_POST[ $atts['name'] ]: '';
					foreach($ops as $val){
						list($val, $sel) = explode(',', $val);
						if( $sel=='' ) $sel = $val;
						$html .= '<option value="'.esc_attr($val).'"'.($sl==$val?' selected="selected"':'').'>'.esc_html($sel).'</option>';
					}
					return $html.'</select>';
				case 'textarea':
					$v = isset($_POST[ $atts['name'] ]) ? stripslashes($_POST[ $atts['name'] ]): '';
					return '<textarea'.$attr.'>'.esc_textarea($v).'</textarea>';
				case 'file':
					if( isset($atts['name']) ) $this->setSSN( array(
						$atts['name'] => array(
							'type' => 'file',
							'ext' => isset($atts['ext']) ? $atts['ext']: '',
							'ext_message' => isset($atts['ext_message']) ? $atts['ext_message']: '',
							'size' => isset($atts['size']) ? $atts['size']: 0,
							'size_message' => isset($atts['size_message']) ? $atts['size_message']: ''
						)
					));
					return '<input type="file"'.$attr.'>';
				case 'reset':
					return '<input type="reset"'.$attr.'>';
				case 'submit':
					return '<input type="submit"'.$attr.'>';
				case 'button':
					return '<input type="button"'.$attr.'>';
				case 'hidden':
					return '<input type="hidden"'.$attr.'>';
			}
		}

	}

	function validate(){
		global $locale;

		$caution = array();
		foreach($_POST as $key => $val){

			if( is_array($val) ) $val = implode(':', $val);
			$_POST[ $key ] = wp_kses_post( $val );

			$v = $this->getSSN($key);
			if( empty($v['type']) ) continue;

			switch( $v['type'] ){
				case 'required':
					if( $val=='' ) $caution[] = $v['message'];
					break;

				case 'email':
					if( !preg_match("/^[^@]+@[^@]+\.[\w]+$/", $val) ) $caution[] = $v['message'];
					break;

				case 'zip':
					if( ($locale=='ja' && !preg_match("/^\d{3}\-?\d{4}$/", $val)) || ($locale=='en_US' && !preg_match("/^\d{5}\-?\d{4}?$/", $val)) || 
						preg_match("/[^\d\-]/", $val) ){
						$caution[] = $v['message'];
					}
					break;
				case 'tel':
					if( preg_match("/[^\d\-]/", $val) ) $caution[] = $v['message'];
					break;

				case 'numeric':
					if( preg_match("/[^\d]/", $val) ) $caution[] = $v['message'];
					break;
				case 'alphanumeric':
					if( preg_match("/[^\w]/", $val) ) $caution[] = $v['message'];
					break;
			}
		}

		if( count($_FILES) ){

			$r = $this->upload();
			if( $r!==false ) $caution[] = $r;
		}

		$this->setSSN( array('CAUTION'=>$caution) );

		if( count($caution)==0 ){

			$this->setSSN( array('post'=>$_POST) );
			$this->setSSN( array('phase'=>'complete') );
		}

	}

	function complete(){
		global $wpdb;

		$this->autoDel();

		$tomail = $this->getSSN('tomail');
		$files = $email = $txt = $items = '';

		$attach = array();
		$post = $this->getSSN('post');
		if( is_array($post) ){
			foreach($post as $key => $val){

				if( $key==$tomail ){
					$email = $val;
					continue;

				}elseif( $key=='_upfiles' ){

					$files = $val;
					foreach(explode(':',$val) as $f){
						rename($this->dir.$f.'.tmp', $this->dir.$f);
						$attach[] = $this->dir.$f;
					}
					continue;
				}
				if( $key=='_ffnonce' ) continue;

				$key = preg_replace("/[\t=]/", '', sanitize_key($key));
				$val = str_replace("\t", '    ', $val);

				$txt .= $key.'='.$val."\t";
				$items .= $val."\n";
			}

			/* files = time.ext:time.ext */
			$wpdb->query($wpdb->prepare(
				"INSERT INTO ".$this->table." (permalink,email,txt,files,ip) VALUES (%s,%s,%s,%s,%s)",
				$this->id, $email, rtrim($txt, "\t"), $files, $_SERVER['REMOTE_ADDR']
			));

			$admin_mail = $this->gOPS('admin_mail');
			$admin_sub = $this->gOPS('admin_sub');
			$user_sub = $this->gOPS('user_sub');

			if( $email!='' && $user_sub!='' ){

				$headers = 'From: "'.$this->gOPS('admin_name').'" <'.$email.">\n";
				$body = str_replace('#FORM_CONTENT#', stripcslashes($items), $this->gOPS('user_body'));
				wp_mail($email, $user_sub, $body, $headers);
			}
			if( $admin_mail!='' && $admin_sub!='' ){

				if( $email=='' ) $email = $admin_mail;
				$headers = 'From: <'.$email.">\n";
				$body = str_replace('#FORM_CONTENT#', stripcslashes($items), $this->gOPS('admin_body'));
				wp_mail($admin_mail, $admin_sub, $body, $headers, $attach);
			}
		}

		$this->setSSN( array('post'=>'') );
		$this->setSSN( array('phase'=>'end') );

		$url = $this->gOPS('complete_url');
		if( $url!='' ){
			wp_safe_redirect( $url );
			exit;
		}

	}

	function upload(){

		$ups = array();
		foreach($_FILES as $key => $files){
			if( is_array($files['tmp_name']) ){

				foreach($files['tmp_name'] as $i => $file){

					$ext = preg_replace("/^.+(\.[^\.]+)$/", "$1", $files['name'][ $i ]);
					$v = $this->getSSN($key);
					if( $v['ext']!='' && strpos($v['ext'], $ext)===false ){
						return $v['ext_message'];
					}
					if( $v['size']!='' && $v['size'] < filesize($file) ){
						return $v['size_message'];
					}
					$ups[] = $f = $this->mkName($ext);
					move_uploaded_file($file, $this->dir.$f.'.tmp');
				}

			}else{

				$ext = preg_replace("/^.+(\.[^\.]+)$/", "$1", $files['name']);
				$v = $this->getSSN($key);
				if( $v['ext']!='' && strpos($v['ext'], $ext)===false ){
					return $v['ext_message'];
				}
				if( $v['size']!='' && $v['size'] < filesize($files['tmp_name']) ){
					return $v['size_message'];
				}
				$ups[] = $f = $this->mkName($ext);
				move_uploaded_file($files['tmp_name'], $this->dir.$f.'.tmp');
			}
		}

		$_POST['_upfiles'] = implode(':', $ups);

		return false;
	}

	function mkName($ext){

		$time = date("YmdHis");
		$fname = $time.$ext;

		if( file_exists($this->dir.$fname) ){
			for($i=1;$i<20;$i++){

				if( !file_exists($this->dir.$time.'-'.$i.$ext) ){
					$fname = $time.'-'.$i.$ext;
					break;
				}
			}
		}

		return $fname;
	}

	function show_menu(){

		add_object_page(
			'FirstForm',
			'FirstForm',
			8,
			'firstform',
			array($this, 'view_settings'),
			'dashicons-list-view'
		);
		add_submenu_page(
			'firstform',
			__('inquiry', $this->name),
			__('inquiry', $this->name),
			8,
			'inquiry',
			array($this, 'view_inquiry')
		);
		add_submenu_page(
			'firstform',
			__('shortcode', $this->name),
			__('shortcode', $this->name),
			8,
			'shortcode',
			array($this, 'view_shortcode')
		);

	}

	function view_settings(){

		$msg = 0;
		if( $_SERVER['REQUEST_METHOD']=='POST' && check_admin_referer('update-settings') ){

			$this->options['attach_path'] = preg_replace("/[^\w_\.\-\/]/", '', $this->gPOST('attach_path'));
			$this->options['complete_url'] = esc_url( $this->gPOST('complete_url') );
			$this->options['complete_body'] = wp_kses_post( $this->gPOST('complete_body') );
			$this->options['admin_mail'] = sanitize_email( $this->gPOST('admin_mail') );
			$this->options['admin_name'] = sanitize_text_field( $this->gPOST('admin_name') );
			$this->options['user_sub'] = sanitize_text_field( $this->gPOST('user_sub') );
			$this->options['user_body'] = wp_filter_post_kses( $this->gPOST('user_body') );
			$this->options['admin_sub'] = sanitize_text_field( $this->gPOST('admin_sub') );
			$this->options['admin_body'] = wp_filter_post_kses( $this->gPOST('admin_body') );

			update_option($this->name, $this->options);
			$msg = 1;
		}

?>
<div class="wrap">
<h2><?php _e('Configuration', $this->name); ?></h2>
<?php if( $msg ): ?>
<div id="message" class="updated"><p><?php _e('Change was preserved.', $this->name); ?></p></div>
<?php endif; ?>
<form method="post">
<table class="form-table" cellspacing="0">
<tbody>
<tr>
	<th><?php _e('Attachment preservation directory', $this->name); ?></th>
	<td><input type="text" name="attach_path" placeholder="<?php echo ABSPATH; ?>wp-content/uploads/" class="large-text" value="<?php echo esc_attr( $this->gOPS('attach_path') ); ?>"></td>
</tr>
</tbody>
</table>

<h3><?php _e('Operation at the time of acceptance completion', $this->name); ?></h3>
<table class="form-table" cellspacing="0">
<tbody>
<tr>
	<th><?php _e('URL to move (no movement in the blank)', $this->name); ?></th>
	<td><input type="text" name="complete_url" class="large-text" value="<?php echo esc_attr( $this->gOPS('complete_url') ); ?>"></td>
</tr>
<tr>
	<th><?php _e('Display', $this->name); ?></th>
	<td>
		<p>
			<textarea name="complete_body" rows="10" class="large-text"><?php echo esc_textarea( $this->gOPS('complete_body') ); ?></textarea>
		</p>
	</td>
</tr>
</tbody>
</table>

<h3><?php _e('Mail settings for the user at the time of acceptance', $this->name); ?></h3>
<table class="form-table" cellspacing="0">
<tbody>
<tr>
	<th><?php _e('From address (no transmitted in blank)', $this->name); ?></th>
	<td><input type="text" name="admin_mail" value="<?php echo esc_attr( $this->gOPS('admin_mail') ); ?>"></td>
</tr>
<tr>
	<th><?php _e('Sender\'s name', $this->name); ?></th>
	<td><input type="text" name="admin_name" value="<?php echo esc_attr( $this->gOPS('admin_name') ); ?>"></td>
</tr>
<tr>
	<th><?php _e('Subject (no transmitted in blank)', $this->name); ?></th>
	<td><input type="text" name="user_sub" value="<?php echo esc_attr( $this->gOPS('user_sub') ); ?>"></td>
</tr>
<tr>
	<th><?php _e('Body of letter', $this->name); ?></th>
	<td>
		<p>
			<textarea name="user_body" rows="10" class="large-text"><?php echo esc_textarea( $this->gOPS('user_body') ); ?></textarea>
		</p>
		<p><?php _e('#FORM_CONTENT# is replaced the users input', $this->name); ?></p>
	</td>
</tr>
</tbody>
</table>

<h3><?php _e('Mail settings for the reception at the time of administrator', $this->name); ?></h3>
<table class="form-table" cellspacing="0">
<tbody>
<tr>
	<th><?php _e('Subject (no transmitted in blank)', $this->name); ?></th>
	<td><input type="text" name="admin_sub" value="<?php echo esc_attr( $this->gOPS('admin_sub') ); ?>"></td>
</tr>
<tr>
	<th><?php _e('Body of letter', $this->name); ?></th>
	<td>
		<p>
			<textarea name="admin_body" rows="10" class="large-text"><?php echo esc_textarea( $this->gOPS('admin_body') ); ?></textarea>
		</p>
		<p><?php _e('#FORM_CONTENT# is replaced the users input', $this->name); ?></p>
	</td>
</tr>

</tbody>
</table>
<p class="submit">
	<input type="submit" class="button button-primary" value="<?php _e('Save'); ?>">
</p>
<?php wp_nonce_field('update-settings'); ?>
</form>
</div>
<?php

	}

	function view_shortcode(){

		$base = $this->gOPS('base');
		if( $base=='' ) $base = '[fform_area]



[/fform_area]';

?>
<div class="wrap">
<h2><?php _e('Shortcode', $this->name); ?></h2>
<table class="form-table" cellspacing="0">
<tbody>
<tr>
	<th><?php _e('Base', $this->name); ?></th>
	<td>
		<p>
			<textarea id="base" rows="10" class="large-text"><?php echo $base; ?></textarea>
		</p>
	</td>
</tr>

<tr>
	<th><?php _e('Form Items', $this->name); ?></th>
	<td>
		<p>
			<select id="item">
				<option value="text"><?php _e('text', $this->name); ?></option>
				<option value="radio"><?php _e('radio', $this->name); ?></option>
				<option value="checkbox"><?php _e('checkbox', $this->name); ?></option>
				<option value="select"><?php _e('select', $this->name); ?></option>
				<option value="textarea"><?php _e('textarea', $this->name); ?></option>
				<option value="file"><?php _e('file', $this->name); ?></option>
				<option value="submit"><?php _e('submit', $this->name); ?></option>
				<option value="reset"><?php _e('reset', $this->name); ?></option>
				<option value="button"><?php _e('button', $this->name); ?></option>
				<option value="hidden"><?php _e('hidden', $this->name); ?></option>
			</select>

			<select id="vali">
				<option value=""><?php _e('No check', $this->name); ?></option>
				<option value="required"><?php _e('required', $this->name); ?></option>
				<option value="email"><?php _e('email', $this->name); ?></option>
				<option value="zip"><?php _e('zip', $this->name); ?></option>
				<option value="tel"><?php _e('tel', $this->name); ?></option>
				<option value="numeric"><?php _e('numeric', $this->name); ?></option>
				<option value="alphanumeric"><?php _e('alphanumeric', $this->name); ?></option>
			</select>

			<input type="text" id="name" placeholder="<?php _e('Name element', $this->name); ?>">
		</p>
	</td>
</tr>
<tr>
	<th><?php _e('addcode', $this->name); ?></th>
	<td>
		<p><input type="text" id="code" value='[fform type=text name=""]' class="large-text"></p>
		<p><?php _e('Code, posts, can be installed fixed page, the widget.', $this->name); ?></p>
		<p><input type="button" id="addcode" value="<?php _e('Add', $this->name); ?>"></p>
	</td>
</tr>

<tr>
	<th><?php _e('Specification', $this->name); ?></th>
	<td>
		<p>
		<?php _e('[fform_area] or [fform_multi_area] is start', $this->name); ?><br>
		<?php _e('[/fform_area] or [/fform_multi_area] is end', $this->name); ?><br>

		<br>
		<?php _e('options="" is set of choices, :separated choices, ,separated value,display (optional)', $this->name); ?><br>

		<br>
		<?php _e('name="name*" is arranging a plurality of the same item', $this->name); ?><br>

		<br>
		<?php _e('ext=".jpg,.png,.gif" is file extension limit', $this->name); ?><br>
		<?php _e('ext_message="" is warning statement', $this->name); ?><br>
		<?php _e('size=number is file size limit', $this->name); ?><br>
		<?php _e('size_message="" is warning statement', $this->name); ?><br>

		<br>
		<?php _e('[fform caution] is display error', $this->name); ?><br>
		<?php _e('message="" is warning statement', $this->name); ?><br>
		<?php _e('confirm="" is confirm button value', $this->name); ?><br>
		<?php _e('tomail is destination', $this->name); ?><br>

		<br>
		<?php _e('Configurable, such as id="" class=""', $this->name); ?><br>

		</p>
	</td>
</tr>

</tbody>
</table>
<?php wp_nonce_field('update-shortcode'); ?>
</div>
<?php

	}

	function view_inquiry(){
		global $wpdb;

		$msg = 0;
		if( $_SERVER['REQUEST_METHOD']=='POST' && check_admin_referer('inquiry') ){

			$ids = $this->gPOST('delId');
			if( is_array($ids) ){

				$del = array();
				foreach($ids as $id){
					$_id = preg_replace("/[^\d]/", "", $id);
					if( $_id=='' ) continue;

					$del[] = 'id='.$_id;
				}

				if( count($del) ){

					$wh = implode(' OR ', $del);

					$rows = $wpdb->get_results('SELECT files FROM '.$this->table.' WHERE '.$wh, ARRAY_A);
					foreach($rows as $row){
						foreach(explode(':',$row['files']) as $file){
							if( file_exists($this->dir.$file) ) unlink( $this->dir.$file );
						}
					}

					$wpdb->query('DELETE FROM '.$this->table.' WHERE '.$wh);
					$msg = 1;
				}
			}
		}

		$paged = preg_replace("/[^\d]/", "", $_GET['paged']);
		if($paged == ''){
			$st = 0;
		}else{
			$st = ($paged-1)*10;
		}

		$all = $wpdb->get_var('SELECT COUNT(*) FROM '.$this->table);
		$rows = $wpdb->get_results($wpdb->prepare('SELECT id,permalink,email,txt,files,ip,DATE_FORMAT(created, %s) as created FROM '.$this->table.' ORDER BY created DESC LIMIT %d,10', '%Y-%m-%d %H:%i',$st), ARRAY_A);

?>
<div class="wrap">
<h2><?php _e('inquiry list', $this->name); ?></h2>
<?php if( $msg ): ?>
<div id="message" class="updated"><p><?php _e('the relevant data was removed', $this->name); ?></p></div>
<?php endif; ?>
<form method="post">
<table class="widefat" cellspacing="0">
<thead>
<tr><th></th><th><?php _e('inquiryNo', $this->name); ?></th><th><?php _e('page', $this->name); ?></th><th><?php _e('email', $this->name); ?></th><th><?php _e('content', $this->name); ?></th><th><?php _e('attachment', $this->name); ?></th><th><?php _e('IP address', $this->name); ?></th><th><?php _e('created date', $this->name); ?></th></tr>
</thead>
<tbody>
<?php foreach($rows as $row): ?>
<tr>
	<td><input type="checkbox" name="delId[]" value="<?php echo $row['id']; ?>"></td>
	<td><?php echo esc_html($row['id']); ?></td>
	<td><?php echo esc_html($row['permalink']); ?></td>
	<td><?php echo esc_html($row['email']); ?></td>
	<td><?php echo str_replace("\t", '<br>', esc_html(stripslashes($row['txt']))); ?></td>
	<td>
	<?php foreach(explode(':',$row['files']) as $file): ?>
		<?php if( $file!='' && file_exists($this->dir.$file) ): ?><a href="#" class="fDL" id="<?php echo str_replace('.','-',$file); ?>"><?php _e('file', $this->name); ?></a><?php endif; ?>
	<?php endforeach; ?>
	</td>
	<td><?php echo esc_html($row['ip']); ?></td>
	<td><?php echo esc_html($row['created']); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot>
<tr><th></th><th><?php _e('inquiryNo', $this->name); ?></th><th><?php _e('page', $this->name); ?></th><th><?php _e('email', $this->name); ?></th><th><?php _e('content', $this->name); ?></th><th><?php _e('attachment', $this->name); ?></th><th><?php _e('IP address', $this->name); ?></th><th><?php _e('created date', $this->name); ?></th></tr>
</tfoot>
</table>
<p class="submit">
	<input type="submit" class="button button-primary" value="<?php _e('delete', $this->name); ?>">
</p>
<?php wp_nonce_field('inquiry'); ?>
</form>
</div>
<?php

		$big = 99999;
		echo paginate_links( array(
			'base' => str_replace($big, '%#%', esc_url( get_pagenum_link( $big ) ) ),
			'format' => '?paged=%#%',
			'current' => max( 1, $paged ),
			'total' => ($all%10==0)?intval($all/10):intval($all/10)+1
		) );
	}

	function autoDel(){

		if( is_dir($this->dir) ){
			$dh = opendir($this->dir);
			while( $file = readdir($dh) ){
				if( $file=='.' || $file=='..' || !preg_match("/\.tmp$/", $file) ) continue;

				$time = preg_replace("/^(\d+)\..+$/", "$1", $file);
				if( strtotime($time) < time()-1800 ) unlink($this->dir.$file);
			}
			closedir($dh);
		}
	}

	function gPOST($key){

		return isset($_POST[ $key ]) ? $_POST[ $key ]: '';
	}

	function gOPS($key){

		return isset($this->options[ $key ]) ? stripslashes($this->options[ $key ]): '';
	}
	function eOPS($key){

		echo isset($this->options[ $key ]) ? esc_attr(stripslashes($this->options[ $key ])) : '';
	}

	function setSSN($vals=''){

		if( $vals=='' ){
			unset( $_SESSION[ $this->name ] );

		}else{

			foreach($vals as $key => $val){
				$_SESSION[ $this->name ][ $this->id ][ $key ] = $val;
			}
		}
	}
	function getSSN($key){

		return isset($_SESSION[ $this->name ][ $this->id ][ $key ]) ? $_SESSION[ $this->name ][ $this->id ][ $key ]: '';
	}

	function scripts($hook_suffix){

		if( $hook_suffix==$this->name.'_page_shortcode' || $hook_suffix==$this->name.'_page_inquiry' ){
			wp_enqueue_script($this->name, plugins_url($this->name . '/js/items.js'), array('jquery'), '1.0', true);
		}
	}

}

$fForm = new FirstForm();
