<?php
/**
*
* Auto Groups extension for the phpBB Forum Software package.
*
* @copyright (c) 2014 phpBB Limited <https://www.phpbb.com>
* @license GNU General Public License, version 2 (GPL-2.0)
*
*/

namespace phpbb\autogroups\conditions;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
* Auto Groups service class
*/
class manager
{
	/** @var array Array with auto group types */
	protected $autogroups_types;

	/** @var ContainerInterface */
	protected $phpbb_container;

	/** @var \phpbb\cache\driver\driver_interface */
	protected $cache;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\user */
	protected $user;

	/** @var string The database table the auto group rules are stored in */
	protected $autogroups_rules_table;

	/** @var string The database table the auto group types are stored in */
	protected $autogroups_types_table;

	/**
	* Constructor
	*
	* @param array                                $autogroups_types         Array with auto group types
	* @param ContainerInterface                   $phpbb_container          Service container interface
	* @param \phpbb\cache\driver\driver_interface $cache                    Cache driver interface
	* @param \phpbb\db\driver\driver_interface    $db                       Database object
	* @param \phpbb\user                          $user                     User object
	* @param string                               $autogroups_rules_table   Name of the table used to store auto group rules data
	* @param string                               $autogroups_types_table   Name of the table used to store auto group types data
	*
	* @return \phpbb\autogroups\conditions\manager
	* @access public
	*/
	public function __construct($autogroups_types, ContainerInterface $phpbb_container, \phpbb\cache\driver\driver_interface $cache, \phpbb\db\driver\driver_interface $db, \phpbb\user $user, $autogroups_rules_table, $autogroups_types_table)
	{
		$this->autogroups_types = $autogroups_types;
		$this->phpbb_container = $phpbb_container;
		$this->cache = $cache;
		$this->db = $db;
		$this->user = $user;
		$this->autogroups_rules_table = $autogroups_rules_table;
		$this->autogroups_types_table = $autogroups_types_table;
	}

	/**
	* Check auto groups conditions and execute them
	*
	* @return null
	* @access public
	*/
	public function check_conditions()
	{
		foreach ($this->autogroups_types as $autogroups_type => $data)
		{
			$this->check_condition($autogroups_type);
		}
	}

	/**
	* Check auto groups condition and execute it
	*
	* @param string     $type_name      Name of the condition
	* @param array      $options        Array of optional data
	*
	* @return null
	* @access public
	*/
	public function check_condition($type_name, $options = array())
	{
		$condition = $this->phpbb_container->get($type_name);

		$check_users = $condition->get_users_for_condition($options);

		$condition->check($check_users, $options);
	}

	/**
	* Add new condition type
	*
	* @param string     $autogroups_type_name      The name of the auto group type
	*
	* @return int The identifier of the new condition type
	* @access public
	*/
	public function add_autogroups_type($autogroups_type_name)
	{
		$sql = 'INSERT INTO ' . $this->autogroups_types_table . '
			' . $this->db->sql_build_array('INSERT', array('autogroups_type_name' => $this->db->sql_escape($autogroups_type_name)));
		$this->db->sql_query($sql);

		return (int) $this->db->sql_nextid();
	}

	/**
	* Purge all conditions of a certain type
	*
	* @param string     $autogroups_type_name      The name of the auto group type
	*
	* @return null
	* @access public
	*/
	public function purge_autogroups_type($autogroups_type_name)
	{
		try
		{
			$condtion_type_id = $this->get_autogroup_type_id($autogroups_type_name);

			$sql = 'DELETE FROM ' . $this->autogroups_rules_table . '
				WHERE autogroups_type_id = ' . (int) $condtion_type_id;
			$this->db->sql_query($sql);

			$sql = 'DELETE FROM ' . $this->autogroups_types_table . '
				WHERE autogroups_type_id = ' . (int) $condtion_type_id;
			$this->db->sql_query($sql);

			$this->cache->destroy('autogroups_type_ids');
		}
		catch (\phpbb\autogroups\exception\base $e)
		{
			// Continue
		}
	}

	/**
	* Get the condition type id from the name
	*
	* @param string     $autogroups_type_name      The name of the auto group type
	*
	* @return int The condition_type_id
	* @throws \phpbb\autogroups\exception\base
	*/
	public function get_autogroup_type_id($autogroups_type_name)
	{
		// Get cached auto groups ids if they exist
		$autogroups_type_ids = $this->cache->get('autogroups_type_ids');

		// Get auto groups ids from the db if no cache data exists, cache result
		if ($autogroups_type_ids === false)
		{
			$autogroups_type_ids = array();

			$sql = 'SELECT autogroups_type_id, autogroups_type_name
				FROM ' . $this->autogroups_types_table;
			$result = $this->db->sql_query($sql);
			while ($row = $this->db->sql_fetchrow($result))
			{
				$autogroups_type_ids[$row['autogroups_type_name']] = (int) $row['autogroups_type_id'];
			}
			$this->db->sql_freeresult($result);

			$this->cache->put('autogroups_type_ids', $autogroups_type_ids);
		}

		// Add auto group type name to db if it exists as service but not in db, cache result
		if (!isset($autogroups_type_ids[$autogroups_type_name]))
		{
			if (!isset($this->autogroups_types[$autogroups_type_name]))
			{
				throw new \phpbb\autogroups\exception\base(array($autogroups_type_name, $this->user->lang('AUTOGROUPS_TYPE_NOT_EXIST')));
			}

			$autogroups_type_ids[$autogroups_type_name] = $this->add_autogroups_type($autogroups_type_name);

			$this->cache->put('autogroups_type_ids', $autogroups_type_ids);
		}

		return $autogroups_type_ids[$autogroups_type_name];
	}

	/**
	* Get condition type ids (as an array)
	*
	* @return array Array of condition type ids
	* @access public
	*/
	public function get_autogroup_type_ids()
	{
		$autogroups_type_ids = array();

		foreach ($this->autogroups_types as $type_name => $data)
		{
			$autogroups_type_ids[$type_name] = $this->get_autogroup_type_id($type_name);
		}

		return $autogroups_type_ids;
	}
}
