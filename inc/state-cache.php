<?php
/* Osmium
 * Copyright (C) 2012, 2013 Romain "Artefact2" Dalmaso <artefact2@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Osmium\State;

/* System unique prefix for semaphore keys. Change this if you have
 * some other process using semaphores whose first SEM_PREFIX_LENGTH
 * bits are SEM_PREFIX. */
const SEM_PREFIX = 0xA6;

/* Number of bits to use of SEM_PREFIX. Increase this if you need to
 * use a longer prefix. */
const SEM_PREFIX_LENGTH = 8;

/** @internal */
$__osmium_cache_stack = array();

/** @internal */
$__osmium_cache_enabled = true;

/**
 * Override current cache setting. Nested calls behave correctly as
 * expected.
 *
 * @param $enable whether to enable or disable cache.
 */
function set_cache_enabled($enable = true) {
	global $__osmium_cache_stack;
	global $__osmium_cache_enabled;

	$__osmium_cache_stack[] = $__osmium_cache_enabled;
	$__osmium_cache_enabled = $enable;
}

/**
 * Undo the last override made by set_cache_enabled() and restore the
 * old setting. Supports nested calls.
 */
function pop_cache_enabled() {
	global $__osmium_cache_stack;
	global $__osmium_cache_enabled;

	$__osmium_cache_enabled = array_pop($__osmium_cache_stack);
}

/* NOTE: all the cache types below have their own distinct namespaces
 * for keys. */

/* --------------------- DISK CACHE --------------------- */
/* Disk cache is persistent and global (ie not isolated per
 * account). It is generally the slowest. */

/**
 * Get a cache variable previously set by put_cache().
 *
 * @param $default the value to return if $key is not found in the
 * cache.
 */
function get_cache($key, $default = null, $prefix = 'OsmiumCache_') {
	global $__osmium_cache_enabled;
	if(!$__osmium_cache_enabled) return $default;

	$f = \Osmium\CACHE_DIRECTORY.'/'.$prefix.hash('sha512', $key);
	if(file_exists($f)) {
		$mtime = filemtime($f);
		if($mtime === 0 || $mtime > time()) {
			return unserialize(file_get_contents($f));
		}
	}
	return $default;
}

/**
 * Store a cache variable. Cache variables are not account-bound.
 *
 * @param $expires if set to zero, the cache value must never expire
 * unless explicitely invalidated. If not, *try* to keep the value in
 * cache for $expires seconds.
 */
function put_cache($key, $value, $expires = 0, $prefix = 'OsmiumCache_') {
	global $__osmium_cache_enabled;
	if(!$__osmium_cache_enabled) return;

	if($expires > 0) $expires = time() + $expires;

	$f = \Osmium\CACHE_DIRECTORY.'/'.$prefix.hash('sha512', $key);
    return file_put_contents($f, serialize($value)) && touch($f, $expires);
}

/**
 * Delete a cached variable.
 */
function invalidate_cache($key, $prefix = 'OsmiumCache_') {
	global $__osmium_cache_enabled;
	if(!$__osmium_cache_enabled) return;

	$f = \Osmium\CACHE_DIRECTORY.'/'.$prefix.hash('sha512', $key);
	if(file_exists($f)) unlink($f);
}

/**
 * Get the expiration date of a cached key.
 *
 * @returns UNIX timestamp of the expiration date, or the special
 * value 0 if the key is cached and set to never expire.
 */
function get_expiration_date($key, $prefix = 'OsmiumCache_') {
	global $__osmium_cache_enabled;
	/* Zero is for cache that does not expires; when cache is
	 * disabled, this function should always indicate all the cache to
	 * be expired. */
	if(!$__osmium_cache_enabled) return 1;

	$f = \Osmium\CACHE_DIRECTORY.'/'.$prefix.hash('sha512', $key);
	return file_exists($f) ? filemtime($f) : 1;
}

/* --------------------- MEMORY CACHE --------------------- */
/* Memory cache is non-persistent and global. It is usually faster
 * than disk cache, but there is no guarantee that a stored item will
 * be retrievable later even if it has not expired yet.. */

