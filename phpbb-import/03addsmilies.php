<?php

// sqlite3 class to read the exported data
class ZetaDB extends SQLite3 {
  function __construct() {
    $this->open('database.db');
  }
}


// setup phpbb stuff
define('IN_PHPBB', true);
$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : './';
$phpEx = substr(strrchr(__FILE__, '.'), 1);
include($phpbb_root_path . 'common.' . $phpEx);
include($phpbb_root_path . 'includes/functions_user.' . $phpEx);


// Start session management
$user->session_begin();
$auth->acl($user->data);
$user->setup();


// delete all the current smilies
$sql = 'DELETE FROM phpbb_smilies';
$db->sql_query($sql);




// retrieve the exported smilies
$zeta = new ZetaDB();
if(!$zeta) {
  echo $zeta->lastErrorMsg();
  die();
}
$sql = "SELECT * from emoji;";
$ret = $zeta->query($sql);


// loop through and add all emoji
while($emoji = $ret->fetchArray(SQLITE3_ASSOC) ) {

  echo "Processing emoji " . $emoji['id'] . " :: " . $emoji['name'] . "\n";

  // get emoji data
  $fullpath = "images/smilies/" . $emoji['local'];
  list($width, $height, $type, $attr) = getimagesize($fullpath);

  $emoji_sql = array(
    'smiley_id'           => 0,
    'code'                => $emoji['code'],
    'emotion'             => $emoji['code'],
    'smiley_url'          => $emoji['local'],
    'smiley_width'        => $width,
    'smiley_height'       => $height,
    'smiley_order'        => $emoji['id'],
    'display_on_posting'  => 1,
  );

  // add the emoji
  $sql = 'INSERT INTO phpbb_smilies ' . $db->sql_build_array('INSERT', $emoji_sql);
  $db->sql_query($sql);
}

?>
