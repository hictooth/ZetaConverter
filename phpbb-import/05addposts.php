/*
phpBB Importer - imports posts
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
include($phpbb_root_path . 'includes/functions_admin.' . $phpEx);


// Start session management
$user->session_begin();
$auth->acl($user->data);
$user->setup();



/**
* Submit Post
* @todo Split up and create lightweight, simple API for this.
*/
function import_post($mode, $subject, $username, $topic_type, &$poll_ary, &$data_ary, $update_message = true, $update_search_index = true)
{
	global $db, $auth, $user, $config, $phpEx, $phpbb_root_path, $phpbb_container, $phpbb_dispatcher, $phpbb_log, $request;

	$poll = $poll_ary;
	$data = $data_ary;
	/**
	* Modify the data for post submitting
	*
	* @event core.modify_submit_post_data
	* @var	string	mode				Variable containing posting mode value
	* @var	string	subject				Variable containing post subject value
	* @var	string	username			Variable containing post author name
	* @var	int		topic_type			Variable containing topic type value
	* @var	array	poll				Array with the poll data for the post
	* @var	array	data				Array with the data for the post
	* @var	bool	update_message		Flag indicating if the post will be updated
	* @var	bool	update_search_index	Flag indicating if the search index will be updated
	* @since 3.1.0-a4
	*/
	$vars = array(
		'mode',
		'subject',
		'username',
		'topic_type',
		'poll',
		'data',
		'update_message',
		'update_search_index',
	);
	extract($phpbb_dispatcher->trigger_event('core.modify_submit_post_data', compact($vars)));
	$poll_ary = $poll;
	$data_ary = $data;
	unset($poll);
	unset($data);

	// We do not handle erasing posts here
	if ($mode == 'delete')
	{
		return false;
	}

	if (!empty($data_ary['post_time']))
	{
		$current_time = $data_ary['post_time'];
	}
	else
	{
		$current_time = time();
	}

	if ($mode == 'post')
	{
		$post_mode = 'post';
		$update_message = true;
	}
	else if ($mode != 'edit')
	{
		$post_mode = 'reply';
		$update_message = true;
	}
	else if ($mode == 'edit')
	{
		$post_mode = ($data_ary['topic_posts_approved'] + $data_ary['topic_posts_unapproved'] + $data_ary['topic_posts_softdeleted'] == 1) ? 'edit_topic' : (($data_ary['topic_first_post_id'] == $data_ary['post_id']) ? 'edit_first_post' : (($data_ary['topic_last_post_id'] == $data_ary['post_id']) ? 'edit_last_post' : 'edit'));
	}

	// First of all make sure the subject and topic title are having the correct length.
	// To achieve this without cutting off between special chars we convert to an array and then count the elements.
	$subject = truncate_string($subject, 120);
	$data_ary['topic_title'] = truncate_string($data_ary['topic_title'], 120);

	// Collect some basic information about which tables and which rows to update/insert
	$sql_data = $topic_row = array();
	//$poster_id = ($mode == 'edit') ? $data_ary['poster_id'] : (int) $user->data['user_id'];
	$poster_id = $data_ary['poster_id'];

	// Retrieve some additional information if not present
	if ($mode == 'edit' && (!isset($data_ary['post_visibility']) || !isset($data_ary['topic_visibility']) || $data_ary['post_visibility'] === false || $data_ary['topic_visibility'] === false))
	{
		$sql = 'SELECT p.post_visibility, t.topic_type, t.topic_posts_approved, t.topic_posts_unapproved, t.topic_posts_softdeleted, t.topic_visibility
			FROM ' . TOPICS_TABLE . ' t, ' . POSTS_TABLE . ' p
			WHERE t.topic_id = p.topic_id
				AND p.post_id = ' . $data_ary['post_id'];
		$result = $db->sql_query($sql);
		$topic_row = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);

		$data_ary['topic_visibility'] = $topic_row['topic_visibility'];
		$data_ary['post_visibility'] = $topic_row['post_visibility'];
	}

	// This variable indicates if the user is able to post or put into the queue
	$post_visibility = ITEM_APPROVED;

	// Check the permissions for post approval.
	// Moderators must go through post approval like ordinary users.
	if (!$auth->acl_get('f_noapprove', $data_ary['forum_id']))
	{
		// Post not approved, but in queue
		$post_visibility = ITEM_UNAPPROVED;
		switch ($post_mode)
		{
			case 'edit_first_post':
			case 'edit':
			case 'edit_last_post':
			case 'edit_topic':
				$post_visibility = ITEM_REAPPROVE;
			break;
		}
	}
	else if (isset($data_ary['post_visibility']) && $data_ary['post_visibility'] !== false)
	{
		$post_visibility = $data_ary['post_visibility'];
	}

	// MODs/Extensions are able to force any visibility on posts
	if (isset($data_ary['force_approved_state']))
	{
		$post_visibility = (in_array((int) $data_ary['force_approved_state'], array(ITEM_APPROVED, ITEM_UNAPPROVED, ITEM_DELETED, ITEM_REAPPROVE))) ? (int) $data_ary['force_approved_state'] : $post_visibility;
	}
	if (isset($data_ary['force_visibility']))
	{
		$post_visibility = (in_array((int) $data_ary['force_visibility'], array(ITEM_APPROVED, ITEM_UNAPPROVED, ITEM_DELETED, ITEM_REAPPROVE))) ? (int) $data_ary['force_visibility'] : $post_visibility;
	}

	// Start the transaction here
	$db->sql_transaction('begin');

	// Collect Information
	switch ($post_mode)
	{
		case 'post':
		case 'reply':
			$sql_data[POSTS_TABLE]['sql'] = array(
				'post_id'				=> $data_ary['post_id'],
				'forum_id'			=> $data_ary['forum_id'],
				'poster_id'			=> $data_ary['poster_id'],
				'icon_id'			=> $data_ary['icon_id'],
				'poster_ip'			=> $data_ary['poster_ip'],
				'post_time'			=> $current_time,
				'post_visibility'	=> $post_visibility,
				'enable_bbcode'		=> $data_ary['enable_bbcode'],
				'enable_smilies'	=> $data_ary['enable_smilies'],
				'enable_magic_url'	=> $data_ary['enable_urls'],
				'enable_sig'		=> $data_ary['enable_sig'],
				'post_username'		=> $username,
				'post_subject'		=> $subject,
				'post_text'			=> $data_ary['message'],
				'post_checksum'		=> $data_ary['message_md5'],
				'post_attachment'	=> (!empty($data_ary['attachment_data'])) ? 1 : 0,
				'bbcode_bitfield'	=> $data_ary['bbcode_bitfield'],
				'bbcode_uid'		=> $data_ary['bbcode_uid'],
				'post_postcount'	=> ($auth->acl_get('f_postcount', $data_ary['forum_id'])) ? 1 : 0,
				'post_edit_locked'	=> $data_ary['post_edit_locked']
			);
		break;

	}

	// And the topic ladies and gentlemen
	switch ($post_mode)
	{
		case 'post':
			$sql_data[TOPICS_TABLE]['sql'] = array(
				'topic_id'					=> $data_ary['topic_id'],
				'topic_poster'				=> (int) $data_ary['poster_id'],
				'topic_time'				=> $current_time,
				'topic_last_view_time'		=> $current_time,
				'forum_id'					=> $data_ary['forum_id'],
				'icon_id'					=> $data_ary['icon_id'],
				'topic_posts_approved'		=> ($post_visibility == ITEM_APPROVED) ? 1 : 0,
				'topic_posts_softdeleted'	=> ($post_visibility == ITEM_DELETED) ? 1 : 0,
				'topic_posts_unapproved'	=> ($post_visibility == ITEM_UNAPPROVED) ? 1 : 0,
				'topic_visibility'			=> $post_visibility,
				'topic_delete_user'			=> ($post_visibility != ITEM_APPROVED) ? (int) $data_ary['poster_id'] : 0,
				'topic_title'				=> $subject,
				'topic_first_poster_name'	=> $username,
				'topic_first_poster_colour'	=> $data_ary['user_colour'],
				'topic_type'				=> $topic_type,
				'topic_time_limit'			=> $topic_type != POST_NORMAL ? ($data_ary['topic_time_limit'] * 86400) : 0,
				'topic_attachment'			=> (!empty($data_ary['attachment_data'])) ? 1 : 0,
				'topic_status'				=> (isset($data_ary['topic_status'])) ? $data_ary['topic_status'] : ITEM_UNLOCKED,
				'topic_views'					=> $data_ary['topic_views'],
			);

			if (isset($poll_ary['poll_options']) && !empty($poll_ary['poll_options']))
			{
				$poll_start = ($poll_ary['poll_start']) ? $poll_ary['poll_start'] : $current_time;
				$poll_length = $poll_ary['poll_length'] * 86400;
				if ($poll_length < 0)
				{
					$poll_start = $poll_start + $poll_length;
					if ($poll_start < 0)
					{
						$poll_start = 0;
					}
					$poll_length = 1;
				}

				$sql_data[TOPICS_TABLE]['sql'] = array_merge($sql_data[TOPICS_TABLE]['sql'], array(
					'poll_title'		=> $poll_ary['poll_title'],
					'poll_start'		=> $poll_start,
					'poll_max_options'	=> $poll_ary['poll_max_options'],
					'poll_length'		=> $poll_length,
					'poll_vote_change'	=> $poll_ary['poll_vote_change'])
				);
			}

			$sql_data[USERS_TABLE]['stat'][] = "user_lastpost_time = $current_time" . (($auth->acl_get('f_postcount', $data_ary['forum_id']) && $post_visibility == ITEM_APPROVED) ? ', user_posts = user_posts + 1' : '');

			if ($post_visibility == ITEM_APPROVED)
			{
				$sql_data[FORUMS_TABLE]['stat'][] = 'forum_topics_approved = forum_topics_approved + 1';
				$sql_data[FORUMS_TABLE]['stat'][] = 'forum_posts_approved = forum_posts_approved + 1';
			}
			else if ($post_visibility == ITEM_UNAPPROVED)
			{
				$sql_data[FORUMS_TABLE]['stat'][] = 'forum_topics_unapproved = forum_topics_unapproved + 1';
				$sql_data[FORUMS_TABLE]['stat'][] = 'forum_posts_unapproved = forum_posts_unapproved + 1';
			}
			else if ($post_visibility == ITEM_DELETED)
			{
				$sql_data[FORUMS_TABLE]['stat'][] = 'forum_topics_softdeleted = forum_topics_softdeleted + 1';
				$sql_data[FORUMS_TABLE]['stat'][] = 'forum_posts_softdeleted = forum_posts_softdeleted + 1';
			}
		break;

		case 'reply':
			$sql_data[TOPICS_TABLE]['stat'][] = 'topic_last_view_time = ' . $current_time . ',
				topic_bumped = 0,
				topic_bumper = 0' .
				(($post_visibility == ITEM_APPROVED) ? ', topic_posts_approved = topic_posts_approved + 1' : '') .
				(($post_visibility == ITEM_UNAPPROVED) ? ', topic_posts_unapproved = topic_posts_unapproved + 1' : '') .
				(($post_visibility == ITEM_DELETED) ? ', topic_posts_softdeleted = topic_posts_softdeleted + 1' : '') .
				((!empty($data_ary['attachment_data']) || (isset($data_ary['topic_attachment']) && $data_ary['topic_attachment'])) ? ', topic_attachment = 1' : '');

			$sql_data[USERS_TABLE]['stat'][] = "user_lastpost_time = $current_time" . (($auth->acl_get('f_postcount', $data_ary['forum_id']) && $post_visibility == ITEM_APPROVED) ? ', user_posts = user_posts + 1' : '');

			if ($post_visibility == ITEM_APPROVED)
			{
				$sql_data[FORUMS_TABLE]['stat'][] = 'forum_posts_approved = forum_posts_approved + 1';
			}
			else if ($post_visibility == ITEM_UNAPPROVED)
			{
				$sql_data[FORUMS_TABLE]['stat'][] = 'forum_posts_unapproved = forum_posts_unapproved + 1';
			}
			else if ($post_visibility == ITEM_DELETED)
			{
				$sql_data[FORUMS_TABLE]['stat'][] = 'forum_posts_softdeleted = forum_posts_softdeleted + 1';
			}
		break;

		case 'edit_topic':
		case 'edit_first_post':
			if (isset($poll_ary['poll_options']))
			{
				$poll_start = ($poll_ary['poll_start'] || empty($poll_ary['poll_options'])) ? $poll_ary['poll_start'] : $current_time;
				$poll_length = $poll_ary['poll_length'] * 86400;
				if ($poll_length < 0)
				{
					$poll_start = $poll_start + $poll_length;
					if ($poll_start < 0)
					{
						$poll_start = 0;
					}
					$poll_length = 1;
				}
			}

			$sql_data[TOPICS_TABLE]['sql'] = array(
				'topic_id'					=> $data_ary['topic_id'],
				'forum_id'					=> $data_ary['forum_id'],
				'icon_id'					=> $data_ary['icon_id'],
				'topic_title'				=> $subject,
				'topic_first_poster_name'	=> $username,
				'topic_type'				=> $topic_type,
				'topic_time_limit'			=> $topic_type != POST_NORMAL ? ($data_ary['topic_time_limit'] * 86400) : 0,
				'poll_title'				=> (isset($poll_ary['poll_options'])) ? $poll_ary['poll_title'] : '',
				'poll_start'				=> (isset($poll_ary['poll_options'])) ? $poll_start : 0,
				'poll_max_options'			=> (isset($poll_ary['poll_options'])) ? $poll_ary['poll_max_options'] : 1,
				'poll_length'				=> (isset($poll_ary['poll_options'])) ? $poll_length : 0,
				'poll_vote_change'			=> (isset($poll_ary['poll_vote_change'])) ? $poll_ary['poll_vote_change'] : 0,
				'topic_last_view_time'		=> $current_time,

				'topic_attachment'			=> (!empty($data_ary['attachment_data'])) ? 1 : (isset($data_ary['topic_attachment']) ? $data_ary['topic_attachment'] : 0),
			);

		break;
	}

	$poll = $poll_ary;
	$data = $data_ary;
	/**
	* Modify sql query data for post submitting
	*
	* @event core.submit_post_modify_sql_data
	* @var	array	data				Array with the data for the post
	* @var	array	poll				Array with the poll data for the post
	* @var	string	post_mode			Variable containing posting mode value
	* @var	bool	sql_data			Array with the data for the posting SQL query
	* @var	string	subject				Variable containing post subject value
	* @var	int		topic_type			Variable containing topic type value
	* @var	string	username			Variable containing post author name
	* @since 3.1.3-RC1
	*/
	$vars = array(
		'data',
		'poll',
		'post_mode',
		'sql_data',
		'subject',
		'topic_type',
		'username',
	);
	extract($phpbb_dispatcher->trigger_event('core.submit_post_modify_sql_data', compact($vars)));
	$poll_ary = $poll;
	$data_ary = $data;
	unset($poll);
	unset($data);

	// Submit new topic
	if ($post_mode == 'post')
	{
		$sql = 'INSERT INTO ' . TOPICS_TABLE . ' ' .
			$db->sql_build_array('INSERT', $sql_data[TOPICS_TABLE]['sql']);
		$db->sql_query($sql);

		$data_ary['topic_id'] = $db->sql_nextid();

		$sql_data[POSTS_TABLE]['sql'] = array_merge($sql_data[POSTS_TABLE]['sql'], array(
			'topic_id' => $data_ary['topic_id'])
		);
		unset($sql_data[TOPICS_TABLE]['sql']);
	}

	// Submit new post
	if ($post_mode == 'post' || $post_mode == 'reply')
	{
		if ($post_mode == 'reply')
		{
			$sql_data[POSTS_TABLE]['sql'] = array_merge($sql_data[POSTS_TABLE]['sql'], array(
				'topic_id' => $data_ary['topic_id'],
			));
		}

		$sql = 'INSERT INTO ' . POSTS_TABLE . ' ' . $db->sql_build_array('INSERT', $sql_data[POSTS_TABLE]['sql']);
		$db->sql_query($sql);
		$data_ary['post_id'] = $db->sql_nextid();

		if ($post_mode == 'post' || $post_visibility == ITEM_APPROVED)
		{
			$sql_data[TOPICS_TABLE]['sql'] = array(
				'topic_last_post_id'		=> $data_ary['post_id'],
				'topic_last_post_time'		=> $current_time,
				'topic_last_poster_id'		=> $sql_data[POSTS_TABLE]['sql']['poster_id'],
				'topic_last_poster_name'	=> $username,
				'topic_last_poster_colour'	=> $data_ary['user_colour'],
				'topic_last_post_subject'	=> (string) $subject,
			);
		}

		if ($post_mode == 'post')
		{
			$sql_data[TOPICS_TABLE]['sql']['topic_first_post_id'] = $data_ary['post_id'];
		}

		// Update total post count and forum information
		if ($post_visibility == ITEM_APPROVED)
		{
			if ($post_mode == 'post')
			{
				$config->increment('num_topics', 1, false);
			}
			$config->increment('num_posts', 1, false);

			$sql_data[FORUMS_TABLE]['stat'][] = 'forum_last_post_id = ' . $data_ary['post_id'];
			$sql_data[FORUMS_TABLE]['stat'][] = "forum_last_post_subject = '" . $db->sql_escape($subject) . "'";
			$sql_data[FORUMS_TABLE]['stat'][] = 'forum_last_post_time = ' . $current_time;
			$sql_data[FORUMS_TABLE]['stat'][] = 'forum_last_poster_id = ' . (int) $data_ary['poster_id'];
			$sql_data[FORUMS_TABLE]['stat'][] = "forum_last_poster_name = '" . $db->sql_escape($username) . "'";
			$sql_data[FORUMS_TABLE]['stat'][] = "forum_last_poster_colour = '" . $data_ary['user_color'] . "'";
		}

		unset($sql_data[POSTS_TABLE]['sql']);
	}

	// Update the topics table
	if (isset($sql_data[TOPICS_TABLE]['sql']))
	{
		$sql = 'UPDATE ' . TOPICS_TABLE . '
			SET ' . $db->sql_build_array('UPDATE', $sql_data[TOPICS_TABLE]['sql']) . '
			WHERE topic_id = ' . $data_ary['topic_id'];
		$db->sql_query($sql);

		unset($sql_data[TOPICS_TABLE]['sql']);
	}

	// Update the posts table
	if (isset($sql_data[POSTS_TABLE]['sql']))
	{
		$sql = 'UPDATE ' . POSTS_TABLE . '
			SET ' . $db->sql_build_array('UPDATE', $sql_data[POSTS_TABLE]['sql']) . '
			WHERE post_id = ' . $data_ary['post_id'];
		$db->sql_query($sql);

		unset($sql_data[POSTS_TABLE]['sql']);
	}

	// Update Poll Tables
	if (isset($poll_ary['poll_options']))
	{
		$cur_poll_options = array();

		if ($mode == 'edit')
		{
			$sql = 'SELECT *
				FROM ' . POLL_OPTIONS_TABLE . '
				WHERE topic_id = ' . $data_ary['topic_id'] . '
				ORDER BY poll_option_id';
			$result = $db->sql_query($sql);

			$cur_poll_options = array();
			while ($row = $db->sql_fetchrow($result))
			{
				$cur_poll_options[] = $row;
			}
			$db->sql_freeresult($result);
		}

		$sql_insert_ary = array();

		for ($i = 0, $size = count($poll_ary['poll_options']); $i < $size; $i++)
		{
			if (strlen(trim($poll_ary['poll_options'][$i])))
			{
				if (empty($cur_poll_options[$i]))
				{
					// If we add options we need to put them to the end to be able to preserve votes...
					$sql_insert_ary[] = array(
						'poll_option_id'	=> (int) count($cur_poll_options) + 1 + count($sql_insert_ary),
						'topic_id'			=> (int) $data_ary['topic_id'],
						'poll_option_text'	=> (string) $poll_ary['poll_options'][$i],
						'poll_option_total'	=> (string) $poll_ary['poll_votes'][$i]
					);
				}
				else if ($poll_ary['poll_options'][$i] != $cur_poll_options[$i])
				{
					$sql = 'UPDATE ' . POLL_OPTIONS_TABLE . "
						SET poll_option_text = '" . $db->sql_escape($poll_ary['poll_options'][$i]) . "'
						WHERE poll_option_id = " . $cur_poll_options[$i]['poll_option_id'] . '
							AND topic_id = ' . $data_ary['topic_id'];
					$db->sql_query($sql);
				}
			}
		}

		$db->sql_multi_insert(POLL_OPTIONS_TABLE, $sql_insert_ary);

		if (count($poll_ary['poll_options']) < count($cur_poll_options))
		{
			$sql = 'DELETE FROM ' . POLL_OPTIONS_TABLE . '
				WHERE poll_option_id > ' . count($poll_ary['poll_options']) . '
					AND topic_id = ' . $data_ary['topic_id'];
			$db->sql_query($sql);
		}

		// If edited, we would need to reset votes (since options can be re-ordered above, you can't be sure if the change is for changing the text or adding an option
		if ($mode == 'edit' && count($poll_ary['poll_options']) != count($cur_poll_options))
		{
			$db->sql_query('DELETE FROM ' . POLL_VOTES_TABLE . ' WHERE topic_id = ' . $data_ary['topic_id']);
			$db->sql_query('UPDATE ' . POLL_OPTIONS_TABLE . ' SET poll_option_total = 0 WHERE topic_id = ' . $data_ary['topic_id']);
		}
	}

	// Submit Attachments
	if (!empty($data_ary['attachment_data']) && $data_ary['post_id'] && in_array($mode, array('post', 'reply', 'quote', 'edit')))
	{
		$space_taken = $files_added = 0;
		$orphan_rows = array();

		foreach ($data_ary['attachment_data'] as $pos => $attach_row)
		{
			$orphan_rows[(int) $attach_row['attach_id']] = array();
		}

		if (count($orphan_rows))
		{
			$sql = 'SELECT attach_id, filesize, physical_filename
				FROM ' . ATTACHMENTS_TABLE . '
				WHERE ' . $db->sql_in_set('attach_id', array_keys($orphan_rows)) . '
					AND is_orphan = 1
					AND poster_id = ' . $data_ary['poster_id'];
			$result = $db->sql_query($sql);

			$orphan_rows = array();
			while ($row = $db->sql_fetchrow($result))
			{
				$orphan_rows[$row['attach_id']] = $row;
			}
			$db->sql_freeresult($result);
		}

		foreach ($data_ary['attachment_data'] as $pos => $attach_row)
		{
			if ($attach_row['is_orphan'] && !isset($orphan_rows[$attach_row['attach_id']]))
			{
				continue;
			}

			if (!$attach_row['is_orphan'])
			{
				// update entry in db if attachment already stored in db and filespace
				$sql = 'UPDATE ' . ATTACHMENTS_TABLE . "
					SET attach_comment = '" . $db->sql_escape($attach_row['attach_comment']) . "'
					WHERE attach_id = " . (int) $attach_row['attach_id'] . '
						AND is_orphan = 0';
				$db->sql_query($sql);
			}
			else
			{
				// insert attachment into db
				if (!@file_exists($phpbb_root_path . $config['upload_path'] . '/' . utf8_basename($orphan_rows[$attach_row['attach_id']]['physical_filename'])))
				{
					continue;
				}

				$space_taken += $orphan_rows[$attach_row['attach_id']]['filesize'];
				$files_added++;

				$attach_sql = array(
					'post_msg_id'		=> $data_ary['post_id'],
					'topic_id'			=> $data_ary['topic_id'],
					'is_orphan'			=> 0,
					'poster_id'			=> $poster_id,
					'attach_comment'	=> $attach_row['attach_comment'],
				);

				$sql = 'UPDATE ' . ATTACHMENTS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', $attach_sql) . '
					WHERE attach_id = ' . $attach_row['attach_id'] . '
						AND is_orphan = 1
						AND poster_id = ' . $data_ary['poster_id'];
				$db->sql_query($sql);
			}
		}

		if ($space_taken && $files_added)
		{
			$config->increment('upload_dir_size', $space_taken, false);
			$config->increment('num_files', $files_added, false);
		}
	}

	$first_post_has_topic_info = ($post_mode == 'edit_first_post' &&
			(($post_visibility == ITEM_DELETED && $data_ary['topic_posts_softdeleted'] == 1) ||
			($post_visibility == ITEM_UNAPPROVED && $data_ary['topic_posts_unapproved'] == 1) ||
			($post_visibility == ITEM_REAPPROVE && $data_ary['topic_posts_unapproved'] == 1) ||
			($post_visibility == ITEM_APPROVED && $data_ary['topic_posts_approved'] == 1)));
	// Fix the post's and topic's visibility and first/last post information, when the post is edited
	if (($post_mode != 'post' && $post_mode != 'reply') && $data_ary['post_visibility'] != $post_visibility)
	{
		// If the post was not approved, it could also be the starter,
		// so we sync the starter after approving/restoring, to ensure that the stats are correct
		// Same applies for the last post
		$is_starter = ($post_mode == 'edit_first_post' || $post_mode == 'edit_topic' || $data_ary['post_visibility'] != ITEM_APPROVED);
		$is_latest = ($post_mode == 'edit_last_post' || $post_mode == 'edit_topic' || $data_ary['post_visibility'] != ITEM_APPROVED);

		/* @var $phpbb_content_visibility \phpbb\content_visibility */
		$phpbb_content_visibility = $phpbb_container->get('content.visibility');
		$phpbb_content_visibility->set_post_visibility($post_visibility, $data_ary['post_id'], $data_ary['topic_id'], $data_ary['forum_id'], $data_ary['poster_id'], time(), '', $is_starter, $is_latest);
	}
	else if ($post_mode == 'edit_last_post' || $post_mode == 'edit_topic' || $first_post_has_topic_info)
	{
		if ($post_visibility == ITEM_APPROVED || $data_ary['topic_visibility'] == $post_visibility)
		{
			// only the subject can be changed from edit
			$sql_data[TOPICS_TABLE]['stat'][] = "topic_last_post_subject = '" . $db->sql_escape($subject) . "'";

			// Maybe not only the subject, but also changing anonymous usernames. ;)
			if ($data_ary['poster_id'] == ANONYMOUS)
			{
				$sql_data[TOPICS_TABLE]['stat'][] = "topic_last_poster_name = '" . $db->sql_escape($username) . "'";
			}

			if ($post_visibility == ITEM_APPROVED)
			{
				// this does not _necessarily_ mean that we must update the info again,
				// it just means that we might have to
				$sql = 'SELECT forum_last_post_id, forum_last_post_subject
					FROM ' . FORUMS_TABLE . '
					WHERE forum_id = ' . (int) $data_ary['forum_id'];
				$result = $db->sql_query($sql);
				$row = $db->sql_fetchrow($result);
				$db->sql_freeresult($result);

				// this post is the latest post in the forum, better update
				if ($row['forum_last_post_id'] == $data_ary['post_id'] && ($row['forum_last_post_subject'] !== $subject || $data_ary['poster_id'] == ANONYMOUS))
				{
					// the post's subject changed
					if ($row['forum_last_post_subject'] !== $subject)
					{
						$sql_data[FORUMS_TABLE]['stat'][] = "forum_last_post_subject = '" . $db->sql_escape($subject) . "'";
					}

					// Update the user name if poster is anonymous... just in case a moderator changed it
					if ($data_ary['poster_id'] == ANONYMOUS)
					{
						$sql_data[FORUMS_TABLE]['stat'][] = "forum_last_poster_name = '" . $db->sql_escape($username) . "'";
					}
				}
			}
		}
	}

	// Update forum stats
	$where_sql = array(
		POSTS_TABLE		=> 'post_id = ' . $data_ary['post_id'],
		TOPICS_TABLE	=> 'topic_id = ' . $data_ary['topic_id'],
		FORUMS_TABLE	=> 'forum_id = ' . $data_ary['forum_id'],
		USERS_TABLE		=> 'user_id = ' . $poster_id
	);

	foreach ($sql_data as $table => $update_ary)
	{
		if (isset($update_ary['stat']) && implode('', $update_ary['stat']))
		{
			$sql = "UPDATE $table SET " . implode(', ', $update_ary['stat']) . ' WHERE ' . $where_sql[$table];
			$db->sql_query($sql);
		}
	}

	// Delete topic shadows (if any exist). We do not need a shadow topic for an global announcement
	if ($topic_type == POST_GLOBAL)
	{
		$sql = 'DELETE FROM ' . TOPICS_TABLE . '
			WHERE topic_moved_id = ' . $data_ary['topic_id'];
		$db->sql_query($sql);
	}

	// Committing the transaction before updating search index
	$db->sql_transaction('commit');

	// Delete draft if post was loaded...
	$draft_id = $request->variable('draft_loaded', 0);
	if ($draft_id)
	{
		$sql = 'DELETE FROM ' . DRAFTS_TABLE . "
			WHERE draft_id = $draft_id
				AND user_id = {$data_ary['poster_id']}";
		$db->sql_query($sql);
	}

	// Index message contents
	if ($update_search_index && $data_ary['enable_indexing'])
	{
		// Select the search method and do some additional checks to ensure it can actually be utilised
		$search_type = $config['search_type'];

		if (!class_exists($search_type))
		{
			trigger_error('NO_SUCH_SEARCH_MODULE');
		}

		$error = false;
		$search = new $search_type($error, $phpbb_root_path, $phpEx, $auth, $config, $db, False, $phpbb_dispatcher); //hmmmmmm

		if ($error)
		{
			trigger_error($error);
		}

		$search->index($mode, $data_ary['post_id'], $data_ary['message'], $subject, $poster_id, $data_ary['forum_id']);
	}

	// Topic Notification, do not change if moderator is changing other users posts...
	if ($data_ary['poster_id'] == $poster_id)
	{
		if (!$data_ary['notify_set'] && $data_ary['notify'])
		{
			$sql = 'INSERT INTO ' . TOPICS_WATCH_TABLE . ' (user_id, topic_id)
				VALUES (' . $data_ary['poster_id'] . ', ' . $data_ary['topic_id'] . ')';
			$db->sql_query($sql);
		}
		else if (($config['email_enable'] || $config['jab_enable']) && $data_ary['notify_set'] && !$data_ary['notify'])
		{
			$sql = 'DELETE FROM ' . TOPICS_WATCH_TABLE . '
				WHERE user_id = ' . $data_ary['poster_id'] . '
					AND topic_id = ' . $data_ary['topic_id'];
			$db->sql_query($sql);
		}
	}

	if ($mode == 'post' || $mode == 'reply' || $mode == 'quote')
	{
		// Mark this topic as posted to
		markread('post', $data_ary['forum_id'], $data_ary['topic_id']);
	}

	// Mark this topic as read
	// We do not use post_time here, this is intended (post_time can have a date in the past if editing a message)
	markread('topic', $data_ary['forum_id'], $data_ary['topic_id'], time());

	//
	if ($config['load_db_lastread'] && true)
	{
		$sql = 'SELECT mark_time
			FROM ' . FORUMS_TRACK_TABLE . '
			WHERE user_id = ' . $user->data['user_id'] . '
				AND forum_id = ' . $data_ary['forum_id'];
		$result = $db->sql_query($sql);
		$f_mark_time = (int) $db->sql_fetchfield('mark_time');
		$db->sql_freeresult($result);
	}
	else if ($config['load_anon_lastread'] || true)
	{
		$f_mark_time = false;
	}

	if (($config['load_db_lastread'] && true) || $config['load_anon_lastread'] || true)
	{
		// Update forum info
		$sql = 'SELECT forum_last_post_time
			FROM ' . FORUMS_TABLE . '
			WHERE forum_id = ' . $data_ary['forum_id'];
		$result = $db->sql_query($sql);
		$forum_last_post_time = (int) $db->sql_fetchfield('forum_last_post_time');
		$db->sql_freeresult($result);

		update_forum_tracking_info($data_ary['forum_id'], $forum_last_post_time, $f_mark_time, false);
	}

	// If a username was supplied or the poster is a guest, we will use the supplied username.
	// Doing it this way we can use "...post by guest-username..." in notifications when
	// "guest-username" is supplied or ommit the username if it is not.
	$username = ($username !== '' || !true) ? $username : $username;

	// Send Notifications
	$notification_data = array_merge($data_ary, array(
		'topic_title'		=> (isset($data_ary['topic_title'])) ? $data_ary['topic_title'] : $subject,
		'post_username'		=> $username,
		'poster_id'			=> $poster_id,
		'post_text'			=> $data_ary['message'],
		'post_time'			=> $current_time,
		'post_subject'		=> $subject,
	));

	/* @var $phpbb_notifications \phpbb\notification\manager */
	$phpbb_notifications = $phpbb_container->get('notification_manager');

	if ($post_visibility == ITEM_APPROVED)
	{
		switch ($mode)
		{
			case 'post':
				$phpbb_notifications->add_notifications(array(
					'notification.type.quote',
					'notification.type.topic',
				), $notification_data);
			break;

			case 'reply':
			case 'quote':
				$phpbb_notifications->add_notifications(array(
					'notification.type.quote',
					'notification.type.bookmark',
					'notification.type.post',
				), $notification_data);
			break;

			case 'edit_topic':
			case 'edit_first_post':
			case 'edit':
			case 'edit_last_post':
				$phpbb_notifications->update_notifications(array(
					'notification.type.quote',
					'notification.type.bookmark',
					'notification.type.topic',
					'notification.type.post',
				), $notification_data);
			break;
		}
	}
	else if ($post_visibility == ITEM_UNAPPROVED)
	{
		switch ($mode)
		{
			case 'post':
				$phpbb_notifications->add_notifications('notification.type.topic_in_queue', $notification_data);
			break;

			case 'reply':
			case 'quote':
				$phpbb_notifications->add_notifications('notification.type.post_in_queue', $notification_data);
			break;

			case 'edit_topic':
			case 'edit_first_post':
			case 'edit':
			case 'edit_last_post':
				// Nothing to do here
			break;
		}
	}
	else if ($post_visibility == ITEM_REAPPROVE)
	{
		switch ($mode)
		{
			case 'edit_topic':
			case 'edit_first_post':
				$phpbb_notifications->add_notifications('notification.type.topic_in_queue', $notification_data);

				// Delete the approve_post notification so we can notify the user again,
				// when his post got reapproved
				$phpbb_notifications->delete_notifications('notification.type.approve_post', $notification_data['post_id']);
			break;

			case 'edit':
			case 'edit_last_post':
				$phpbb_notifications->add_notifications('notification.type.post_in_queue', $notification_data);

				// Delete the approve_post notification so we can notify the user again,
				// when his post got reapproved
				$phpbb_notifications->delete_notifications('notification.type.approve_post', $notification_data['post_id']);
			break;

			case 'post':
			case 'reply':
			case 'quote':
				// Nothing to do here
			break;
		}
	}
	else if ($post_visibility == ITEM_DELETED)
	{
		switch ($mode)
		{
			case 'post':
			case 'reply':
			case 'quote':
			case 'edit_topic':
			case 'edit_first_post':
			case 'edit':
			case 'edit_last_post':
				// Nothing to do here
			break;
		}
	}

	$params = $add_anchor = '';

	if ($post_visibility == ITEM_APPROVED ||
		($auth->acl_get('m_softdelete', $data_ary['forum_id']) && $post_visibility == ITEM_DELETED) ||
		($auth->acl_get('m_approve', $data_ary['forum_id']) && in_array($post_visibility, array(ITEM_UNAPPROVED, ITEM_REAPPROVE))))
	{
		$params .= '&amp;t=' . $data_ary['topic_id'];

		if ($mode != 'post')
		{
			$params .= '&amp;p=' . $data_ary['post_id'];
			$add_anchor = '#p' . $data_ary['post_id'];
		}
	}
	else if ($mode != 'post' && $post_mode != 'edit_first_post' && $post_mode != 'edit_topic')
	{
		$params .= '&amp;t=' . $data_ary['topic_id'];
	}

	$url = (!$params) ? "{$phpbb_root_path}viewforum.$phpEx" : "{$phpbb_root_path}viewtopic.$phpEx";
	$url = append_sid($url, 'f=' . $data_ary['forum_id'] . $params) . $add_anchor;

	$poll = $poll_ary;
	$data = $data_ary;
	/**
	* This event is used for performing actions directly after a post or topic
	* has been submitted. When a new topic is posted, the topic ID is
	* available in the $data array.
	*
	* The only action that can be done by altering data made available to this
	* event is to modify the return URL ($url).
	*
	* @event core.submit_post_end
	* @var	string	mode				Variable containing posting mode value
	* @var	string	subject				Variable containing post subject value
	* @var	string	username			Variable containing post author name
	* @var	int		topic_type			Variable containing topic type value
	* @var	array	poll				Array with the poll data for the post
	* @var	array	data				Array with the data for the post
	* @var	int		post_visibility		Variable containing up to date post visibility
	* @var	bool	update_message		Flag indicating if the post will be updated
	* @var	bool	update_search_index	Flag indicating if the search index will be updated
	* @var	string	url					The "Return to topic" URL
	*
	* @since 3.1.0-a3
	* @changed 3.1.0-RC3 Added vars mode, subject, username, topic_type,
	*		poll, update_message, update_search_index
	*/
	$vars = array(
		'mode',
		'subject',
		'username',
		'topic_type',
		'poll',
		'data',
		'post_visibility',
		'update_message',
		'update_search_index',
		'url',
	);
	extract($phpbb_dispatcher->trigger_event('core.submit_post_end', compact($vars)));
	$data_ary = $data;
	$poll_ary = $poll;
	unset($data);
	unset($poll);

	return $url;
}