if(function_exists('apc_store')) {
	/* Use APC-based memory cache */

	/** @see get_cache() */
	function get_cache_memory($key, $default = null, $prefix = '') {
		global $__osmium_cache_enabled;
		if(!$__osmium_cache_enabled) return;

		$v = apc_fetch('Osmium_'.$prefix.$key, $success);
		return $success ? $v : $default;
	}

	/** @see put_cache() */
	function put_cache_memory($key, $value, $expires = 0, $prefix = '') {
		global $__osmium_cache_enabled;
		if(!$__osmium_cache_enabled) return;

		return apc_store('Osmium_'.$prefix.$key, $value, $expires);
	}

	/** @see invalidate_cache() */
	function invalidate_cache_memory($key, $prefix = '') {
		global $__osmium_cache_enabled;
		if(!$__osmium_cache_enabled) return;

		return apc_delete('Osmium_'.$prefix.$key);
	}
} else {
	/* Use disk-based cache as a fallback */

	/** @see get_cache() */
	function get_cache_memory($key, $default = null, $prefix = '') {
		return get_cache($key, $default, 'MemoryCacheFB_'.$prefix);
	}

	/** @see put_cache() */
	function put_cache_memory($key, $value, $expires = 0, $prefix = '') {
		return put_cache($key, $value, $expires, 'MemoryCacheFB_'.$prefix);
	}

	/** @see invalidate_cache() */
	function invalidate_cache_memory($key, $prefix = '') {
		return invalidate_cache($key, 'MemoryCache_'.$prefix);
	}
}

/* --------------------- PERSISTENT MEMORY CACHE --------------------- */
/* Persistent memory cache is persistent and global, and should be
 * faster than disk-only cache in most cases. */

/** @see get_cache() */
function get_cache_memory_fb($key, $default = null, $prefix = '') {
	$v = get_cache_memory($key, null, $prefix);
	if($v === null) {
		$v = get_cache('MemoryFB_'.$key, null, $prefix);
		if($v === null) {
			return $default;
		}

		/* Re-put the value in the memory cache */
		$expires = get_expiration_date('MemoryFB_'.$key, $prefix);
		$time = time();
		if($expires == 0 || $expires > $time) {
			put_cache_memory($key, $v, ($expires == 0) ? $expires : ($expires - $time), $prefix);
		}
	}

	return $v;
}

/** @see put_cache() */
function put_cache_memory_fb($key, $value, $expires = 0, $prefix = '') {
	put_cache('MemoryFB_'.$key, $value, $expires, $prefix);
	put_cache_memory($key, $value, $expires, $prefix);
}

/** @see invalidate_cache() */
function invalidate_cache_memory_fb($key, $prefix = '') {
	invalidate_cache('MemoryFB_'.$key, $prefix);
	invalidate_cache_memory($key, $prefix);
}

/* --------------------- STATE --------------------- */
/* State is non-persistent (it will at least persist for the session
 * lifetime) and isolated (per user). Internally, it can be either
 * disk-based or memory-based, so large data should not be stored in
 * state variables. */

/**
 * Get a state variable previously set via put_state().
 *
 * @param $default the value to return if $key is not found.
 */
function get_state($key, $default = null) {
	if(isset($_SESSION['__osmium_state'][$key])) {
		return $_SESSION['__osmium_state'][$key];
	} else return $default;
}

/**
 * Store a state variable. State variables are account-bound but do
 * not persist between sessions.
 */
function put_state($key, $value) {
	if(!isset($_SESSION['__osmium_state']) || !is_array($_SESSION['__osmium_state'])) {
		$_SESSION['__osmium_state'] = array();
	}

	return $_SESSION['__osmium_state'][$key] = $value;
}

/* --------------------- SETTINGS --------------------- */
/* Settings are persistent and isolated. They are stored in the
 * database. Settings do not work for anonymous users. Each call to
 * get_setting() or put_setting() issues a database query, so use with
 * moderation. */

/**
 * Get a setting previously stored with put_setting().
 *
 * @param $default the value to return if $key is not found.
 */
