<?php
/**
 * Plugin Name: Redis Object Cache Drop-In
 * Description: A persistent object cache backend powered by Memcached.
 * Version: 1.0.0
 * Author: Alex Sancho
 * Author URI: https://github.com/alexsancho
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Upload this file to your WordPress site's /wp-content/ folder. Setup multiple Memcached backends by defining them in wp-config. Check the plugin repo on GitHub for further instructions.
 * Based on WP Memcached Object Cache by Fotis Alexandrou (using code from Scott Taylor, Ryan Boren, Denis de Bernardy, Matt Martz, Mike Schroder, Mika Epstein)
 * @see https://github.com/joomlaworks/wp-memcached-object-cache
 */

if ( ! defined('WP_CACHE_KEY_SALT')) {
	define('WP_CACHE_KEY_SALT', md5(__DIR__));
}

function wp_cache_add($key, $data, $group = '', $expire = 0)
{
	global $wp_object_cache;

	return $wp_object_cache->add($key, $data, $group, $expire);
}

function wp_cache_incr($key, $n = 1, $group = '')
{
	global $wp_object_cache;

	return $wp_object_cache->incr($key, $n, $group);
}

function wp_cache_decr($key, $n = 1, $group = '')
{
	global $wp_object_cache;

	return $wp_object_cache->decr($key, $n, $group);
}

function wp_cache_close()
{
	global $wp_object_cache;

	return $wp_object_cache->close();
}

function wp_cache_delete($key, $group = '')
{
	global $wp_object_cache;

	return $wp_object_cache->delete($key, $group);
}

function wp_cache_flush()
{
	global $wp_object_cache;

	return $wp_object_cache->flush();
}

function wp_cache_get($key, $group = '', $force = false, &$found = null)
{
	global $wp_object_cache;

	return $wp_object_cache->get($key, $group, $force, $found);
}

/**
 * $keys_and_groups = array(
 *      array( 'key', 'group' ),
 *      array( 'key', '' ),
 *      array( 'key', 'group' ),
 *      array( 'key' )
 * );
 *
 * @param        $key_and_groups
 * @param string $bucket
 *
 * @return array
 */
function wp_cache_get_multi($key_and_groups, $bucket = 'default')
{
	global $wp_object_cache;

	return $wp_object_cache->get_multi($key_and_groups, $bucket);
}

/**
 * $items = array(
 *      array( 'key', 'data', 'group' ),
 *      array( 'key', 'data' )
 * );
 *
 * @param        $items
 * @param int    $expire
 * @param string $group
 */
function wp_cache_set_multi($items, $expire = 0, $group = 'default')
{
	global $wp_object_cache;

	return $wp_object_cache->set_multi($items, $expire = 0, $group = 'default');
}

function wp_cache_init()
{
	global $wp_object_cache;

	// phpcs:disable
	$wp_object_cache = WP_Object_Cache::instance();
	// phpcs:enable
}

/**
 * @param        $key
 * @param        $data
 * @param string $group
 * @param int    $expire
 *
 * @return mixed
 */
function wp_cache_replace($key, $data, $group = '', $expire = 0)
{
	global $wp_object_cache;

	return $wp_object_cache->replace($key, $data, $group, $expire);
}

/**
 * @param        $key
 * @param        $data
 * @param string $group
 * @param int    $expire
 *
 * @return bool
 */
function wp_cache_set($key, $data, $group = '', $expire = 0)
{
	global $wp_object_cache;

	if (defined('WP_INSTALLING') === false) {
		return $wp_object_cache->set($key, $data, $group, $expire);
	}

	return $wp_object_cache->delete($key, $group);
}

/**
 * @param $groups
 */
function wp_cache_add_global_groups($groups)
{
	global $wp_object_cache;

	$wp_object_cache->add_global_groups($groups);
}

/**
 * @param $groups
 */
function wp_cache_add_non_persistent_groups($groups)
{
	global $wp_object_cache;

	$wp_object_cache->add_non_persistent_groups($groups);
}

