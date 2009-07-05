<?php
/**!info**
{
  "Plugin Name"  : "Gorilla Paste",
  "Plugin URI"   : "http://enanocms.org/plugin/gorilla",
  "Description"  : "For The Toughest Pasting Jobs On Earth.&trade; The pastebin, Enano style. <a href=\"http://enanocms.org/plugin/geshi\" onclick=\"window.open(this.href); return false;\">GeSHi plugin</a> highly recommended.",
  "Author"       : "Dan Fuhry",
  "Version"      : "0.1.1",
  "Author URI"   : "http://enanocms.org/",
  "Version list" : ['0.1', '0.1.1']
}
**!*/

// Register namespace and ACLs
$plugins->attachHook('acl_rule_init', 'gorilla_setupcore($this, $session);');
// Add our special page
$plugins->attachHook('session_started', 'register_special_page(\'NewPaste\', \'gorilla_page_create\', true);');

// constants
define('PASTE_PRIVATE', 1);

function gorilla_setupcore(&$paths, &$session)
{
  // register our paste namespace
  $nssep = substr($paths->nslist['Special'], -1);
  $paths->create_namespace('Paste', 'Paste' . $nssep);
  
  // create our ACLs
  /**
   * @param string $acl_type An identifier for this field
   * @param int $default_perm Whether permission should be granted or not if it's not specified in the ACLs.
   * @param string $desc A human readable name for the permission type
   * @param array $deps The list of dependencies - this should be an array of ACL types
   * @param string $scope Which namespaces this field should apply to. This should be either a pipe-delimited list of namespace IDs or just "All".
   */
   
  $session->acl_extend_scope('read', 'Paste', $paths);
  $session->acl_extend_scope('post_comments', 'Paste', $paths);
  $session->acl_extend_scope('edit_comments', 'Paste', $paths);
  $session->acl_extend_scope('mod_comments', 'Paste', $paths);
  $session->acl_extend_scope('create_page', 'Paste', $paths);
  $session->acl_extend_scope('mod_misc', 'Paste', $paths);
  
  $session->register_acl_type('delete_paste_own', AUTH_ALLOW, 'gorilla_acl_delete_paste_own', array(), 'Paste');
  $session->register_acl_type('delete_paste_others', AUTH_DISALLOW, 'gorilla_acl_delete_paste_others', array(), 'Paste');
}

