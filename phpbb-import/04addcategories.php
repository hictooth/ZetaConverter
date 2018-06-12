<?php

/*
A simple way to add a batch of new forums.
This script is placed in your phpBB root directory. That is where config.php lives.
Changes variables and run it by entering http://yourforumname.com/create_forums.php in your browser.
*/


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
include($phpbb_root_path . 'includes/functions_admin.' . $phpEx);


// Start session management
$user->session_begin();
$auth->acl($user->data);
$user->setup();



function create_forum($forum) {

  global $db;

  $forum_data = array(
    'parent_id'				=> $forum['parent'],
    'forum_type'			=> 0,
    'forum_status'			=> ITEM_UNLOCKED,
    'forum_parents'			=> '',
    'forum_name'			=> $forum['name'],
    'forum_link'			=> '',
    'forum_desc'			=> '',
    'forum_desc_uid'		=> '',
    'forum_desc_options'	=> 7,
    'forum_desc_bitfield'	=> '',
    'forum_rules'			=> '',
    'forum_rules_uid'		=> '',
    'forum_rules_options'	=> 7,
    'forum_rules_bitfield'	=> '',
    'forum_rules_link'		=> '',
    'forum_image'			=> '',
    'forum_style'			=> 0,
    'display_subforum_list'	=> true,
    'display_on_index'		=> true,
    'forum_topics_per_page'	=> 0,
    'enable_indexing'		=> true,
    'enable_icons'			=> false,
    'enable_prune'			=> false,
    'enable_shadow_prune'	=> false,
    'prune_days'			=> 7,
    'prune_viewed'			=> 7,
    'prune_freq'			=> 1,
    'prune_shadow_days'		=> 7,
    'prune_shadow_freq'		=> 1,
    'forum_password'		=> '',
    'forum_flags' => 32,
  );

  // do left and right id magic

  if ($forum['parent']) {
    // there is a parent (ie it's not a category)

    $sql = 'SELECT left_id, right_id, forum_type
      FROM ' . FORUMS_TABLE . '
      WHERE forum_id = ' . $forum_data['parent_id'];
    $result = $db->sql_query($sql);
    $row = $db->sql_fetchrow($result);
    $db->sql_freeresult($result);

    if (!$row) {
      echo "parent doesn't seem to exist: " . $forum['parent'];
    }

    $sql = 'UPDATE ' . FORUMS_TABLE . '
      SET left_id = left_id + 2, right_id = right_id + 2
      WHERE left_id > ' . $row['right_id'];
    $db->sql_query($sql);

    $sql = 'UPDATE ' . FORUMS_TABLE . '
      SET right_id = right_id + 2
      WHERE ' . $row['left_id'] . ' BETWEEN left_id AND right_id';
    $db->sql_query($sql);

    $forum_data['left_id'] = $row['right_id'];
    $forum_data['right_id'] = $row['right_id'] + 1;
    //$left_id = $row['right_id'];
    //$right_id = $row['right_id'] + 1;
  }
  else
  {
    $sql = 'SELECT MAX(right_id) AS right_id
      FROM ' . FORUMS_TABLE;
    $result = $db->sql_query($sql);
    $row = $db->sql_fetchrow($result);
    $db->sql_freeresult($result);

    $forum_data['left_id'] = $row['right_id'] + 1;
    $forum_data['right_id'] = $row['right_id'] + 2;
    //$left_id = $row['right_id'] + 1;
    //$right_id = $row['right_id'] + 2;

    //echo $left_id;
    //echo $right_id;
  }



  // Create new forum
  $source_forum_id = 1;
  if ($forum['parent'] != 0) {
    $forum_data['forum_type'] = 1;
    $forum_data['forum_flags'] = 48;
    $source_forum_id = 2;
  }

  $sql = 'INSERT INTO ' . FORUMS_TABLE . ' ' . $db->sql_build_array('INSERT', $forum_data);
  $db->sql_query($sql);
  $new_forum_id = $db->sql_nextid();
  echo "new id:" . $new_forum_id;


  // add permissions
  copy_forum_permissions($source_forum_id, $new_forum_id);

  return $new_forum_id;
}

// map zeta ids to phpbb ids
$mapper = array();


$zeta = new ZetaDB();
if(!$zeta) {
  echo $zeta->lastErrorMsg();
  die();
}
$sql = "SELECT * from forum;";
$ret = $zeta->query($sql);


// start with those that don't have a parent
$insertIDs = array();
array_push($insertIDs, 0);

while (count($insertIDs) > 0) {
  $insertID = array_pop($insertIDs);

  // add all IDs this enables us to add, to the $insertIDs array
  $sql = "SELECT * from forum WHERE parent=" . $insertID . " ORDER BY `order`;";
  $ret = $zeta->query($sql);
  while($forum = $ret->fetchArray(SQLITE3_ASSOC) ) {
    // add this forum id to ones we can now do children of
    array_push($insertIDs, $forum['id']);

    // add this forum's ID to the mapper
    $oldid = $forum['id'];

    // correct the parent id to the phpbb id system
    if ($forum['parent'] != 0) {
      $forum['parent'] = $mapper[$forum['parent']];
    }

    // add this forum in
    $newid = create_forum($forum);
    $mapper[$oldid] = $newid;
    echo "created forum " . $forum['name'] . " :: " . $oldid . " -> " . $newid . "\n";
  }
}

$jsonmapper = json_encode($mapper, JSON_PRETTY_PRINT);
if(file_put_contents("mapper.json", $jsonmapper)) {
  echo "Saved mapper to file\n";
}

print_r($mapper);

?>