function import_post_handler($post) {

  global $db, $mapper, $zeta;

  // see if this post is the first (and therefore a topic needs to be created)
  $rows = $zeta->query("SELECT id FROM post WHERE topic=" . $post['topic'] . " ORDER BY id LIMIT 1;");
  $firstPostId = $rows->fetchArray()['id'];
  $type = "reply";
  if ($post['id'] == $firstPostId) {
    // this is the first post of a topic - create the topic
    $type = "post";
  }

  // get the phpbb topic ID that this post is in
  $rows = $zeta->query("SELECT * FROM topic WHERE id=" . $post['topic'] . " LIMIT 1;");
  $topic = $rows->fetchArray();
  $topic['forum'] = $mapper[$topic['forum']];

  // get the phpbb forum ID that this topic is in
  $rows = $zeta->query("SELECT name FROM forum WHERE id=" . $topic['forum'] . " LIMIT 1;");
  $forumName = $rows->fetchArray()['name'];

  $forumName = htmlspecialchars($forumName);



  // for deleted posts
  if ($post['member'] == '' || !$post['member']) {
    $post['member'] = 1; // phpbb has user 1 as special 'anonymous' guest poster
  }

	echo "    - Processing post " . $post['id'] . "\n";

  $poll_data = '';
  if ($type == "post" && $topic['poll']) {

    // special case - get the poll too
		$rows = $zeta->query("SELECT * FROM poll WHERE id=" . $topic['poll'] . " LIMIT 1;");
    $poll = $rows->fetchArray();

		// and now get the options
		$query = $zeta->query("SELECT * FROM option WHERE poll=" . $topic['poll'] . ";");

    // convert options to phpbb format
    $options_data = array();
    $options_votes = array();
    while($option = $query->fetchArray(SQLITE3_ASSOC) ) {
      array_push($options_data, $option['option']);
    	array_push($options_votes, $option['votes']);
    }

    // convert poll to phpbb format
    $poll_data = array(
        'poll_options'				=> $options_data,
    		'poll_votes'					=> $options_votes,
    		'poll_start'					=> $topic['date'],
    		'poll_length'					=> 0,
    		'poll_title'					=> $poll['question'],
    		'poll_max_options'		=> $poll['options'],
    		'poll_vote_change'		=> 0,
    );
  }

  // get poster name
  $posterName = 'Deleted User';
  if ($post['member'] != 1) {
    $rows = $zeta->query("SELECT name FROM member WHERE id=" . $post['member'] . " LIMIT 1;");
    $posterName = $rows->fetchArray()['name'];
  }

  // generate the post text
  $uid = $bitfield = $options = '';
  generate_text_for_storage($post['bbcode'], $uid, $bitfield, $options, true, true, true);

  // generate the post data
  $post_data = array(
      // topic stuff
      'forum_id'            => $topic['forum'],    // The forum ID in which the post will be placed. (int)
      'topic_id'            => $topic['id'],    // Post a new topic or in an existing one? Set to 0 to create a new one, if not, specify your topic ID here instead.
  		'topic_title'        => $topic['name'],
      'icon_id'            => false,    // The Icon ID in which the post will be displayed with on the viewforum, set to false for icon_id. (int)
  		'topic_views'				=> $topic['views'],

  		// post stuff
  		'post_id' => $post['id'],
  		'poster_id' => $post['member'],
  		'message'            => $post['bbcode'],        // Your text you wish to have submitted. It should pass through generate_text_for_storage() before this. (string)
  		'message_md5'    => md5($post['bbcode']),// The md5 hash of your message
  		'bbcode_bitfield'    => $bitfield,    // Value created from the generate_text_for_storage() function.
  		'bbcode_uid'        => $uid,        // Value created from the generate_text_for_storage() function.

      // post settings
      'enable_bbcode'    => true,    // Enable BBcode in this post. (bool)
      'enable_smilies'    => true,    // Enabe smilies in this post. (bool)
      'enable_urls'        => true,    // Enable self-parsing URL links in this post. (bool)
      'enable_sig'        => true,    // Enable the signature of the poster to be displayed in the post. (bool)
      'post_edit_locked'    => 0,        // Disallow post editing? 1 = Yes, 0 = No
  		'enable_indexing'    => false,        // Allow indexing the post? (bool)
      'force_approved_state'    => true, // Allow the post to be submitted without going into unapproved queue
      'force_visibility'            => true, // Allow the post to be submitted without going into unapproved queue, or make it be deleted
  		'user_colour' => 'black',
  		'poster_ip' => '127.0.0.1',

      // Email Notification Settings
      'notify_set'        => false,        // (bool)
      'notify'            => false,        // (bool)
      'post_time'         => $post['date'],        // Set a specific time, use 0 to let submit_post() take care of getting the proper time (int)
      'forum_name'        => '',        // For identifying the name of the forum in a notification email. (string)
  );

  // run the import
  import_post($type, $topic['name'], $posterName, POST_NORMAL, $poll_data, $post_data, true);
}









// get mapper data
$jsonmapper = file_get_contents("mapper.json");
$mapper = json_decode($jsonmapper, true);

//print_r($mapper);
//die();



// now add in topics
$zeta = new ZetaDB();
if(!$zeta) {
  echo $zeta->lastErrorMsg();
  die();
}


$sql = "SELECT * from post;";
$ret = $zeta->query($sql);

while($post = $ret->fetchArray(SQLITE3_ASSOC) ) {
  import_post_handler($post);
}






?>