function get_setting($key, $default = null) {
	if(!is_logged_in()) return $default;

	global $__osmium_state;
	$accountid = $__osmium_state['a']['accountid'];

	$k = \Osmium\Db\query_params('SELECT value FROM osmium.accountsettings WHERE accountid = $1 AND key = $2', array($accountid, $key));
	while($r = \Osmium\Db\fetch_row($k)) {
		$ret = $r[0];
	}

	return isset($ret) ? unserialize($ret) : $default;
}

/**
 * Store a setting. Settings are account-bound, database-stored and
 * persistent between sessions.
 */
function put_setting($key, $value) {
	if(!is_logged_in()) return;

	global $__osmium_state;
	$accountid = $__osmium_state['a']['accountid'];
	\Osmium\Db\query_params('DELETE FROM osmium.accountsettings WHERE accountid = $1 AND key = $2', array($accountid, $key));
	\Osmium\Db\query_params('INSERT INTO osmium.accountsettings (accountid, key, value) VALUES ($1, $2, $3)', array($accountid, $key, serialize($value)));

	return $value;
}

/* --------------------- (TRY) PERSISTENT STATE --------------------- */
/* (Try) Persistent state is just a pretty way of using settings for
 * logged-in users and regular state for anonymous users. Use with
 * moderation. */

/**
 * Get a state variable previously stored by put_state_trypersist().
 *
 * This is just a wrapper that uses settings for logged-in users, and
 * state vars for anonymous users.
 */
function get_state_trypersist($key, $default = null) {
	if(is_logged_in()) {
		return get_setting($key, $default);
	} else {
		return get_state('__setting_'.$key, $default);
	}
}

/**
 * Store a state variable, and try to persist changes as much as
 * possible.
 *
 * This is just a wrapper that uses settings for logged-in users, and
 * state vars for anonymous users.
 */
function put_state_trypersist($key, $value) {
	if(is_logged_in()) {
		/* For regular users, use database-stored persistent storage */
		return put_setting($key, $value);
	} else {
		/* For anonymous users, use session-based storage (not really persistent, but better than nothing) */
		return put_state('__setting_'.$key, $value);
	}
}

/* --------------------- SEMAPHORES --------------------- */
/* You can use semaphores to avoid cache slams (ie when a heavily used
 * cache entry expires, the cache will be generated simultaneously by
 * a lot of requests and waste resources). All the functions above
 * will not use semaphores automatically, you will have to do it when
 * necessary. */

if(function_exists('sem_acquire')) {
	/* Use the builtin semaphore functions */

	/**
     * Acquire a semaphore. This will block until the semaphore can be
     * acquired.
     *
     * @returns the semaphore resource to be given to
     * semaphore_release().
     */
	function semaphore_acquire($name) {
		$key = (SEM_PREFIX << (32 - SEM_PREFIX_LENGTH)) & (crc32(__FILE__.'/'.$name) >> SEM_PREFIX_LENGTH);
		$id = sem_get($key);
		if($id === false) return false;
		if(sem_acquire($id) === false) return false;
		return $id;
	}

	/**
	 * Release a semaphore. This is automatically done when the
	 * process terminates (but will generate a warning).
	 */
	function semaphore_release($semaphore) {
		sem_release($semaphore);
		sem_remove($semaphore);
	}
} else {
	/* Use flock() as a fallback */

	/** @see semaphore_acquire() */
	function semaphore_acquire($name) {
		$f = fopen($filename = \Osmium\CACHE_DIRECTORY.'/Semaphore_'.hash('sha512', $name), 'cb');
		touch($f, time() + 600); /* This semaphore will probably be stale in 10 minutes */
		if($f === false) return false;
		if(flock($f, LOCK_EX) === false) return false;
		return [ $f, $filename ];
	}

	/** @see semaphore_release() */
	function semaphore_release($semaphore) {
		flock($semaphore[0], LOCK_UN);
		/* unlink() will remove the file when it is no longer opened
		 * by any process. So if the file is already opened by other
		 * processes (aka other processes are waiting to acquire the
		 * semaphore), it will still work as expected. */
		unlink($semaphore[1]);
	}
}