// Our paste creation page
function page_Special_NewPaste()
{
  global $db, $session, $paths, $template, $plugins; // Common objects
  global $lang, $output;
  
  $have_geshi = isset($GLOBALS['geshi_supported_formats']);
  $perms = $session->fetch_page_acl('0', 'Paste');
  $have_permission = $perms->get_permissions('create_page');
  
  if ( $paths->getParam(0) === 'ajaxsubmit' )
  {
    header('Content-type: text/plain');
    echo gorilla_process_post($have_geshi, $have_permission, true);
    return true;
  }
  
  $private = false;
  $highlight = isset($_COOKIE['g_highlight']) ? $_COOKIE['g_highlight'] : 'plaintext';
  $text = '';
  $title = '';
  $ttl = 3600;
  $copy_from = false;
  
  if ( preg_match('/^Copy=([0-9]+)$/', $paths->getParam(0), $match) )
  {
    $paste_id = intval($match[1]);
    $q = $db->sql_query('SELECT paste_flags, paste_language, paste_text, paste_title, paste_ttl FROM ' . table_prefix . "pastes WHERE paste_id = $paste_id;");
    if ( !$q )
      $db->_die();
    
    list($flags, $highlight, $text, $title, $ttl) = $db->fetchrow_num();
    $db->free_result();
    $private = $flags & PASTE_PRIVATE ? true : false;
    $copy_from = $paste_id;
    
    if ( $flags & PASTE_PRIVATE )
    {
      if ( @$_GET['hash'] !== gorilla_sign($paste_id, $text) )
      {
        die_friendly($lang->get('etc_access_denied_short'), '<p>' . $lang->get('gorilla_msg_wrong_hash') . '</p>');
      }
    }
  }
  
  $output->header();
  
  ?>
  <script type="text/javascript">
  var gorilla_have_permission = <?php echo $have_permission ? 'true' : 'false'; ?>;
  function gorilla_create_submit()
  {
    if ( !window.gorilla_have_permission && user_level < USER_LEVEL_MEMBER )
    {
      load_component('login');
      
      ajaxLogonInit(function(k, response)
        {
          window.gorilla_have_permission = true;
          document.forms['gorilla_create'].submit();
        }, USER_LEVEL_MEMBER);
      return false;
    }
    
    try
    {
      load_component(['jquery', 'jquery-ui']);
      $('#gorilla_submit_result').empty().hide();
      
      var whitey = whiteOutElement(document.forms['gorilla_create']);
      
      var parent = parseInt($('#gorilla_parent').val());
      if ( isNaN(parent) )
        parent = 0;
      
      var json_packet = {
        highlight: $('#gorilla_highlight').val(),
        text: $('#gorilla_create_text').val(),
        is_private: $('#gorilla_private:checked').val() ? true : false,
        nick: $('#gorilla_nick').val(),
        title: $('#gorilla_title').val(),
        ttl: parseInt($('.gorilla_ttl:checked').val()),
        parent: parent,
        hash: $('#gorilla_hash').val()
      };
      json_packet = ajaxEscape(toJSONString(json_packet));
      ajaxPost(makeUrlNS('Special', 'NewPaste/ajaxsubmit'), 'r=' + json_packet, function(ajax)
        {
          if ( ajax.readyState == 4 && ajax.status == 200 )
          {
            var failed = parseInt((String(ajax.responseText)).substr(0, 1));
            if ( failed == 1 )
              whiteOutReportFailure(whitey);
            else
              whiteOutReportSuccess(whitey);
              
            var response = (String(ajax.responseText)).substr(2);
            
            setTimeout(function()
              {
                window.scroll(0, 0);
                $('#gorilla_submit_result').html(response).show('blind', 150);
              }, 1250);
          }
        });
      return false;
    }
    catch(e)
    {}
    
    return true;
  }
  addOnloadHook(function()
    {
      load_component('expander');
    });
  </script>
  <div id="gorilla_submit_result">
  <?php
    echo substr(gorilla_process_post($have_geshi, $have_permission), 2);
  ?>
  </div>
  
  <form action="<?php echo makeUrlNS('Special', 'NewPaste'); ?>" method="post" name="gorilla_create" onsubmit="return gorilla_create_submit();">
  
    <?php
    if ( $copy_from )
    {
      echo '<p style="float: left;">' . $lang->get('gorilla_msg_copying_from', array('paste_id' => $copy_from, 'paste_url' => makeUrlNS('Paste', $copy_from, false, true))) . '</p>';
    }
    ?>
  
    <!-- private -->
    <div style="float: right; margin: 10px 1%;">
      <label title="<?php echo $lang->get('gorilla_lbl_private_hint'); ?>">
        <input type="checkbox" name="is_private" id="gorilla_private" <?php if ( $private ) echo 'checked="checked"'; ?> />
        <img alt="<?php echo $lang->get('gorilla_lbl_private'); ?>" src="<?php echo cdnPath; ?>/images/lock16.png" />
      </label>
    </div>
    
    <!-- highlighting -->
    <div style="float: right; margin: 10px;">
    
    <?php echo $lang->get('gorilla_lbl_highlight'); ?>
    <?php if ( $have_geshi ): ?>
      <select name="highlight" id="gorilla_highlight">
        <?php
        // print out options for each GeSHi format
        global $geshi_supported_formats;
        $formats = array_merge(array('plaintext'), $geshi_supported_formats);
        foreach ( $formats as $format )
        {
          // $string = str_replace('-', '_', "geshi_lang_$format");
          // if ( ($_ = $lang->get($string)) !== $string )
          //   $string = $_;
          // else
            $string = $format;
            
          $sel = ( $format == $highlight ) ? ' selected="selected"' : '';
          echo '<option value="' . $format . '"' . $sel . '>' . $string . '</option>' . "\n          ";
        }
        ?>
      </select>
    <?php else: ?>
      <span style="color: #808080;"><?php echo $lang->get('gorilla_msg_no_geshi'); ?></span>
      <input type="hidden" name="highlight_type" id="gorilla_highlight" value="plaintext" />
    <?php endif; ?>
                   
    </div>
  
    <!-- text box -->
    
    <textarea id="gorilla_create_text" name="text" rows="30" cols="80" style="width: 98%; display: block; margin: 10px auto; clear: both;"><?php echo htmlspecialchars($text); ?></textarea>
    
    <fieldset enano:expand="closed" style="margin-bottom: 10px;">
      <legend><?php echo $lang->get('gorilla_btn_advanced_options'); ?></legend>
      <div>
      
      <!-- title -->
      <p>
        <?php echo $lang->get('gorilla_lbl_title'); ?>
        <input type="text" name="title" id="gorilla_title" size="40" value="<?php echo htmlspecialchars($title); ?>" />
      </p>
      
      <!-- nick -->
      <p>
        <?php echo $lang->get('gorilla_lbl_nick'); ?>
        <?php
        if ( !$have_permission && !$session->user_logged_in )
        {
          echo '<em>' . $lang->get('gorilla_msg_using_login_nick') . '</em>';
          echo '<input type="hidden" name="nick" id="gorilla_nick" value="" />';
        }
        else if ( $session->user_logged_in )
        {
          $rankinfo = $session->get_user_rank($session->user_id);
          echo '<em>' . $lang->get('gorilla_msg_using_logged_in_nick') . '</em> ';
          echo '<span style="' . $rankinfo['rank_style'] . '">' . $session->username . '</span>';
          echo '<input type="hidden" name="nick" id="gorilla_nick" value="" />';
        }
        else
        {
          echo '<input type="text" name="nick" id="gorilla_nick" value="' . $lang->get('gorilla_nick_anonymous') . '" />';
        }
        ?>
      </p>
      
      <!-- ttl -->
      <p>
        <?php echo $lang->get('gorilla_lbl_ttl'); ?>
        <em>
        <label><input<?php if ( !in_array($ttl, array(0, 86400, 2592000)) ) echo ' checked="checked"'; ?> class="gorilla_ttl" type="radio" name="ttl" value="3600" /> <?php echo $lang->get('gorilla_lbl_ttl_hour'); ?></label>
        <label><input<?php if ( $ttl == 86400                             ) echo ' checked="checked"'; ?> class="gorilla_ttl" type="radio" name="ttl" value="86400" /> <?php echo $lang->get('gorilla_lbl_ttl_day'); ?></label>
        <label><input<?php if ( $ttl == 2592000                           ) echo ' checked="checked"'; ?> class="gorilla_ttl" type="radio" name="ttl" value="2592000" /> <?php echo $lang->get('gorilla_lbl_ttl_month'); ?></label>
        <label><input<?php if ( $ttl == 0                                 ) echo ' checked="checked"'; ?> class="gorilla_ttl" type="radio" name="ttl" value="0" /> <?php echo $lang->get('gorilla_lbl_ttl_forever'); ?></label>
        </em>
      </p>
      
      <!-- reply -->
      <p>
        <?php echo $lang->get('gorilla_lbl_reply'); ?><input id="gorilla_parent" name="parent" size="8" value="<?php echo $copy_from ? strval($copy_from) : ''; ?>" />
      </p>
      
      </div>
    </fieldset>
    
    <!-- hash -->
    <?php if ( $private && $copy_from ): ?>
    <input type="hidden" name="hash" id="gorilla_hash" value="<?php echo gorilla_sign($paste_id, $text); ?>" />
    <?php else: ?>
    <input type="hidden" name="hash" id="gorilla_hash" value="" />
    <?php endif; ?>
  
    <!-- login notice -->
  
    <?php if ( !$have_permission && !$session->user_logged_in ): ?>
    <div class="info-box-mini">
      <?php echo $lang->get('gorilla_msg_will_prompt_for_login'); ?>
    </div>
    <?php endif; ?>
    
    <!-- submit -->
  
    <input type="submit" style="font-size: x-large;" value="<?php echo $lang->get('gorilla_btn_submit'); ?>" />
  </form>
  <?php
  
  $output->footer();
}

