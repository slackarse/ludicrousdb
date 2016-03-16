<?php

/**
 * Plugin name: LudicrousDB
 * Author:      johnjamesjacoby
 * Plugin URI:  https://github.com/johnjamesjacoby/ludicrousdb
 * Description: An advanced database class that supports replication, failover, load balancing, and partitioning.
 * Version:     2.0.0
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit();

// The config file was defined earlier.
if ( defined( 'DB_CONFIG_FILE' ) && file_exists( DB_CONFIG_FILE ) ) {
	// Do nothing here

// The config file resides in ABSPATH.
} elseif ( file_exists( ABSPATH . 'db-config.php' ) ) {
	define( 'DB_CONFIG_FILE', ABSPATH . 'db-config.php' );

// The config file resides one level above ABSPATH but is not part of
// another install.
} elseif ( file_exists( dirname( ABSPATH ) . '/db-config.php' ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
	define( 'DB_CONFIG_FILE', dirname( ABSPATH ) . '/db-config.php' );

// Lacking a config file, revert to the standard database class.
} else {
	$wpdb = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	return;
}

/**
 * Add a database table
 *
 * @param  string $dataset
 * @param  string $table
 */
function ldb_add_db_table( $dataset, $table ) {
	$GLOBALS['wpdb']->add_table( $dataset, $table );
}

/**
 * This is back-compatible with an older config style. It is for convenience.
 * lhost, part, and dc were removed from LudicrousDB because the read and write
 * parameters provide enough power to achieve the desired effects via config.
 *
 * @param string $dataset  Datset:           the name of the dataset. Just use "global" if you don't need horizontal partitioning.
 * @param int    $part     Partition:        the vertical partition number (1, 2, 3, etc.). Use "0" if you don't need vertical partitioning.
 * @param string $dc       Datacenter:       where the database server is located. Airport codes are convenient. Use whatever.
 * @param int    $read     Read group:       tries all servers in lowest number group before trying higher number group. Typical: 1 for slaves, 2 for master. This will cause reads to go to slaves unless al$
 * @param bool   $write    Write flag:       is this server writable? Works the same as $read. Typical: 1 for master, 0 for slaves.
 * @param string $host     Internet address: host:port of server on internet.
 * @param string $lhost    Local address:    host:port of server for use when in same datacenter. Leave empty if no local address exists.
 * @param string $name     Database name.
 * @param string $user     Database user.
 * @param string $password Database password.
 */
function ldb_add_db_server( $dataset, $part, $dc, $read, $write, $host, $lhost, $name, $user, $password, $timeout = 0.2 ) {

	// dc is not used in LudicrousDB. This produces the desired effect of
	// trying to connect to local servers before remote servers. Also
	// increases time allowed for TCP responsiveness check.
	if ( ! empty( $dc ) && defined( DATACENTER ) && ( DATACENTER !== $dc ) ) {
		$read   += 10000;
		$write  += 10000;
		$timeout = 0.7;
	}

	if ( ! empty( $part ) ) {
		$dataset = $dataset . '_' . $part;
	}

	$database = compact( 'dataset', 'read', 'write', 'host', 'name', 'user', 'password', 'timeout' );

	$GLOBALS['wpdb']->add_database( $database );

	if ( defined( 'DATACENTER' ) && $dc === DATACENTER ) {
		// lhost is not used in LudicrousDB. This configures LudicrousDB with an
		// additional server to represent the local hostname so it tries to
		// connect over the private interface before the public one.
		if ( ! empty( $lhost ) ) {

			$database[ 'host' ] = $lhost;

			if ( ! empty( $read ) ) {
				$database[ 'read' ] = $read - 0.5;
			}

			if ( ! empty( $write ) ) {
				$database[ 'write' ] = $write - 0.5;
			}

			$GLOBALS['wpdb']->add_database( $database );
		}
	}
}

/**
 * Common definitions
 */
define( 'DB_LAG_OK',      1 );
define( 'DB_LAG_BEHIND',  2 );
define( 'DB_LAG_UNKNOWN', 3 );

class LudicrousDB extends wpdb {

	/**
	 * The last table that was queried
	 * @var string
	 */
	public $last_table;

	/**
	 * After any SQL_CALC_FOUND_ROWS query, the query "SELECT FOUND_ROWS()"
	 * is sent and the mysql result resource stored here. The next query
	 * for FOUND_ROWS() will retrieve this. We do this to prevent any
	 * intervening queries from making FOUND_ROWS() inaccessible. You may
	 * prevent this by adding "NO_SELECT_FOUND_ROWS" in a comment.
	 * @var resource
	 */
	public $last_found_rows_result;

	/**
	 * Whether to store queries in an array. Useful for debugging and profiling.
	 * @var bool
	 */
	public $save_queries = false;

	/**
	 * The current mysql link resource
	 * @var resource
	 */
	public $dbh;

	/**
	 * Associative array (dbhname => dbh) for established mysql connections
	 * @var array
	 */
	public $dbhs;

	/**
	 * The multi-dimensional array of datasets and servers
	 * @public array
	 */
	public $ludicrous_servers = array();

	/**
	 * Optional directory of tables and their datasets
	 * @public array
	 */
	public $ludicrous_tables = array();

	/**
	 * Optional directory of callbacks to determine datasets from queries
	 * @public array
	 */
	public $ludicrous_callbacks = array();

	/**
	 * Custom callback to save debug info in $this->queries
	 * @public callable
	 */
	public $save_query_callback = null;

	/**
	 * Whether to use mysql_pconnect instead of mysql_connect
	 * @public bool
	 */
	public $persistent = false;

	/**
	 * The maximum number of db links to keep open. The least-recently used
	 * link will be closed when the number of links exceeds this.
	 * @public int
	 */
	public $max_connections = 10;

	/**
	 * Whether to check with fsockopen prior to mysql_connect.
	 * @public bool
	 */
	public $check_tcp_responsiveness = true;

	/**
	 * Minimum number of connections to try before bailing
	 * @public int
	 */
	public $min_tries = 3;


	/**
	 * The number of times to retry reconnecting before dying.
	 * Added for backwards compat.
	 *
	 * @access protected
	 * @see wpdb::check_connection()
	 * @var int
	 */
	protected $reconnect_retries = 3;

	/**
	 * Send Reads To Masters. This disables slave connections while true.
	 * Otherwise it is an array of written tables.
	 * @public array
	 */
	public $srtm = array();

	/**
	 * The log of db connections made and the time each one took
	 * @public array
	 */
	public $db_connections;

	/**
	 * The list of unclosed connections sorted by LRU
	 */
	public $open_connections = array();

	/**
	 * Lookup array (dbhname => host:port)
	 * @public array
	 */
	public $dbh2host = array();

	/**
	 * The last server used and the database name selected
	 * @public array
	 */
	public $last_used_server;

	/**
	 * Lookup array (dbhname => (server, db name) ) for re-selecting the db
	 * when a link is re-used.
	 * @public array
	 */
	public $used_servers = array();

