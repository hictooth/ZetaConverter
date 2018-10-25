/*
phpBB Importer - imports users
Copyright (C) 2018  tapedrive

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

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


// setup the custom profile fields (I know we shouln't manipulate the db directly, but there isn't another option)
$sql = 'UPDATE ' . PROFILE_FIELDS_TABLE . " SET field_active=1 WHERE field_name = 'phpbb_interests' OR field_name = 'phpbb_aol' OR field_name = 'phpbb_yahoo'";
$db->sql_query($sql);


// get the id of the registered users group
$group_name = 'REGISTERED'; // 'ADMINISTRATORS', 'GLOBAL MODERATORS',
$sql = 'SELECT group_id FROM ' . GROUPS_TABLE . " WHERE group_name = '" . $db->sql_escape($group_name) . "' AND group_type = " . GROUP_SPECIAL;
$result = $db->sql_query($sql);
$row = $db->sql_fetchrow($result);
$registeredUsersGroup = $row['group_id'];


// retrieve the exported data
$zeta = new ZetaDB();
if(!$zeta) {
  echo $zeta->lastErrorMsg();
  die();
}
$sql = "SELECT * from member;";
$ret = $zeta->query($sql);


// loop through and add all users
while($row = $ret->fetchArray(SQLITE3_ASSOC) ) {

  /*if ($row['id'] < 3886684) {
    continue;
  }*/

  echo "Processing user " . $row['id'] . " :: " . $row['name'] . "\n";

  // get number of posts by this user
  //$rows = $zeta->query("SELECT COUNT(*) as count FROM post WHERE member=" . $row['id']); // should probably protect against injection
  //$numPosts = $rows->fetchArray()['count'];

  // get last post time by this user
  $rows = $zeta->query("SELECT date FROM post WHERE member=" . $row['id'] . " ORDER BY date DESC LIMIT 1;"); // should probably protect against injection
  $lastPost = $rows->fetchArray()['date'];

  // we need to strip out all characters that aren't in the Basic Multilingual Plane (BMP) - because MySQL doesn't support them
  $row['name'] = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $row['name']);
  $row['email'] = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $row['email']);
  $row['group'] = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $row['group']);
  $row['title'] = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $row['title']);
  $row['name'] = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $row['name']);
  $row['photo'] = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $row['photo']);
  $row['avatarlocal'] = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $row['avatarlocal']);
  $row['avatarremote'] = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $row['avatarremote']);
  $row['interests'] = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $row['interests']);
  $row['signaturebb'] = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $row['signaturebb']);
  $row['location'] = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $row['location']);
  $row['aol'] = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $row['aol']);
  $row['yahoo'] = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $row['yahoo']);
  $row['msn'] = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $row['msn']);
  $row['homepage'] = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $row['homepage']);


  // do stuff with the signature - we're aparently meant to do this
  $signaturebb = $row['signaturebb'];
  $signatureuid = $signaturebitfield = $options = '';
  generate_text_for_storage($signaturebb, $signatureuid, $signaturebitfield, $options, true, true, true);

  // build the user_data array
  $user_row = array(
    'user_id'                   => $row['id'],
    'user_type'                 => USER_NORMAL,
    'group_id'                  => $registeredUsersGroup,
    'user_ip'                   => $row['ip'],
    'user_regdate'              => $row['joined'],
    'username'                  => $row['name'],
    'user_password'             => phpbb_hash("dragons"),
    'user_email'                => $row['email'],
    'user_lastvisit'            => $row['lastactive'],
    'user_lastpost_time'        => $lastPost,
    'user_warnings'             => $row['warning'],
    'user_posts'                => $row['numposts'],
    'user_timezone'             => $row['hourdifference'],
    'user_allow_pm'             => $row['pms'],
    'user_allow_viewemail'      => 0,
    'user_avatar'               => $row['avatarremote'],
    'user_avatar_type'          => 'avatar.driver.remote',
    //'user_avatar_width'         => $avatarWidth,
    //'user_avatar_height'        => $avatarHeight,
    'user_sig'                  => $signaturebb,
    'user_sig_bbcode_uid'       => $signatureuid,
    'user_sig_bbcode_bitfield'  => $signaturebitfield,
    'user_birthday'             => $row['birthday'],
    'user_number'               => $row['number'],
  );

  // custom profile fields that don't go into the main user table
  $cp_data = array(
    'pf_phpbb_location'         => $row['location'],
    'pf_phpbb_website'          => $row['homepage'],
    'pf_phpbb_interests'        => $row['interests'],
    'pf_phpbb_aol'              => $row['aol'],
    'pf_phpbb_yahoo'            => $row['yahoo'],
    'pf_phpbb_title'            => $row['title'],
    'pf_phpbb_photo'            => $row['photo'],
    'pf_phpbb_msn'              => $row['msn'],
  );


  // add the user in
  $user_id = user_add($user_row, $cp_data);
}

?>