// actual processing for submitted pastes
function gorilla_process_post($have_geshi, $have_permission, $is_ajax = false)
{
  global $db, $session, $paths, $template, $plugins; // Common objects
  global $lang;
  
  $fields = array(
      'highlight' => 'string',
      'text' => 'string',
      'is_private' => 'boolean',
      'nick' => 'string',
      'title' => 'string',
      'ttl' => 'integer',
      'parent' => 'integer',
      'hash' => 'string'
    );
  
  $info = array();
  
  if ( $is_ajax )
  {
    try
    {
      $request = enano_json_decode(@$_POST['r']);
    }
    catch ( Exception $e )
    {
      return '1;No JSON request given';
    }
    foreach ( $fields as $field => $type )
    {
      if ( !isset($request[$field]) )
        return "1;Field \"$field\" not provided";
      if ( ($ftype = gettype($request[$field])) !== $type )
        return "1;Field \"$field\": expected $type, got $ftype";
      
      $info[$field] = $request[$field];
    }
  }
  else
  {
    foreach ( $fields as $field => $type )
    {
      if ( !isset($_POST[$field]) && $field != 'is_private' )
        return '';
    }
    $info = array(
        'highlight' => $_POST['highlight'],
        'text' => $_POST['text'],
        'is_private' => isset($_POST['is_private']),
        'nick' => $_POST['nick'],
        'title' => $_POST['title'],
        'ttl' => intval($_POST['ttl']),
        'parent' => intval($_POST['parent']),
        'hash' => $_POST['hash']
      );
  }
  
  if ( $info['parent'] )
  {
    // make sure we have the right hash
    $q = $db->sql_query('SELECT paste_text FROM ' . table_prefix . "pastes WHERE paste_id = {$info['parent']};");
    if ( !$q )
      $db->_die();
    
    if ( $db->numrows() > 0 )
    {
      list($old_text) = $db->fetchrow_num();
      if ( $info['hash'] !== gorilla_sign($info['parent'], $old_text) )
      {
        $info['parent'] = 0;
      }
    }
    else
    {
      $info['parent'] = 0;
    }
  }
  
  if ( !$have_permission )
  {
    return '1;<div class="error-box-mini">' . $lang->get('etc_access_denied') . '</div>';
  }
  
  // validate highlight scheme
  global $geshi_supported_formats;
  if ( is_array($geshi_supported_formats) )
  {
    if ( !in_array($info['highlight'], $geshi_supported_formats) )
      $info['highlight'] = 'plaintext';
  }
  else
  {
    $info['highlight'] = 'plaintext';
  }
  
  setcookie('g_highlight', $info['highlight'], time() + 365 * 24 * 60 * 60);
  
  $info_db = $info;
  foreach ( $info_db as &$item )
  {
    if ( is_string($item) )
      $item = "'" . $db->escape($item) . "'";
    else if ( is_bool($item) )
      $item = $item ? '1' : '0';
    else if ( is_int($item) )
      $item = strval($item);
    else
      $item = "''";
  }
  
  $now = time();
  $flags = 0;
  if ( $info['is_private'] )
    $flags |= PASTE_PRIVATE;
  
  $sql = 'INSERT INTO ' . table_prefix . "pastes( paste_title, paste_text, paste_author, paste_author_name, paste_author_ip, paste_language, paste_timestamp, paste_ttl, paste_flags, paste_parent ) VALUES\n"
  . "  ( {$info_db['title']}, {$info_db['text']}, $session->user_id, {$info_db['nick']}, '{$_SERVER['REMOTE_ADDR']}', {$info_db['highlight']}, $now, {$info_db['ttl']}, $flags, {$info_db['parent']} );";
       
  if ( !$db->sql_query($sql) )
    ( $is_ajax ) ? $db->die_json() : $db->_die();
  
  // avoid insert_id
  $q = $db->sql_query('SELECT paste_id FROM ' . table_prefix . "pastes WHERE paste_timestamp = $now ORDER BY paste_id DESC LIMIT 1;");
  if ( !$q )
    ( $is_ajax ) ? $db->die_json() : $db->_die();
  list($paste_id) = $db->fetchrow_num();
  $db->free_result();
  
  $params = false;
  if ( $flags & PASTE_PRIVATE )
    $params = 'hash=' . gorilla_sign($paste_id, $info['text']);
  
  $paste_url = makeUrlComplete('Paste', $paste_id, $params, true);
  
  return '0;'
           . '<div class="info-box-mini">' . $lang->get('gorilla_msg_created') . '</div>'
           . '<div style="font-size: larger; text-align: center; margin: 30px 0;">' . $lang->get('gorilla_msg_paste_url') . '<br /><input onfocus="this.select()" readonly="readonly" type="text" size="50" style="font-size: larger; text-align: center;" value="' . $paste_url . '" /></div>';
}