	/**
	 * Whether to save debug_backtrace in save_query_callback. You may wish
	 * to disable this, e.g. when tracing out-of-memory problems.
	 */
	public $save_backtrace = true;

	/**
	 * Maximum lag in seconds. Set null to disable. Requires callbacks.
	 * @public integer
	 */
	public $default_lag_threshold = null;

	/**
	 * Gets ready to make database connections
	 * @param array db class vars
	 */
	public function __construct( $args = null ) {

		if ( WP_DEBUG && WP_DEBUG_DISPLAY ) {
			$this->show_errors();
		}

		/* Use ext/mysqli if it exists and:
		 *  - WP_USE_EXT_MYSQL is defined as false, or
		 *  - We are a development version of WordPress, or
		 *  - We are running PHP 5.5 or greater, or
		 *  - ext/mysql is not loaded.
		 */
		if ( function_exists( 'mysqli_connect' ) ) {
			if ( defined( 'WP_USE_EXT_MYSQL' ) ) {
				$this->use_mysqli = ! WP_USE_EXT_MYSQL;
			} elseif ( version_compare( phpversion(), '5.5', '>=' ) || ! function_exists( 'mysql_connect' ) ) {
				$this->use_mysqli = true;
			} elseif ( false !== strpos( $GLOBALS['wp_version'], '-' ) ) {
				$this->use_mysqli = true;
			}
		}


		$this->reconnect_retries = $this->min_tries;

		if ( is_array( $args ) ) {
			foreach ( get_class_vars( __CLASS__ ) as $var => $value ) {
				if ( isset( $args[$var] ) ) {
					$this->$var = $args[$var];
				}
			}
		}

		$this->init_charset();
	}

	/**
	 * Sets $this->charset and $this->collate
	 */
	public function init_charset() {
		global $wp_version;
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			if ( version_compare( $wp_version, '4.2', '<' ) ) {
				$this->charset = 'utf8';
				$this->collate = 'utf8_general_ci';
			} else {
				$this->charset = 'utf8mb4';
				$this->collate = 'utf8mb4_unicode_ci';
			}
			if ( defined( 'DB_COLLATE' ) && DB_COLLATE ) {
				$this->collate = DB_COLLATE;
			}
		} elseif ( defined( 'DB_COLLATE' ) ) {
			$this->collate = DB_COLLATE;
		}