class WP_Object_Cache
{
	public $global_prefix = '';
	public $blog_prefix = '';
	public $global_groups = [];
	public $no_mc_groups = [];
	public $cache = [];
	public $mc = [];
	public $stats = [
		'get'       => 0,
		'get_multi' => 0,
		'add'       => 0,
		'set'       => 0,
		'delete'    => 0,
		'miss'      => 0,
	];
	public $group_ops = [];
	public $debug = false;
	public $cache_enabled = true;
	public $default_expiration = 0;

	/**
	 * @param        $id
	 * @param        $data
	 * @param string $group
	 * @param int    $expire
	 *
	 * @return bool
	 */
	public function add($id, $data, $group = 'default', $expire = 0): bool
	{
		$key = $this->key($id, $group);

		if (is_object($data)) {
			$data = clone $data;
		}

		if (in_array($group, $this->no_mc_groups, false)) {
			$this->cache[$key] = $data;

			return true;
		}

		if (isset($this->cache[$key]) && $this->cache[$key] !== false) {
			return false;
		}

		$mc     = $this->get_mc($group);
		$expire = ($expire === 0) ? $this->default_expiration : $expire;
		$result = $mc->add($key, $data, $expire);

		if (false !== $result) {
			++$this->stats['add'];
			$this->group_ops[$group][] = "add $id";
			$this->cache[$key]         = $data;
		}

		return $result;
	}

	//	public function __destruct()
	//	{
	//		$this->close();
	//		foreach ($this->mc as $bucket => $mc) {
	//			$mc->resetServerList();
	//		}
	//	}

	/**
	 * @param $groups
	 */
	public function add_global_groups($groups): void
	{
		if ( ! is_array($groups)) {
			$groups = (array)$groups;
		}

		$this->global_groups = array_merge($this->global_groups, $groups);
		$this->global_groups = array_unique($this->global_groups);
	}

	/**
	 * @param $groups
	 */
	public function add_non_persistent_groups($groups): void
	{
		if ( ! is_array($groups)) {
			$groups = (array)$groups;
		}

		$this->no_mc_groups = array_merge($this->no_mc_groups, $groups);
		$this->no_mc_groups = array_unique($this->no_mc_groups);
	}

	/**
	 * @param        $id
	 * @param int    $n
	 * @param string $group
	 *
	 * @return mixed
	 */
	public function incr($id, $n = 1, $group = 'default')
	{
		$key = $this->key($id, $group);

		$this->cache[$key] = $this->get_mc($group)->increment($key, $n);

		return $this->cache[$key];
	}

	/**
	 * @param        $id
	 * @param int    $n
	 * @param string $group
	 *
	 * @return mixed
	 */
	public function decr($id, $n = 1, $group = 'default')
	{
		$key = $this->key($id, $group);

		$this->cache[$key] = $this->get_mc($group)->decrement($key, $n);

		return $this->cache[$key];
	}

	/**
	 * @return bool
	 */
	public function close(): bool
	{
		foreach ($this->mc as $bucket => $mc) {
			$mc->quit();
		}

		return true;
	}

	/**
	 * @param        $id
	 * @param string $group
	 *
	 * @return bool
	 */
	public function delete($id, $group = 'default'): bool
	{
		$key = $this->key($id, $group);
		if (in_array($group, $this->no_mc_groups, false)) {
			unset($this->cache[$key]);

			return true;
		}
		$mc     = $this->get_mc($group);
		$result = $mc->delete($key);
		if (false !== $result) {
			++$this->stats['delete'];
			$this->group_ops[$group][] = "delete $id";
			unset($this->cache[$key]);
		}

		return $result;
	}

	/**
	 * @return bool
	 */
	public function flush(): bool
	{
		// Don't flush if multi-blog.
		if (function_exists('is_site_admin') || (defined('CUSTOM_USER_TABLE') && defined('CUSTOM_USER_META_TABLE'))) {
			return true;
		}

		$ret = true;

		foreach (array_keys($this->mc) as $group) {
			$ret &= $this->mc[$group]->flush();
		}

		return $ret;
	}