###############################################################################
## PASTE DISPLAY
###############################################################################

class Namespace_Paste extends Namespace_Default
{
  protected $paste_data = false;
  
  public function __construct($page_id, $namespace, $revid = 0)
  {
    $this->page_id = $page_id;
    $this->namespace = $namespace;
    $this->revision_id = 0;
    
    $this->build_cdata();
  }
  
  public function build_cdata()
  {
    global $db, $session, $paths, $template, $plugins; // Common objects
    global $lang;
  
    $this->exists = false;
    if ( ctype_digit($this->page_id) )
    {
      $q = $db->sql_query('SELECT p.*, u.username FROM ' . table_prefix . "pastes AS p\n"
                        . "  LEFT JOIN " . table_prefix . "users AS u\n"
                        . "    ON ( u.user_id = p.paste_author )\n"
                        . "  WHERE p.paste_id = $this->page_id;");
      if ( $db->numrows() > 0 )
      {
        $this->exists = true;
        $this->paste_data = $db->fetchrow();
      }
      $db->free_result();
    }
    if ( $this->exists )
    {
      $this->cdata = array(
        'name' => empty($this->paste_data['paste_title']) ? $lang->get('gorilla_untitled_paste') : $this->paste_data['paste_title'],
        'urlname' => $this->page_id,
        'namespace' => $this->namespace,
        'special' => 0,
        'visible' => 0,
        'comments_on' => 1,
        'protected' => 0,
        'delvotes' => 0,
        'delvote_ips' => '',
        'wiki_mode' => 2,
        'page_exists' => true,
        'page_format' => getConfig('default_page_format', 'wikitext')
      );
    }
    else
    {
      $this->cdata = array(
        'name' => $lang->get('gorilla_title_404'),
        'urlname' => $this->page_id,
        'namespace' => $this->namespace,
        'special' => 0,
        'visible' => 0,
        'comments_on' => 0,
        'protected' => 0,
        'delvotes' => 0,
        'delvote_ips' => '',
        'wiki_mode' => 2,
        'page_exists' => false,
        'page_format' => getConfig('default_page_format', 'wikitext')
      );
    }
    $this->cdata = Namespace_Default::bake_cdata($this->cdata);
  }
  