		if ( defined( 'DB_CHARSET' ) ) {
			$this->charset = DB_CHARSET;
		}
	}

	/**
	 * Add the connection parameters for a database
	 */
	public function add_database( array $db = array() ) {

		$dataset = isset( $db['dataset'] )
			? $db['dataset']
			: 'global';

		$read = isset( $db['read'] )
			? (int) $db['read']
			: 1;

		$write = isset( $db['write'] )
			? (int) $db['write']
			: 1;

		unset( $db['dataset'] );

		if ( ! empty( $read ) ) {
			$this->ludicrous_servers[ $dataset ]['read'][ $read ][] = $db;
		}

		if ( ! empty( $write ) ) {
			$this->ludicrous_servers[ $dataset ]['write'][ $write ][] = $db;
		}
	}

	/**
	 * Specify the dataset where a table is found
	 */
	public function add_table( $dataset, $table ) {
		$this->ludicrous_tables[ $table ] = $dataset;
	}

	/**
	 * Add a callback to a group of callbacks.
	 * The default group is 'dataset', used to examine
	 * queries and determine dataset.
	 */
	public function add_callback( $callback, $group = 'dataset' ) {
		$this->ludicrous_callbacks[ $group ][] = $callback;
	}

	/**
	 * Determine the likelihood that this query could alter anything
	 * @param string query
	 * @return bool
	 */
	public function is_write_query( $q ) {
		// Quick and dirty: only SELECT statements are considered read-only.
		$q = ltrim( $q, "\r\n\t (" );
		return !preg_match( '/^(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\s/i', $q );
	}

	/**
	 * Set a flag to prevent reading from slaves which might be lagging after a write
	 */
	public function send_reads_to_masters() {
		$this->srtm = true;
	}

	/**
	 * Callbacks are executed in the order in which they are registered until one
	 * of them returns something other than null.
	 */
	public function run_callbacks( $group, $args = null ) {
		if ( ! isset( $this->ludicrous_callbacks[$group] ) || !is_array( $this->ludicrous_callbacks[$group] ) ) {
			return null;
		}

		if ( ! isset( $args ) ) {
			$args = array( &$this );
		} elseif ( is_array( $args ) ) {
			$args[] = &$this;
		} else {
			$args = array( $args, &$this );
		}

		foreach ( $this->ludicrous_callbacks[$group] as $func ) {
			$result = call_user_func_array( $func, $args );
			if ( isset( $result ) ) {
				return $result;
			}
		}
	}

	/**
	 * Figure out which database server should handle the query, and connect to it.
	 * @param string query
	 * @return resource mysql database connection
	 */
	public function db_connect( $query = '' ) {

		if ( empty( $query ) ) {
			return false;
		}

		$this->last_table = $this->table = $this->get_table_from_query( $query );

		// Use current table with no callback results
		if ( isset( $this->ludicrous_tables[$this->table] ) ) {
			$dataset               = $this->ludicrous_tables[$this->table];
			$this->callback_result = null;

		// Run callbacks and either extract or update dataset
		} else {

			// Run callbacks and get result
			$this->callback_result = $this->run_callbacks( 'dataset', $query );

			// Set if not null
			if ( ! is_null( $this->callback_result ) ) {
				if ( is_array( $this->callback_result ) ) {
					extract( $this->callback_result, EXTR_OVERWRITE );
				} else {
					$dataset = $this->callback_result;
				}
			}
		}

		if ( ! isset( $dataset ) ) {
			$dataset = 'global';
		}

		if ( empty( $dataset ) ) {
			return $this->bail( "Unable to determine which dataset to query. ($this->table)" );
		} else {
			$this->dataset = $dataset;
		}

		$this->run_callbacks( 'dataset_found', $dataset );

		if ( empty( $this->ludicrous_servers ) ) {
			if ( $this->dbh_type_check( $this->dbh ) ) {
				return $this->dbh;
			}

			if (
				!defined( 'DB_HOST' ) || !defined( 'DB_USER' ) || !defined( 'DB_PASSWORD' ) || !defined( 'DB_NAME' ) ) {
				return $this->bail( "We were unable to query because there was no database defined." );
			}

			// Fallback to wpdb db_connect method.

			$this->dbuser     = DB_USER;
			$this->dbpassword = DB_PASSWORD;
			$this->dbname     = DB_NAME;
			$this->dbhost     = DB_HOST;

			parent::db_connect();

			return $this->dbh;
		}

		// Determine whether the query must be sent to the master (a writable server)
		if ( !empty( $use_master ) || $this->srtm === true || isset( $this->srtm[$this->table] ) ) {
			$use_master = true;
		} elseif ( $is_write = $this->is_write_query( $query ) ) {
			$use_master = true;
			if ( is_array( $this->srtm ) ) {
				$this->srtm[$this->table] = true;
			}

		// Detect queries that have a join in the srtm array.
		} elseif ( ! isset( $use_master ) && is_array( $this->srtm ) && !empty( $this->srtm ) ) {
			$use_master  = false;
			$query_match = substr( $query, 0, 1000 );
			foreach ( $this->srtm as $key => $value ) {
				if ( false !== stripos( $query_match, $key ) ) {
					$use_master = true;
					break;
				}
			}
		} else {
			$use_master = false;
		}

		if ( $use_master ) {
			$this->dbhname = $dbhname = $dataset . '__w';
			$operation     = 'write';
		} else {
			$this->dbhname = $dbhname = $dataset . '__r';
			$operation     = 'read';
		}

		// Try to reuse an existing connection
		while ( isset( $this->dbhs[$dbhname] ) && $this->dbh_type_check( $this->dbhs[$dbhname] ) ) {

			// Find the connection for incrementing counters
			foreach ( array_keys( $this->db_connections ) as $i ) {
				if ( $this->db_connections[$i]['dbhname'] == $dbhname ) {
					$conn = & $this->db_connections[$i];
				}
			}

			if ( isset( $server['name'] ) ) {
				$name = $server['name'];

				// A callback has specified a database name so it's possible the
				// existing connection selected a different one.
				if ( $name != $this->used_servers[$dbhname]['name'] ) {
					if ( !$this->select( $name, $this->dbhs[$dbhname] ) ) {
						// this can happen when the user varies and lacks permission on the $name database
						if ( isset( $conn['disconnect (select failed)'] ) ) {
							++$conn['disconnect (select failed)'];
						} else {
							$conn['disconnect (select failed)'] = 1;
						}

						$this->disconnect( $dbhname );
						break;
					}
					$this->used_servers[$dbhname]['name'] = $name;
				}
			} else {
				$name = $this->used_servers[$dbhname]['name'];
			}

			$this->current_host = $this->dbh2host[$dbhname];

			// Keep this connection at the top of the stack to prevent disconnecting frequently-used connections
			if ( $k = array_search( $dbhname, $this->open_connections ) ) {
				unset( $this->open_connections[$k] );
				$this->open_connections[] = $dbhname;
			}

			$this->last_used_server = $this->used_servers[$dbhname];
			$this->last_connection  = compact( 'dbhname', 'name' );

			if ( ! $this->check_connection( false, $this->dbhs[ $dbhname ] ) ) {
				if ( isset( $conn['disconnect (ping failed)'] ) ) {
					++$conn['disconnect (ping failed)'];
				} else {
					$conn['disconnect (ping failed)'] = 1;
				}

				$this->disconnect( $dbhname );
				break;
			}

			if ( isset( $conn['queries'] ) ) {
				++$conn['queries'];
			} else {
				$conn['queries'] = 1;
			}

			return $this->dbhs[$dbhname];
		}

		if ( $use_master && defined( "MASTER_DB_DEAD" ) ) {
			return $this->bail( "We're updating the database, please try back in 5 minutes. If you are posting to your blog please hit the refresh button on your browser in a few minutes to post the data again. It will be posted as soon as the database is back online again." );
		}

		if ( empty( $this->ludicrous_servers[$dataset][$operation] ) ) {
			return $this->bail( "No databases available with $this->table ($dataset)" );
		}

		// Put the groups in order by priority
		ksort( $this->ludicrous_servers[$dataset][$operation] );

		// Make a list of at least $this->reconnect_retries connections to try, repeating as necessary.
		$servers = array();
		do {
			foreach ( $this->ludicrous_servers[$dataset][$operation] as $group => $items ) {
				$keys = array_keys( $items );
				shuffle( $keys );
				foreach ( $keys as $key ) {
					$servers[] = compact( 'group', 'key' );
				}
			}

			if ( ! $tries_remaining = count( $servers ) ) {
				return $this->bail( "No database servers were found to match the query. ($this->table, $dataset)" );
			}

			if ( ! isset( $unique_servers ) ) {
				$unique_servers = $tries_remaining;
			}
		} while ( $tries_remaining < $this->reconnect_retries );

		// Connect to a database server
		do {
			$unique_lagged_slaves = array();
			$success = false;

			foreach ( $servers as $group_key ) {
				--$tries_remaining;

				// If all servers are lagged, we need to start ignoring the lag and retry
				if ( count( $unique_lagged_slaves ) == $unique_servers ) {
					break;
				}

				// $group, $key
				extract( $group_key, EXTR_OVERWRITE );

				// $host, $user, $password, $name, $read, $write [, $lag_threshold, $timeout ]
				extract( $this->ludicrous_servers[ $dataset ][ $operation ][ $group ][ $key ], EXTR_OVERWRITE );
				$port = null;

				// Split host:port into $host and $port
				if ( strpos( $host, ':' ) ) {
					list($host, $port) = explode( ':', $host );
				}

				// Overlay $server if it was extracted from a callback
				if ( isset( $server ) && is_array( $server ) ) {
					extract( $server, EXTR_OVERWRITE );
				}

				// Split again in case $server had host:port
				if ( strpos( $host, ':' ) ) {
					list($host, $port) = explode( ':', $host );
				}

				// Make sure there's always a port number
				if ( empty( $port ) ) {
					$port = 3306;
				}

				// Use a default timeout of 200ms
				if ( ! isset( $timeout ) ) {
					$timeout = 0.2;
				}

				// Get the minimum group here, in case $server rewrites it
				if ( ! isset( $min_group ) || $min_group > $group ) {
					$min_group = $group;
				}

				// Can be used by the lag callbacks
				$this->lag_cache_key = "$host:$port";
				$this->lag_threshold = isset( $lag_threshold )
					? $lag_threshold
					: $this->default_lag_threshold;

				// Check for a lagged slave, if applicable
				if ( ! $use_master && ! $write && ! isset( $ignore_slave_lag ) && isset( $this->lag_threshold ) && ! isset( $server['host'] ) && ( $lagged_status = $this->get_lag_cache() ) === DB_LAG_BEHIND ) {

					// If it is the last lagged slave and it is with the best preference we will ignore its lag
					if ( ! isset( $unique_lagged_slaves["$host:$port"] ) && $unique_servers == count( $unique_lagged_slaves ) + 1 && $group == $min_group ) {
						$this->lag_threshold = null;
					} else {
						$unique_lagged_slaves["$host:$port"] = $this->lag;
						continue;
					}
				}

				$this->timer_start();

				// Connect if necessary or possible
				$tcp = null;
				if ( $use_master || ! $tries_remaining || ! $this->check_tcp_responsiveness || true === $tcp = $this->check_tcp_responsiveness( $host, $port, $timeout ) ) {
					$this->single_db_connect( $dbhname, "$host:$port", $user, $password );
				} else {
					$this->dbhs[$dbhname] = false;
				}

				$elapsed = $this->timer_stop();

				if ( $this->dbh_type_check( $this->dbhs[ $dbhname ] ) ) {
					/**
					 * If we care about lag, disconnect lagged slaves and try to find others.
					 * We don't disconnect if it is the last lagged slave and it is with the best preference.
					 */
					if ( ! $use_master && ! $write && ! isset( $ignore_slave_lag ) && isset( $this->lag_threshold ) && ! isset( $server['host'] ) && $lagged_status !== DB_LAG_OK && ( $lagged_status = $this->get_lag() ) === DB_LAG_BEHIND && !(
						! isset( $unique_lagged_slaves["$host:$port"] ) && $unique_servers == count( $unique_lagged_slaves ) + 1 && $group == $min_group
						)
					) {
						$success = false;
						$unique_lagged_slaves["$host:$port"] = $this->lag;
						$this->disconnect( $dbhname );
						$this->dbhs[$dbhname] = false;
						$msg = "Replication lag of {$this->lag}s on $host:$port ($dbhname)";
						$this->print_error( $msg );
						continue;
					} else{
						$this->set_sql_mode( array(), $this->dbhs[ $dbhname ] );
						if ( $this->select( $name, $this->dbhs[$dbhname] ) ) {
							$success = true;
							$this->current_host = "$host:$port";
							$this->dbh2host[$dbhname] = "$host:$port";
							$queries = 1;
							$lag = isset( $this->lag )
								? $this->lag
								: 0;
							$this->last_connection    = compact( 'dbhname', 'host', 'port', 'user', 'name', 'tcp', 'elapsed', 'success', 'queries', 'lag' );
							$this->db_connections[]   = $this->last_connection;
							$this->open_connections[] = $dbhname;
							break;
						}
					}
				}

				$success = false;
				$this->last_connection  = compact( 'dbhname', 'host', 'port', 'user', 'name', 'tcp', 'elapsed', 'success' );
				$this->db_connections[] = $this->last_connection;

				if ( $this->use_mysqli ) {
					$error = mysqli_error( $this->dbhs[ $dbhname ] );
					$errno = mysqli_errno( $this->dbhs[ $dbhname ] );
				} else {
					$error = mysql_error( $this->dbhs[ $dbhname ] );
					$errno = mysql_errno( $this->dbhs[ $dbhname ] );
				}

				$msg = date( "Y-m-d H:i:s" ) . " Can't select $dbhname - \n";
				$msg .= "'referrer' => '{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}',\n";
				$msg .= "'host' => {$host},\n";
				$msg .= "'error' => " . $error . ",\n";
				$msg .= "'errno' => " . $errno . ",\n";
				$msg .= "'tcp_responsive' => " . ( $tcp === true
						? 'true'
						: $tcp ) . ",\n";
				$msg .= "'lagged_status' => " . ( isset( $lagged_status )
						? $lagged_status
						: DB_LAG_UNKNOWN );

				$this->print_error( $msg );
			}

			if ( ! $success || ! isset( $this->dbhs[$dbhname] ) || !$this->dbh_type_check( $this->dbhs[$dbhname] ) ) {
				if ( ! isset( $ignore_slave_lag ) && count( $unique_lagged_slaves ) ) {
					// Lagged slaves were not used. Ignore the lag for this connection attempt and retry.
					$ignore_slave_lag = true;
					$tries_remaining = count( $servers );
					continue;
				}

				$error_details = array(
					'host' => $host,
					'port' => $port,
					'operation' => $operation,
					'table' => $this->table,
					'dataset' => $dataset,
					'dbhname' => $dbhname
				);
				$this->run_callbacks( 'db_connection_error', $error_details );

				return $this->bail( "Unable to connect to $host:$port to $operation table '$this->table' ($dataset)" );
			}

			break;
		} while ( true );

		if ( ! isset( $charset ) ) {
			$charset = null;
		}

		if ( ! isset( $collate ) ) {
			$collate = null;
		}

		$this->set_charset( $this->dbhs[$dbhname], $charset, $collate );

		$this->dbh                    = $this->dbhs[$dbhname]; // needed by $wpdb->_real_escape()
		$this->last_used_server       = compact( 'host', 'user', 'name', 'read', 'write' );
		$this->used_servers[$dbhname] = $this->last_used_server;

		while ( ! $this->persistent && count( $this->open_connections ) > $this->max_connections ) {
			$oldest_connection = array_shift( $this->open_connections );
			if ( $this->dbhs[$oldest_connection] != $this->dbhs[$dbhname] ) {
				$this->disconnect( $oldest_connection );
			}
		}

		return $this->dbhs[$dbhname];
	}


	/**
	 * Connect selected database.
	 *
	 * @param string $dbhname
	 * @param string $host     Internet address: host:port of server on internet.
	 * @param string $user     Database user.
	 * @param string $password Database password.
	 *
	 * @return bool|mysqli|resource
	 */
	protected function single_db_connect( $dbhname, $host, $user, $password ) {
		$this->is_mysql = true;

		/*
		 * Deprecated in 3.9+ when using MySQLi. No equivalent
		 * $new_link parameter exists for mysqli_* functions.
		 */
		$new_link     = defined( 'MYSQL_NEW_LINK' ) ? MYSQL_NEW_LINK : true;
		$client_flags = defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0;

		if ( $this->use_mysqli ) {
			$this->dbhs[ $dbhname ] = mysqli_init();

			// mysqli_real_connect doesn't support the host param including a port or socket
			// like mysql_connect does. This duplicates how mysql_connect detects a port and/or socket file.
			$port           = null;
			$socket         = null;
			$port_or_socket = strstr( $host, ':' );
			if ( ! empty( $port_or_socket ) ) {
				$host           = substr( $host, 0, strpos( $host, ':' ) );
				$port_or_socket = substr( $port_or_socket, 1 );
				if ( 0 !== strpos( $port_or_socket, '/' ) ) {
					$port         = intval( $port_or_socket );
					$maybe_socket = strstr( $port_or_socket, ':' );
					if ( ! empty( $maybe_socket ) ) {
						$socket = substr( $maybe_socket, 1 );
					}
				} else {
					$socket = $port_or_socket;
				}
			}
			// Detail found here - https://core.trac.wordpress.org/ticket/31018
			$pre_host = '';
			// If DB_HOST begins with a 'p:', allow it to be passed to mysqli_real_connect().
			// mysqli supports persistent connections starting with PHP 5.3.0.
			if ( $this->persistent && version_compare( phpversion(), '5.3.0', '>=' ) ) {
				$pre_host = 'p:';
			}

			mysqli_real_connect( $this->dbhs[ $dbhname ], $pre_host . $host, $user, $password, null, $port, $socket, $client_flags );

			if ( $this->dbhs[ $dbhname ]->connect_errno ) {
				$this->dbhs[ $dbhname ] = false;
				return false;
			}
		} else {
			// Check if functions exists, as in PHP 7, they may not.
			if ( $this->persistent && function_exists( 'mysql_pconnect' ) ) {
				$this->dbhs[ $dbhname ] = mysql_pconnect( $host, $user, $password, $new_link, $client_flags );
			} elseif ( function_exists( 'mysql_connect' ) ) {
				$this->dbhs[ $dbhname ] = mysql_connect( $host, $user, $password, $new_link, $client_flags );
			}

		}
	}


	/**
	 * Change the current SQL mode, and ensure its WordPress compatibility.
	 *
	 * If no modes are passed, it will ensure the current MySQL server
	 * modes are compatible.
	 *
	 *
	 * @param array $modes Optional. A list of SQL modes to set.
	 * @param false|string|resource $dbh_or_table the databaese (the current database, the database housing the specified table, or the database of the mysql resource)
	 */
	public function set_sql_mode( $modes = array(), $dbh_or_table = false ) {
		$dbh = $this->get_db_object( $dbh_or_table );
		if ( empty( $modes ) ) {
			if ( $this->use_mysqli ) {
				$res = mysqli_query( $dbh, 'SELECT @@SESSION.sql_mode' );
			} else {
				$res = mysql_query( 'SELECT @@SESSION.sql_mode', $dbh );
			}
			if ( empty( $res ) ) {
				return;
			}
			if ( $this->use_mysqli ) {
				$modes_array = mysqli_fetch_array( $res );
				if ( empty( $modes_array[0] ) ) {
					return;
				}
				$modes_str = $modes_array[0];
			} else {
				$modes_str = mysql_result( $res, 0 );
			}
			if ( empty( $modes_str ) ) {
				return;
			}
			$modes = explode( ',', $modes_str );
		}
		$modes = array_change_key_case( $modes, CASE_UPPER );
		/**
		 * Filter the list of incompatible SQL modes to exclude.
		 *
		 * @param array $incompatible_modes An array of incompatible modes.
		 */
		$incompatible_modes = (array) apply_filters( 'incompatible_sql_modes', $this->incompatible_modes );
		foreach ( $modes as $i => $mode ) {
			if ( in_array( $mode, $incompatible_modes ) ) {
				unset( $modes[ $i ] );
			}
		}
		$modes_str = implode( ',', $modes );
		if ( $this->use_mysqli ) {
			mysqli_query( $dbh, "SET SESSION sql_mode='$modes_str'" );
		} else {
			mysql_query( "SET SESSION sql_mode='$modes_str'", $dbh );
		}
	}

	/**
	 * Selects a database using the current database connection.
	 *
	 * The database name will be changed based on the current database
	 * connection. On failure, the execution will bail and display an DB error.
	 *
	 *
	 * @param string        $db  MySQL database name
	 * @param false|string|resource $dbh_or_table the databaese (the current database, the database housing the specified table, or the database of the mysql resource)
	 */
	public function select( $db, $dbh_or_table = false ) {
		$dbh = $this->get_db_object( $dbh_or_table );

		if ( $this->use_mysqli ) {
			$success = mysqli_select_db( $dbh, $db );
		} else {
			$success = mysql_select_db( $db, $dbh );
		}

		return $success;
	}

	/**
	 * Load the column metadata from the last query.
	 *
	 *
	 * @access protected
	 */
	protected function load_col_info() {
		if ( $this->col_info )
			return;
		$this->col_info = array();
		if ( $this->use_mysqli ) {
			$num_fields = mysqli_num_fields( $this->result );
			for ( $i = 0; $i < $num_fields; $i ++ ) {
				$this->col_info[ $i ] = mysqli_fetch_field( $this->result );
			}
		} else {
			$num_fields = mysql_num_fields( $this->result );
			for ( $i = 0; $i < $num_fields; $i ++ ) {
				$this->col_info[ $i ] = mysql_fetch_field( $this->result, $i );
			}
		}
	}

	/*
	 * Force addslashes() for the escapes.
	 *
	 * LudicrousDB makes connections when a query is made
	 * which is why we can't use mysql_real_escape_string() for escapes.
	 * This is also the reason why we don't allow certain charsets. See set_charset().
	 */

	public function _real_escape( $string ) {
		return addslashes( $string );
	}

	/**
	 * Sets the connection's character set.
	 * @param resource $dbh     The resource given by mysql_connect
	 * @param string   $charset The character set (optional)
	 * @param string   $collate The collation (optional)
	 */
	public function set_charset( $dbh, $charset = null, $collate = null ) {
		if ( ! isset( $charset ) ) {
			$charset = $this->charset;
		}

		if ( ! isset( $collate ) ) {
			$collate = $this->collate;
		}

		if ( empty( $charset ) || empty( $collate ) ) {
			wp_die( $dbh . '  ' . $charset . '  ' . $collate );
		}

		if ( !in_array( strtolower( $charset ), array( 'utf8', 'utf8mb4', 'latin1' ) ) ) {
			wp_die( "$charset charset isn't supported in LudicrousDB for security reasons" );
		}

		if ( $this->has_cap( 'collation', $dbh ) && ! empty( $charset ) ) {
			if ( $this->use_mysqli && function_exists( 'mysqli_set_charset' ) && $this->has_cap( 'set_charset', $dbh ) ) {
				mysqli_set_charset( $dbh, $charset );
			} else if ( function_exists( 'mysql_set_charset' ) && $this->has_cap( 'set_charset', $dbh ) ) {
				mysql_set_charset( $charset, $dbh );
			} else {
				$query = $this->prepare( 'SET NAMES %s', $charset );
				if ( ! empty( $collate ) ) {
					$query .= $this->prepare( ' COLLATE %s', $collate );
				}
				$this->_do_query( $query, $dbh );
			}
		}
	}

	/**
	 * Disconnect and remove connection from open connections list
	 * @param string $dbhname
	 */
	public function disconnect( $dbhname ) {

		$k = array_search( $dbhname, $this->open_connections );
		if ( ! empty( $k ) ) {
			unset( $this->open_connections[$k] );
		}

		if ( $this->dbh_type_check( $this->dbhs[$dbhname] ) ) {
			$this->close( $this->dbhs[$dbhname] );
		}

		unset( $this->dbhs[$dbhname] );
	}

	/**
	 * Kill cached query results
	 */
	public function flush() {
		$this->last_error = '';
		$this->num_rows = 0;
		parent::flush();
	}


	/**
	 * Check that the connection to the database is still up. If not, try to reconnect.
	 *
	 * If this function is unable to reconnect, it will forcibly die, or if after the
	 * the template_redirect hook has been fired, return false instead.
	 *
	 * If $allow_bail is false, the lack of database connection will need
	 * to be handled manually.
	 *
	 *
	 * @param bool $allow_bail Optional. Allows the function to bail. Default true.
	 * @return bool|void True if the connection is up.
	 */
	public function check_connection( $allow_bail = true, $dbh_or_table = false ) {

		$dbh = $this->get_db_object( $dbh_or_table );

			if ( $this->use_mysqli ) {
				if ( mysqli_ping( $dbh ) ) {
					return true;
				}
			} else {
				if ( mysql_ping( $dbh ) ) {
					return true;
				}
			}
		}

		if ( ! $allow_bail ) {
			return false;
		}

		$error_reporting = false;
		// Disable warnings, as we don't want to see a multitude of "unable to connect" messages
		if ( WP_DEBUG ) {
			$error_reporting = error_reporting();
			error_reporting( $error_reporting & ~E_WARNING );
		}
		for ( $tries = 1; $tries <= $this->reconnect_retries; $tries++ ) {
			// On the last try, re-enable warnings. We want to see a single instance of the
			// "unable to connect" message on the bail() screen, if it appears.
			if ( $this->reconnect_retries === $tries && WP_DEBUG ) {
				error_reporting( $error_reporting );
			}
			if ( $this->db_connect( false ) ) {
				if ( $error_reporting ) {
					error_reporting( $error_reporting );
				}
				return true;
			}
			sleep( 1 );
		}
		// If template_redirect has already happened, it's too late for wp_die()/dead_db().
		// Let's just return and hope for the best.
		if ( did_action( 'template_redirect' ) ) {
			return false;
		}

		wp_load_translations_early();
		$message = '<h1>' . __( 'Error reconnecting to the database' ) . "</h1>\n";
		$message .= '<p>' . sprintf(
			/* translators: %s: database host */
				__( 'This means that we lost contact with the database server at %s. This could mean your host&#8217;s database server is down.' ),
				'<code>' . htmlspecialchars( $this->dbhost, ENT_QUOTES ) . '</code>'
			) . "</p>\n";
		$message .= "<ul>\n";
		$message .= '<li>' . __( 'Are you sure that the database server is running?' ) . "</li>\n";
		$message .= '<li>' . __( 'Are you sure that the database server is not under particularly heavy load?' ) . "</li>\n";
		$message .= "</ul>\n";
		$message .= '<p>' . sprintf(
			/* translators: %s: support forums URL */
				__( 'If you&#8217;re unsure what these terms mean you should probably contact your host. If you still need help you can always visit the <a href="%s">WordPress Support Forums</a>.' ),
				__( 'https://wordpress.org/support/' )
			) . "</p>\n";
		// We weren't able to reconnect, so we better bail.
		$this->bail( $message, 'db_connect_fail' );
		// Call dead_db() if bail didn't die, because this database is no more. It has ceased to be (at least temporarily).
		dead_db();
	}

	/**
	 * Basic query. See docs for more details.
	 * @param string $query
	 * @return int number of rows
	 */
	public function query( $query ) {

		// some queries are made before the plugins have been loaded, and thus cannot be filtered with this method
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the database query.
			 *
			 * Some queries are made before the plugins have been loaded,
			 * and thus cannot be filtered with this method.
			 *
			 * @param string $query Database query.
			 */
			$query = apply_filters( 'query', $query );
		}

		// initialise return
		$return_val = 0;
		$this->flush();

		// Log how the function was called
		$this->func_call = "\$db->query(\"$query\")";

		// If we're writing to the database, make sure the query will write safely.
		if ( $this->check_current_query && ! $this->check_ascii( $query ) ) {
			$stripped_query = $this->strip_invalid_text_from_query( $query );
			// strip_invalid_text_from_query() can perform queries, so we need
			// to flush again, just to make sure everything is clear.
			$this->flush();
			if ( $stripped_query !== $query ) {
				$this->insert_id = 0;
				return false;
			}
		}

		$this->check_current_query = true;

		// Keep track of the last query for debug..
		$this->last_query = $query;

		if ( preg_match( '/^\s*SELECT\s+FOUND_ROWS(\s*)/i', $query ) && ( ( ! $this->use_mysqli && is_resource( $this->last_found_rows_result ) ) || ( $this->use_mysqli && $this->last_found_rows_result instanceof mysqli_result ) ) ) {
			$this->result = $this->last_found_rows_result;
			$elapsed = 0;
		} else {
			$this->dbh = $this->db_connect( $query );

			if ( !$this->dbh_type_check( $this->dbh ) ) {
				return false;
			}

			$this->timer_start();
			$this->result = $this->_do_query( $query, $this->dbh );
			$elapsed      = $this->timer_stop();

			++$this->num_queries;

			if ( preg_match( '/^\s*SELECT\s+SQL_CALC_FOUND_ROWS\s/i', $query ) ) {
				if ( false === strpos( $query, "NO_SELECT_FOUND_ROWS" ) ) {
					$this->timer_start();
					$this->last_found_rows_result = $this->_do_query( "SELECT FOUND_ROWS()", $this->dbh );
					$elapsed += $this->timer_stop();
					++$this->num_queries;
					$query .= "; SELECT FOUND_ROWS()";
				}
			} else {
				$this->last_found_rows_result = null;
			}

			if ( $this->save_queries || ( defined( 'SAVEQUERIES' ) && SAVEQUERIES )) {
				if ( is_callable( $this->save_query_callback ) ) {
					$this->queries[] = call_user_func_array( $this->save_query_callback, array( $query, $elapsed, $this->save_backtrace
							? debug_backtrace( false )
							: null, &$this ) );
				} else {
					$this->queries[] = array( $query, $elapsed, $this->get_caller() );
				}
			}
		}

		// If there is an error then take note of it
		if ( $this->use_mysqli ) {
			$this->last_error = mysqli_error( $this->dbh );
		} else {
			$this->last_error = mysql_error( $this->dbh );
		}

		if ( ! empty( $this->last_error ) ) {
			$this->print_error( $this->last_error );
			return false;
		}

		if ( preg_match( "/^\\s*(insert|delete|update|replace|alter) /i", $query ) ) {
			if ( $this->use_mysqli ) {
				$this->rows_affected = mysqli_affected_rows( $this->dbh );
			} else {
				$this->rows_affected = mysql_affected_rows( $this->dbh );
			}
			// Take note of the insert_id
			if ( preg_match( '/^\s*(insert|replace)\s/i', $query ) ) {
				if ( $this->use_mysqli ) {
					$this->insert_id = mysqli_insert_id( $this->dbh );
				} else {
					$this->insert_id = mysql_insert_id( $this->dbh );
				}
			}

			// Return number of rows affected
			$return_val = $this->rows_affected;
		} else {
			$this->load_col_info();
			$num_rows = 0;
			$this->last_result = array();
			if ( $this->use_mysqli && $this->result instanceof mysqli_result ) {
				while ( $row = mysqli_fetch_object( $this->result ) ) {
					$this->last_result[$num_rows] = $row;
					$num_rows++;
				}
			} elseif ( is_resource( $this->result ) ) {
				while ( $row = mysql_fetch_object( $this->result ) ) {
					$this->last_result[$num_rows] = $row;
					$num_rows++;
				}
			}
			// Log number of rows the query returned
			// and return number of rows selected
			$this->num_rows = $num_rows;
			$return_val     = $num_rows;
		}

		return $return_val;
	}

	/**
	 *
	 * Internal function to perform the mysql_query() call.
	 *
	 *
	 * @access protected
	 * @see wpdb::query()
	 *
	 * @param string $query The query to run.
	 * @param bool $dbh_or_table
	 */
	protected function _do_query( $query, $dbh_or_table = false ) {

		$dbh = $this->get_db_object( $dbh_or_table );
		$result = false;
		if ( $this->dbh_type_check( $dbh ) ) {
			if ( $this->use_mysqli ) {
				$result = mysqli_query( $dbh, $query );
			} else {
				$result = mysql_query( $query, $dbh );
			}
		}

		return $result;
	}

	/**
	 * Closes the current database connection.
	 *
	 * @access public
	 * @param false|string|resource $dbh_or_table the databaese (the current database, the database housing the specified table, or the database of the mysql resource)
	 * @return bool True if the connection was successfully closed, false if it wasn't,
	 *              or the connection doesn't exist.
	 */
	public function close( $dbh_or_table = false ) {
		$dbh = $this->get_db_object( $dbh_or_table );

		if ( ! $this->dbh_type_check( $dbh ) ) {
			return false;
		}

		if ( $this->use_mysqli ) {
			$closed = mysqli_close( $dbh );
		} else {
			$closed = mysql_close( $dbh );
		}

		if ( $closed ) {
			$this->dbh = null;
		}

		return $closed;
	}

	/**
	 * Whether or not MySQL database is at least the required minimum version.
	 * The additional argument allows the caller to check a specific database.
	 *
	 * @since 2.5.0
	 * @uses $wp_version
	 * @param false|string|resource $dbh_or_table the databaese (the current database, the database housing the specified table, or the database of the mysql resource)
	 * @return WP_Error
	 */
	public function check_database_version( $dbh_or_table = false ) {
		global $wp_version, $required_mysql_version;
		// Make sure the server has the required MySQL version
		$mysql_version = preg_replace( '|[^0-9\.]|', '', $this->db_version( $dbh_or_table ) );
		if ( version_compare( $mysql_version, $required_mysql_version, '<' ) ) {
			return new WP_Error( 'database_version', sprintf( __( '<strong>ERROR</strong>: WordPress %1$s requires MySQL %2$s or higher' ), $wp_version, $required_mysql_version ) );
		}
	}

	/**
	 * This function is called when WordPress is generating the table schema to determine wether or not the current database
	 * supports or needs the collation statements.
	 * The additional argument allows the caller to check a specific database.
	 * @param false|string|resource $dbh_or_table the databaese (the current database, the database housing the specified table, or the database of the mysql resource)
	 * @return bool
	 */
	public function supports_collation( $dbh_or_table = false ){
		_deprecated_function( __FUNCTION__, '3.5', 'wpdb::has_cap( \'collation\' )' );
		return $this->has_cap( 'collation', $dbh_or_table );
	}

	/**
	 * Generic function to determine if a database supports a particular feature
	 * The additional argument allows the caller to check a specific database.
	 * @param string $db_cap the feature
	 * @param false|string|resource $dbh_or_table the databaese (the current database, the database housing the specified table, or the database of the mysql resource)
	 * @return bool
	 */
	public function has_cap( $db_cap, $dbh_or_table = false ) {
		$version = $this->db_version( $dbh_or_table );

		switch ( strtolower( $db_cap ) ) {
			case 'collation' :    // @since 2.5.0
			case 'group_concat' : // @since 2.7.0
			case 'subqueries' :   // @since 2.7.0
				return version_compare( $version, '4.1', '>=' );
			case 'set_charset' :
				return version_compare( $version, '5.0.7', '>=' );
			case 'utf8mb4' :      // @since 4.1.0
				if ( version_compare( $version, '5.5.3', '<' ) ) {
					return false;
				}

				$dbh = $this->get_db_object( $dbh_or_table );
				if ( $this->dbh_type_check( $dbh ) ) {
					if ( $this->use_mysqli ) {
						$client_version = mysqli_get_client_info( $dbh );
					} else {
						$client_version = mysql_get_client_info( $dbh );
					}

					/*
					 * libmysql has supported utf8mb4 since 5.5.3, same as the MySQL server.
					 * mysqlnd has supported utf8mb4 since 5.0.9.
					 */
					if ( false !== strpos( $client_version, 'mysqlnd' ) ) {
						$client_version = preg_replace( '/^\D+([\d.]+).*/', '$1', $client_version );

						return version_compare( $client_version, '5.0.9', '>=' );
					} else {
						return version_compare( $client_version, '5.5.3', '>=' );
					}
				}
		}

		return false;
	}

	/**
	 * The database version number
	 * @param false|string|resource $dbh_or_table the databaese (the current database, the database housing the specified table, or the database of the mysql resource)
	 * @return false|string false on failure, version number on success
	 */
	public function db_version( $dbh_or_table = false ) {
		$dbh = $this->get_db_object( $dbh_or_table );

		if ( $this->dbh_type_check( $dbh ) ) {
			if ( $this->use_mysqli ) {
				$server_info = mysqli_get_server_info( $dbh );
			} else {
				$server_info = mysql_get_server_info( $dbh );
			}

			return preg_replace( '/[^0-9.].*/', '', $server_info );
		}

		return false;
	}

	/**
	 * Get the db connection object.
	 * @param false|string|resource $dbh_or_table the databaese (the current database, the database housing the specified table, or the database of the mysql resource)
	 */
	private function get_db_object( $dbh_or_table ) {

		if ( $this->dbh_type_check( $dbh_or_table ) ) {
			$dbh = &$dbh_or_table;
		} elseif ( ! $dbh_or_table && $this->dbh_type_check( $this->dbh ) ) {
			$dbh = &$this->dbh;
		} else {
			$dbh = $this->db_connect( "SELECT FROM $dbh_or_table $this->users" );
		}

		return $dbh;
	}

	/**
	 *
	 * @param $dbh resource|mysqli
	 *
	 * @return bool
	 */
	private function dbh_type_check( $dbh ) {
		if ( $this->use_mysqli && ( $dbh instanceof mysqli ) ) {
			return true;
		} else if ( is_resource( $dbh ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the name of the function that called wpdb.
	 * @return string the name of the calling function
	 */
	public function get_caller() {
		if ( function_exists( 'wp_debug_backtrace_summary' ) ) {
			return wp_debug_backtrace_summary( __CLASS__ );
		}

		// requires PHP 4.3+
		if ( ! is_callable( 'debug_backtrace' ) ) {
			return '';
		}

		$bt     = debug_backtrace( false );
		$caller = '';

		foreach ( (array) $bt as $trace ) {
			if ( isset( $trace['class'] ) && is_a( $this, $trace['class'] ) ) {
				continue;
			} elseif ( ! isset( $trace['function'] ) ) {
				continue;
			} elseif ( strtolower( $trace['function'] ) === 'call_user_func_array' ) {
				continue;
			} elseif ( strtolower( $trace['function'] ) === 'apply_filters' ) {
				continue;
			} elseif ( strtolower( $trace['function'] ) === 'do_action' ) {
				continue;
			}

			if ( isset( $trace['class'] ) ) {
				$caller = $trace['class'] . '::' . $trace['function'];
			} else {
				$caller = $trace['function'];
			}

			break;
		}

		return $caller;
	}

	/**
	 * Check the responsiveness of a tcp/ip daemon
	 * @return (bool) true when $host:$post responds within $float_timeout seconds, else (bool) false
	 */
	public function check_tcp_responsiveness( $host, $port, $float_timeout ) {
		if ( function_exists( 'apc_store' ) ) {
			$use_apc = true;
			$apc_key = "{$host}{$port}";
			$apc_ttl = 10;
		} else {
			$use_apc = false;
		}

		if ( $use_apc ) {
			$cached_value = apc_fetch( $apc_key );
			switch ( $cached_value ) {
				case 'up':
					$this->tcp_responsive = 'true';
					return true;
				case 'down':
					$this->tcp_responsive = 'false';
					return false;
			}
		}

		$socket = @fsockopen( $host, $port, $errno, $errstr, $float_timeout );
		if ( $socket === false ) {
			if ( $use_apc ) {
				apc_store( $apc_key, 'down', $apc_ttl );
			}
			return "[ > $float_timeout ] ($errno) '$errstr'";
		}

		fclose( $socket );

		if ( $use_apc ) {
			apc_store( $apc_key, 'up', $apc_ttl );
		}

		return true;
	}

	public function get_lag_cache() {
		$this->lag = $this->run_callbacks( 'get_lag_cache' );

		return $this->check_lag();
	}

	public function get_lag() {
		$this->lag = $this->run_callbacks( 'get_lag' );

		return $this->check_lag();
	}

	public function check_lag() {
		if ( false === $this->lag ) {
			return DB_LAG_UNKNOWN;
		}

		if ( $this->lag > $this->lag_threshold ) {
			return DB_LAG_BEHIND;
		}

		return DB_LAG_OK;
	}

	/**
	 * Retrieves a table's character set.
	 *
	 * NOTE: This must be called after LudicrousDB::db_connect, so that wpdb::dbh is set correctly
	 *
	 * @param string $table Table name
	 *
	 * @return mixed The table character set, or WP_Error if we couldn't find it
	 */
	protected function get_table_charset( $table ) {
		$tablekey = strtolower( $table );

		/**
		 * Filter the table charset value before the DB is checked.
		 *
		 * Passing a non-null value to the filter will effectively short-circuit
		 * checking the DB for the charset, returning that value instead.
		 *
		 *
		 * @param string $charset The character set to use. Default null.
		 * @param string $table   The name of the table being checked.
		 */
		$charset = apply_filters( 'pre_get_table_charset', null, $table );
		if ( null !== $charset ) {
			return $charset;
		}

		if ( isset( $this->table_charset[ $tablekey ] ) ) {
			return $this->table_charset[ $tablekey ];
		}

		$charsets = $columns = array();

		$table_parts = explode( '.', $table );
		$table = '`' . implode( '`.`', $table_parts ) . '`';
		$results = $this->get_results( "SHOW FULL COLUMNS FROM $table" );
		if ( ! $results ) {
			return new WP_Error( 'wpdb_get_table_charset_failure' );
		}

		foreach ( $results as $column ) {
			$columns[ strtolower( $column->Field ) ] = $column;
		}

		$this->col_meta[ $tablekey ] = $columns;

		foreach ( $columns as $column ) {
			if ( ! empty( $column->Collation ) ) {
				list( $charset ) = explode( '_', $column->Collation );

				// If the current connection can't support utf8mb4 characters, let's only send 3-byte utf8 characters.
				if ( 'utf8mb4' === $charset && ! $this->has_cap( 'utf8mb4' ) ) {
					$charset = 'utf8';
				}

				$charsets[ strtolower( $charset ) ] = true;
			}

			list( $type ) = explode( '(', $column->Type );

			// A binary/blob means the whole query gets treated like this.
			if ( in_array( strtoupper( $type ), array( 'BINARY', 'VARBINARY', 'TINYBLOB', 'MEDIUMBLOB', 'BLOB', 'LONGBLOB' ) ) ) {
				$this->table_charset[ $tablekey ] = 'binary';
				return 'binary';
			}
		}

		// utf8mb3 is an alias for utf8.
		if ( isset( $charsets['utf8mb3'] ) ) {
			$charsets['utf8'] = true;
			unset( $charsets['utf8mb3'] );
		}

		// Check if we have more than one charset in play.
		$count = count( $charsets );
		if ( 1 === $count ) {
			$charset = key( $charsets );
		} elseif ( 0 === $count ) {
			// No charsets, assume this table can store whatever.
			$charset = false;
		} else {
			// More than one charset. Remove latin1 if present and recalculate.
			unset( $charsets['latin1'] );
			$count = count( $charsets );
			if ( 1 === $count ) {
				// Only one charset (besides latin1).
				$charset = key( $charsets );
			} elseif ( 2 === $count && isset( $charsets['utf8'], $charsets['utf8mb4'] ) ) {
				// Two charsets, but they're utf8 and utf8mb4, use utf8.
				$charset = 'utf8';
			} else {
				// Two mixed character sets. ascii.
				$charset = 'ascii';
			}
		}

		$this->table_charset[ $tablekey ] = $charset;
		return $charset;
	}

	/**
	 * Given a string, a character set and a table, ask the DB to check the string encoding.
	 * Classes that extend wpdb can override this function without needing to copy/paste
	 * all of wpdb::strip_invalid_text().
	 *
	 * NOTE: This must be called after LudicrousDB::db_connect, so that wpdb::dbh is set correctly
	 *
	 * @param string $string  String to convert
	 * @param string $charset Character set to test against (uses MySQL character set names)
	 *
	 * @return mixed The converted string, or a WP_Error if the conversion fails
	 */
	protected function strip_invalid_text_using_db( $string, $charset ) {
		$query = $this->prepare( "SELECT CONVERT( %s USING $charset )", $string );

		$result = $this->_do_query( $query, $this->dbh );
		if ( empty( $result ) ) {
			return new WP_Error( 'wpdb_convert_text_failure' );
		}

		if ( $this->use_mysqli ) {
			$row = mysqli_fetch_row( $result );
		} else {
			$row = mysql_fetch_row( $result );
		}

		if ( ! is_array( $row ) || count( $row ) < 1 ) {
			return new WP_Error( 'wpdb_convert_text_failure' );
		}

		return $row[0];
 	}
}

// class LudicrousDB
$wpdb = new LudicrousDB();

require( DB_CONFIG_FILE );