	/**
	 * @param        $id
	 * @param string $group
	 * @param bool   $force
	 * @param null   $found
	 *
	 * @return bool|mixed
	 */
	public function get($id, $group = 'default', $force = false, &$found = null)
	{
		$key   = $this->key($id, $group);
		$mc    = $this->get_mc($group);
		$found = false;

		if (isset($this->cache[$key]) && ( ! $force || in_array($group, $this->no_mc_groups, false))) {
			$found = true;
			if (is_object($this->cache[$key])) {
				$value = clone $this->cache[$key];
			} else {
				$value = $this->cache[$key];
			}
		} elseif (in_array($group, $this->no_mc_groups, false)) {
			$this->cache[$key] = $value = false;
		} else {
			$value = $mc->get($key);

			if ($value === false || (is_int($value) && -1 === $value)) {
				// $value = false;
				$found = $mc->getResultCode() !== Memcached::RES_NOTFOUND;
			} else {
				$found = true;
			}

			$this->cache[$key] = $value;
		}

		if ($found) {
			++$this->stats['get'];
			$this->group_ops[$group][] = "get $id";
		} else {
			++$this->stats['miss'];
		}

		if ('checkthedatabaseplease' === $value) {
			unset($this->cache[$key]);
			$value = false;
		}

		return $value;
	}

	/**
	 * @param        $keys
	 * @param string $group
	 *
	 * @return array
	 */
	public function get_multi($keys, $group = 'default'): array
	{
		$return = [];
		$gets   = [];

		$mc = $this->get_mc($group);

		foreach ($keys as $i => $values) {
			$mc     = $this->get_mc($group);
			$values = (array)$values;

			if (empty($values[1])) {
				$values[1] = 'default';
			}

			[$id, $group] = $values;

			$key = $this->key($id, $group);

			if (isset($this->cache[$key])) {
				if (is_object($this->cache[$key])) {
					$return[$key] = clone $this->cache[$key];
				} else {
					$return[$key] = $this->cache[$key];
				}
			} elseif (in_array($group, $this->no_mc_groups, false)) {
				$return[$key] = false;
			} else {
				$gets[$key] = $key;
			}
		}

		if ( ! empty($gets)) {
			$results = $mc->getMulti($gets, Memcached::GET_PRESERVE_ORDER);
			$joined  = array_combine(array_keys($gets), $results);
			$return  = array_merge($return, $joined);
		}

		++$this->stats['get_multi'];

		$this->group_ops[$group][] = "get_multi $id";

		$this->cache = array_merge($this->cache, $return);

		return array_values($return);
	}

	/**
	 * @param $key
	 * @param $group
	 *
	 * @return string|string[]|null
	 */
	public function key($key, $group)
	{
		if (empty($group)) {
			$group = 'default';
		}

		if (in_array($group, $this->global_groups, false)) {
			$prefix = $this->global_prefix;
		} else {
			$prefix = $this->blog_prefix;
		}

		return preg_replace('/\s+/', '', WP_CACHE_KEY_SALT."$prefix$group:$key");
	}

	/**
	 * @param        $id
	 * @param        $data
	 * @param string $group
	 * @param int    $expire
	 *
	 * @return mixed
	 */
	public function replace($id, $data, $group = 'default', $expire = 0)
	{
		$key    = $this->key($id, $group);
		$expire = $expire === 0 ? $this->default_expiration : $expire;
		$mc     = $this->get_mc($group);

		if (is_object($data)) {
			$data = clone $data;
		}

		$result = $mc->replace($key, $data, $expire);

		if (false !== $result) {
			$this->cache[$key] = $data;
		}

		return $result;
	}

	/**
	 * @param        $id
	 * @param        $data
	 * @param string $group
	 * @param int    $expire
	 *
	 * @return bool
	 */
	public function set($id, $data, $group = 'default', $expire = 0): bool
	{
		$key = $this->key($id, $group);
		if (isset($this->cache[$key]) && ('checkthedatabaseplease' === $this->cache[$key])) {
			return false;
		}

		if (is_object($data)) {
			$data = clone $data;
		}

		$this->cache[$key] = $data;

		if (in_array($group, $this->no_mc_groups, false)) {
			return true;
		}

		$expire = $expire === 0 ? $this->default_expiration : $expire;

		return $this->get_mc($group)->set($key, $data, $expire);
	}