  public function send()
  {
    global $db, $session, $paths, $template, $plugins; // Common objects
    global $output, $lang;
    
    $plugins->attachHook('page_type_string_set', '$this->namespace_string = $lang->get(\'gorilla_template_ns_string\');');
    $template->add_header('<style type="text/css">
      .geshi_highlighted a {
        background-image: none !important;
        padding-right: 0 !important;
      }
      </style>
      ');
    
    if ( $this->exists )
    {
      gorilla_display_paste($this->paste_data);
    }
    else
    {
      $output->header();
      $this->error_404();
      $output->footer();
    }
  }
  
  public function error_404()
  {
    global $lang;
    echo '<p>' . $lang->get('gorilla_msg_paste_not_found', array('create_link' => makeUrlNS('Special', 'NewPaste'))) . '</p>';
  }
}

function gorilla_display_paste($data)
{
  global $db, $session, $paths, $template, $plugins; // Common objects
  global $lang;
  global $output;
  
  extract($data);
  $perms = $session->fetch_page_acl($paste_id, 'Paste');
  
  $localhash = false;
  $hash = gorilla_sign($paste_id, $paste_text);
  if ( $paste_flags & PASTE_PRIVATE )
  {
    $localhash = $hash;
  }
  
  if ( $paste_flags & PASTE_PRIVATE || isset($_GET['delete']) )
  {
    if ( @$_GET['hash'] !== $hash )
    {
      // allow viewing regardless if mod or admin
      if ( !($session->user_level >= USER_LEVEL_MOD && !isset($_GET['delete'])) )
      {
        die_friendly($lang->get('etc_access_denied_short'), '<p>' . $lang->get('gorilla_msg_wrong_hash') . '</p>');
      }
    }
  }
  
  if ( isset($_GET['format']) )
  {
    switch($_GET['format'])
    {
      case 'text':
      case 'plain':
        header('Content-type: text/plain');
        echo $paste_text;
        return true;
        break;
      case 'download':
        header('Content-type: text/plain');
        header('Content-disposition: attachment; filename="paste' . $paste_id . '.txt"');
        header('Content-length: ' . strlen($paste_text));
        echo $paste_text;
        return true;
        break;
    }
  }
  
  $output->header();
  
  $perm = $paste_author == $session->user_id ? 'delete_paste_own' : 'delete_paste_others';
  if ( isset($_GET['delete']) && !isset($_POST['cancel']) )
  {
    if ( isset($_POST['delete_confirm']) )
    {
      $q = $db->sql_query('DELETE FROM ' . table_prefix . "pastes WHERE paste_id = $paste_id;");
      if ( !$q )
        $db->_die();
      
      echo '<p>' . $lang->get('gorilla_msg_paste_deleted') . '</p>';
    }
    else
    {
      $submit_url = makeUrlNS('Paste', $paste_id, 'delete&hash=' . gorilla_sign($paste_id, $paste_text), true);
      ?>
      <form action="<?php echo $submit_url; ?>" method="post">
        <p><?php echo $lang->get('gorilla_msg_delete_confirm'); ?></p>
        <p>
          <input type="submit" value="<?php echo $lang->get('gorilla_btn_delete_confirm'); ?>" name="delete_confirm" style="font-weight: bold;" />
          <input type="submit" value="<?php echo $lang->get('etc_cancel'); ?>" name="cancel" />
        </p>
      </form>
      <?php
    }
    $output->footer();
    return true;
  }
  
  if ( $paste_author > 1 )
  {
    // logged-in user
    $rank_info = $session->get_user_rank($paste_author);
    $user_link = '<a href="' . makeUrlNS('User', $username, false, true) . '" style="' . $rank_info['rank_style'] . '">' . htmlspecialchars($username) . '</a>';
  }
  else
  {
    // anonymous
    $user_link = '<b>' . htmlspecialchars($paste_author_name) . '</b>';
  }
  $date = enano_date('D, j M Y H:i:s', $paste_timestamp);
  $pasteinfo = $lang->get('gorilla_msg_paste_info', array('user_link' => $user_link, 'date' => $date));
  
  echo '<div class="mdg-infobox" style="margin: 10px 0;">';
  echo '<div style="float: right;">
          ' . $lang->get('gorilla_msg_other_formats', array('plain_link' => makeUrlNS('Paste', $paste_id, 'format=text' .  ( $localhash ? "&hash=$localhash" : '' ), true), 'download_link' => makeUrlNS('Paste', $paste_id, 'format=download' .  ( $localhash ? "&hash=$localhash" : '' ), true))) . '
          /
          <a title="' . $lang->get('gorilla_tip_new_paste') . '" href="' . makeUrlNS('Special', 'NewPaste') . '">' . $lang->get('gorilla_btn_new_paste') . '</a>
          /
          <a title="' . $lang->get('gorilla_tip_copy_from_this') . '" href="' . makeUrlNS('Special', 'NewPaste/Copy=' . $paste_id, ( $paste_flags & PASTE_PRIVATE ? 'hash=' . gorilla_sign($paste_id, $paste_text) : false ), true) . '">' . $lang->get('gorilla_btn_copy_from_this') . '</a>';
          
  if ( $paste_parent )
  {
    // pull flags of parent
    $q = $db->sql_query('SELECT paste_text, paste_flags FROM ' . table_prefix . "pastes WHERE paste_id = $paste_parent;");
    if ( !$q )
      $db->_die();
    
    if ( $db->numrows() > 0 )
    {
      list($parent_text, $parent_flags) = $db->fetchrow_num();
      $parenthash = false;
      if ( $parent_flags & PASTE_PRIVATE )
      {
        $parenthash = gorilla_sign($paste_parent, $parent_text);
      }
      
      echo ' / ' . $lang->get('gorilla_msg_reply_to', array(
          'parent_link' => makeUrlNS('Paste', $paste_parent, ( $parenthash ? "hash=$parenthash" : '' ), true),
          'diff_link' => makeUrlNS('Paste', $paste_id, 'diff_parent' .  ( $localhash ? "&hash=$localhash" : '' ), true),
          'parent_id' => $paste_parent
        ));
    }
    $db->free_result($q);
  }
          
  if ( $perms->get_permissions($perm) && $session->user_logged_in )
  {
    echo ' / <a title="' . $lang->get('gorilla_tip_delete') . '" href="' . makeUrlNS('Paste', $paste_id, 'delete&hash=' . gorilla_sign($paste_id, $paste_text), true) . '">' . $lang->get('gorilla_btn_delete') . '</a>';
  }
  if ( $perms->get_permissions('mod_misc') )
  {
    echo ' / <span title="' . $lang->get('gorilla_tip_paste_ip') . '">' . $paste_author_ip . '</span>';
  }
  
  echo '</div>';
  echo $pasteinfo;
  echo '</div>';
  
  if ( isset($_GET['diff_parent']) && isset($parent_text) )
  {
    echo '<p>' . $lang->get('gorilla_btn_view_normal', array('orig_url' => makeUrlNS('Paste', $paste_id, ( $localhash ? "hash=$localhash" : '' ), true))) . '</p>';
    // convert to unix newlines to avoid confusing the diff engine (seen on Chromium on Linux)
    echo RenderMan::diff(str_replace("\r\n", "\n", $parent_text), str_replace("\r\n", "\n", $paste_text));
    $output->footer();
    return;
  }
  
  if ( preg_match('/^## /m', $paste_text) )
  {
    gorilla_show_text_multi($paste_text, $paste_language);
  }
  else
  {
    gorilla_show_text($paste_text, $paste_language);
  }
  
  $output->footer();
}

function gorilla_show_text($text, $lang)
{
  $have_geshi = isset($GLOBALS['geshi_supported_formats']);
  
  if ( $have_geshi )
  {
    if ( $lang == 'plaintext' )
      $lang = 'text';
    
    if ( !defined('GESHI_ROOT') )
    define('GESHI_ROOT', ENANO_ROOT . '/plugins/geshi/');
  
    require_once ( GESHI_ROOT . 'base.php' );
    
    $geshi = new GeSHi($text, $lang, null);
    $geshi->set_header_type(GESHI_HEADER_DIV);
    $geshi->enable_line_numbers(GESHI_FANCY_LINE_NUMBERS, 2);
    $geshi->set_overall_class('geshi_highlighted');
    $parsed = $geshi->parse_code();
    
    echo $parsed;
  }
  else
  {
    echo '<h3>FIXME: WRITE REAL PLAINTEXT FORMATTER</h3>';
    echo '<pre>' . htmlspecialchars($text) . '</pre>';
  }
}

function gorilla_show_text_multi($text, $lang)
{
  $sections = preg_split('/^## .*$/m', $text);
  $headingcount = preg_match_all('/^## (.+?)(?: \[([a-z_-]+)\])? *\r?$/m', $text, $matches);
  
  // if we have one heading less than the number of sections, print the first section
  while ( count($sections) > $headingcount )
  {
    gorilla_show_text(trim($sections[0], "\r\n"), $lang);
    
    unset($sections[0]);
    $sections = array_values($sections);
  }
  
  foreach ( $matches[0] as $i => $_ )
  {
    $clang = !empty($matches[2][$i]) ? $matches[2][$i] : $lang;
    echo '<h2>' . htmlspecialchars(trim($matches[1][$i])) . '</h2>';
    gorilla_show_text(trim($sections[$i], "\r\n"), $clang);
  }
}

function gorilla_sign($id, $text)
{
  return hmac_sha1($id, sha1($text));
}

// make sure pastes are pruned on a regular basis
function gorilla_prune_expired()
{
  global $db, $session, $paths, $template, $plugins; // Common objects
  
  $now = time();
  $q = $db->sql_query('DELETE FROM ' . table_prefix . "pastes WHERE paste_timestamp + paste_ttl < $now AND paste_ttl > 0;");
}

register_cron_task('gorilla_prune_expired', 1);

// Search handler
$plugins->attachHook('session_started', 'gorilla_attach_search();');
function gorilla_attach_search()
{
  global $lang;
  register_search_handler(array(
    'table' => 'pastes',
    'titlecolumn' => 'paste_title',
    'datacolumn' => 'paste_text',
    'uniqueid' => 'ns=Paste;cid={paste_id}',
    'additionalcolumns' => array('paste_id', 'paste_language'),
    'resultnote' => $lang->get('gorilla_lbl_search_tag'),
    'linkformat' => array(
        'page_id' => '{paste_id}',
        'namespace' => 'Paste'
      ),
    'additionalwhere' => 'AND (paste_flags & ' . PASTE_PRIVATE . ') = 0',
  ));
}

/**!install dbms="mysql"; **
CREATE TABLE {{TABLE_PREFIX}}pastes(
  paste_id int(18) NOT NULL auto_increment,
  paste_title text DEFAULT NULL,
  paste_text text NOT NULL DEFAULT '',
  paste_author int(12) NOT NULL DEFAULT 1,
  paste_author_name varchar(255) NOT NULL DEFAULT 'Anonymous',
  paste_author_ip varchar(39) NOT NULL,
  paste_language varchar(32) NOT NULL DEFAULT 'plaintext',
  paste_timestamp int(12) NOT NULL DEFAULT 0,
  paste_ttl int(12) NOT NULL DEFAULT 86400,
  paste_flags int(8) NOT NULL DEFAULT 0,
  paste_parent int(18) NOT NULL DEFAULT 0,
  PRIMARY KEY ( paste_id )
) ENGINE=`MyISAM` CHARSET=`UTF8` COLLATE=`utf8_bin`;

**!*/

/**!upgrade from="0.1"; to="0.1.1"; dbms="mysql"; **
ALTER TABLE {{TABLE_PREFIX}}pastes ADD COLUMN paste_parent int(18) NOT NULL DEFAULT 0;
**!*/

/**!uninstall **
DROP TABLE {{TABLE_PREFIX}}pastes;
**!*/

/**!language**
<code>
{
  eng: {
    categories: ['meta', 'gorilla'],
    strings: {
      meta: {
        gorilla: 'Gorilla',
      },
      gorilla: {
        acl_delete_paste_own: 'Delete own pastes',
        acl_delete_paste_others: 'Delete others\' pastes',
        
        page_create: 'Create paste',
        msg_copying_from: 'Copying from <a href="%paste_url%">paste #%paste_id%</a>.',
        lbl_highlight: 'Language:',
        msg_no_geshi: 'Not supported',
        btn_advanced_options: 'Advanced options',
        lbl_private: 'Private paste',
        lbl_private_hint: 'Don\'t list this paste or allow it to be included in searches',
        lbl_title: 'Title:',
        lbl_nick: 'Nickname:',
        nick_anonymous: 'Anonymous',
        msg_using_login_nick: 'Using username provided during login',
        msg_using_logged_in_nick: 'Logged in; using nickname:',
        lbl_ttl: 'Keep it for:',
        lbl_ttl_hour: '1 hour',
        lbl_ttl_day: '1 day',
        lbl_ttl_month: '1 month',
        lbl_ttl_forever: 'forever',
        lbl_reply: 'Reply to paste: #',
        msg_will_prompt_for_login: 'You are not logged in. You will be asked to log in when you click the submit button below.',
        btn_submit: 'Paste it!',
        
        msg_created: 'Paste created.',
        msg_paste_url: 'Share this paste using the following URL:',
        
        untitled_paste: 'Untitled paste',
        title_404: 'Paste not found',
        msg_paste_not_found: 'This paste cannot be found or has been deleted. <a href="%create_link%">Create a new paste</a>',
        msg_wrong_hash: 'Either you are trying to view a private paste which requires a hash in the URL or you were linked to this page from an outside source and the CSRF protection kicked in.',
        
        msg_paste_info: 'By %user_link%, pasted on %date%',
        msg_other_formats: '<a title="View as plain text" href="%plain_link%" onclick="window.open(this.href, \'gorillaplaintext\', \'address=no,status=no,toolbar=no,menus=no,scroll=yes,width=640,height=480\'); return false;">raw</a> / <a title="Download paste" href="%download_link%">dl</a>',
        btn_new_paste: 'new',
        tip_new_paste: 'Create a new paste',
        btn_copy_from_this: 'cp',
        tip_copy_from_this: 'Create a new paste, copying this one into the form',
        btn_delete: 'rm',
        tip_delete: 'Delete this paste',
        tip_paste_ip: 'IP address of paste author',
        msg_reply_to: 'parent: <a href="%parent_link%">#%parent_id%</a> (<a href="%diff_link%">diff</a>)',
        btn_view_normal: '<b>Difference from parent</b> (<a href="%orig_url%">back to paste</a>)',
        template_ns_string: 'paste',
        
        msg_paste_deleted: 'Paste deleted.',
        msg_delete_confirm: 'Really delete this paste?',
        btn_delete_confirm: 'Delete',
        
        lbl_search_tag: '[Paste]',
      }
    }
  }
}
</code>
**!*/
