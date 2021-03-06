<?php

/**
* This file is part of the phpBB Forum extension package
* IUM (Inactive User Manager).
*
* @copyright (c) 2016 by Andreas Kourtidis
* @license   GNU General Public License, version 2 (GPL-2.0)
*
* For full copyright and license information, please see
* the CREDITS.txt file.
*/

namespace andreask\ium\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class listener implements EventSubscriberInterface
{

	protected $log;
	protected $config;
	protected $config_text;
	protected $auth;
	protected $user;
	protected $container;

	public function __construct(ContainerInterface $container, \phpbb\user $user, \phpbb\config\config $config, \phpbb\config\db_text $config_text, \phpbb\auth\auth $auth, \phpbb\log\log $log)
	{
		$this->container 	=	$container;
		$this->user 		=	$user;
		$this->config 		=	$config;
		$this->config_text	=	$config_text;
		$this->auth			=	$auth;
		$this->log			=	$log;
	}

	/**
	 * Subscribe to phpbb events and execute the appropriate scripts.
	 * @return array List of events to subsribe with their represented methods.
	 */
	static public function getSubscribedEvents()
	{
		return array(
			'core.login_box_redirect'				=>	'update_table_ium_reminder_counter',
			'core.delete_user_after'				=>	'update_table_ium_reminder_user_deleted',
			'core.user_add_after'					=>	'update_table_ium_new_user',
			'core.acp_users_display_overview'		=>	'add_remind_user_option',
			'core.acp_users_overview_run_quicktool'	=>	'remind_single_user',
			'core.add_log'							=>	'overright_log',
		);
	}

	/**
	 * Reset reminder counter if users has returned!
	 * @param  array $event user_id of user to reset the counter for.
	 * @return void
	 */
	public function update_table_ium_reminder_counter($event)
	{
		$update = $this->container->get('andreask.ium.classes.reminder');
		$update->reset_counter($this->user->data['user_id']);
	}

	/**
	 * Remove user from ium table when a user is deleted (by admin or by the ext)
	 * @param  array $event user_id(s)
	 * @return void
	 */
	public function update_table_ium_reminder_user_deleted($event)
	{
		$id = $event['user_ids'];

		$delete = $this->container->get('andreask.ium.classes.delete_user');
		$delete->clean_ium_table($id);

		// Example for debugging...
		// $this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'deletion mode ' . $mode, time());
		// $this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'deletion action ' . $action, time());
		// $this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'deleted user_id ' . $id[0], time());
	}

	/**
	 * If a user has registerd succesfuly add him to the table.
	 * @param  array $event user_id of the user to update in the table.
	 * @return void
	 */
	public function update_table_ium_new_user($event)
	{
		$user_id = $event['user_id'];
		$add = $this->container->get('andreask.ium.classes.reminder');
		$add->new_user($user_id);
	}

	/**
	 * Appends new option in quick tools on ACP user overview page.
	 * @param array $event 'option' => 'langugage'.
	 * @return void
	 */
	public function add_remind_user_option($event)
	{
		$user = $event['user_row'];
		$admin = $this->auth->acl_get_list($user['user_id'], 'a_');
		$admin = (!empty($admin[0]['a_'])) ? $admin[0]['a_'] : array();

		$mod = $this->auth->acl_get_list($user['user_id'], 'm_');
		$mod = (!empty($mod[0]['m_'])) ? $mod[0]['m_'] : array();

		$array_merge = array_unique(array_merge($admin, $mod));
		$ignored_groups = $this->config_text->get('andreask_ium_ignored_groups', 'empty');
		$ignored_groups = json_decode($ignored_groups);

		$ignore = $this->container->get('andreask.ium.classes.ignore_user');
		$group_ids = $ignore->get_groups($user['user_id']);

		if ($this->config['andreask_ium_enable'] && (empty($array_merge)) && (!array_intersect($group_ids, $ignored_groups)))
		{
			$option = $event['quick_tool_ary'];
			$option['andreask_ium_remind'] = 'ANDREASK_IUM_USERS_OVERVIEW_OPTION';
			$event['quick_tool_ary'] = $option;
		}
	}

	public function remind_single_user($event)
	{
		$action = $event['action'];
		$user[] = $event['user_row'];

		if ($action == 'andreask_ium_remind')
		{
			$remind = $this->container->get('andreask.ium.classes.reminder');
			$remind->set_single($user);
			$remind->send(1, true);
		}
	}

	public function overright_log($event)
	{
		$sql_ary =  $event['sql_ary'];
		// Make sure we are getting the correct log...
		$username = unserialize($sql_ary['log_data']);

		if (!empty($username))
		{
			$username = array_shift($username);
		}
		if ($sql_ary['log_operation'] == 'SENT_REMINDERS' && (is_int($username)) && $username <= 1)
		{
			unset($sql_ary['log_type']);
			$event['sql_ary'] = $sql_ary;
		}

		if ($sql_ary['log_operation'] == 'LOG_USER_USER_UPDATE')
		{
			// echo "<pre>";
			// var_dump($event['sql_ary']);
			// echo "<br/>";
			// var_dump($sql_ary);
			// echo "</pre>";
			$sql_ary['log_operation'] = 'SENT_REMINDER_TO';
			$event['sql_ary'] = $sql_ary;
		}
	}
}