	/**
	 * @param        $items
	 * @param int    $expire
	 * @param string $group
	 */
	public function set_multi($items, $expire = 0, $group = 'default'): void
	{
		$sets   = [];
		$mc     = $this->get_mc($group);
		$expire = $expire === 0 ? $this->default_expiration : $expire;
		foreach ($items as $i => $item) {
			if (empty($item[2])) {
				$item[2] = 'default';
			}

			list($id, $data, $group) = $item;

			$key = $this->key($id, $group);
			if (isset($this->cache[$key]) && ('checkthedatabaseplease' === $this->cache[$key])) {
				continue;
			}

			if (is_object($data)) {
				$data = clone $data;
			}

			$this->cache[$key] = $data;
			if (in_array($group, $this->no_mc_groups, false)) {
				continue;
			}

			$sets[$key] = $data;
		}

		if ( ! empty($sets)) {
			$mc->setMulti($sets, $expire);
		}
	}

	/**
	 * @param $line
	 *
	 * @return string
	 */
	public function colorize_debug_line($line): string
	{
		$colors = [
			'get'    => 'green',
			'set'    => 'purple',
			'add'    => 'blue',
			'delete' => 'red',
		];

		$cmd  = substr($line, 0, strpos($line, ' '));
		$cmd2 = "<span style='color:{$colors[$cmd]}'>$cmd</span>";

		return $cmd2.substr($line, strlen($cmd))."\n";
	}

	public function stats(): string
	{
		$stats_text = "<p>\n";

		$stats = [];
		foreach ($this->stats as $stat => $n) {
			$stats[] = "<span><strong>$stat</strong> $n</span>";
		}

		$stats_text .= implode(' | ', $stats);
		$stats_text .= "</p>\n";

		foreach ($this->group_ops as $group => $ops) {
			if ( ! isset($_GET['debug_queries']) && 500 < count($ops)) {
				$ops        = array_slice($ops, 0, 500);
				$stats_text .= "<strong>Too many to show! <a href='".add_query_arg('debug_queries', 'true')."'>Show them anyway</a>.</strong>\n";
			}

			$stats_text .= "<h4>$group commands</h4>";
			$stats_text .= "<pre>\n";

			$lines = [];
			foreach ($ops as $op) {
				$lines[] = $this->colorize_debug_line($op);
			}

			$stats_text .= implode('', $lines);
			$stats_text .= "</pre>\n";
		}

		return $stats_text;
	}

	/**
	 * @param $group
	 *
	 * @return \Memcached
	 */
	public function get_mc($group): \Memcached
	{
		return $this->mc[$group] ?? $this->mc['default'];
	}

	/**
	 * @return WP_Object_Cache
	 */
	public static function instance(): WP_Object_Cache
	{
		static $instance;

		if ($instance === null) {
			$instance = new self();
		}

		return $instance;
	}

	private function __construct()
	{
		$this->setConnection();

		global $blog_id, $table_prefix;

		if (function_exists('is_multisite')) {
			$this->global_prefix = (is_multisite() || (defined('CUSTOM_USER_TABLE') && defined('CUSTOM_USER_META_TABLE'))) ? '' : $table_prefix;
			$this->blog_prefix   = (is_multisite() ? $blog_id : $table_prefix).':';
		}

		$this->cache['hits']   =& $this->stats['get'];
		$this->cache['misses'] =& $this->stats['add'];

		if ($this->debug) {
			add_action('wp_footer', [$this, 'stats']);
		}
	}

	private function setConnection(): void
	{
		$memcached_servers = function_exists('get_memcached_servers') ? get_memcached_servers() : null;

		$buckets = $memcached_servers ?? ['127.0.0.1'];

		reset($buckets);

		if (is_int(key($buckets))) {
			$buckets = ['default' => $buckets];
		}

		foreach ($buckets as $bucket => $servers) {
			$this->mc[$bucket] = new Memcached('wpcache');

			$instances = [];

			foreach ($servers as $server) {
				[$node, $port] = explode(':', $server);

				if (empty($port)) {
					$port = ini_get('memcache.default_port');
				}

				$port = (int)$port;

				if ( ! $port) {
					$port = 11211;
				}

				$instances[] = [$node, $port, 1];
			}

			$this->mc[$bucket]->addServers($instances);
		}
	}
}
