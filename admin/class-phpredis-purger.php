<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://rtcamp.com/nginx-helper/
 * @since      2.0.0
 *
 * @package    nginx-helper
 * @subpackage nginx-helper/admin
 */

/**
 * Description of PhpRedis_Purger
 *
 * @package    nginx-helper
 * @subpackage nginx-helper/admin
 * @author     rtCamp
 */
class PhpRedis_Purger extends Purger {

	/**
	 * PHP Redis api object.
	 *
	 * @since    2.0.0
	 * @access   public
	 * @var      string    $redis_object    PHP Redis api object.
	 */
	public $redis_object;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    2.0.0
	 */
	public function __construct() {

		global $nginx_helper_admin;

		try {

			$this->redis_object = new Redis();
			$this->redis_object->connect(
				$nginx_helper_admin->options['redis_hostname'],
				$nginx_helper_admin->options['redis_port'],
				5
			);

		} catch ( Exception $e ) {
			$this->log( $e->getMessage(), 'ERROR' );
		}

	}

	/**
	 * Purge all cache.
	 */
	public function purge_all() {

		global $nginx_helper_admin;

		$prefix = trim( $nginx_helper_admin->options['redis_prefix'] );

		$this->log( '* * * * *' );

		// If Purge Cache link click from network admin then purge all.
		if ( is_network_admin() ) {

			$total_keys_purged = $this->delete_keys_by_wildcard( $prefix . '*' );
			$this->log( '* Purged Everything! * ' );

		} else { // Else purge only site specific cache.

			$parse             = wp_parse_url( get_home_url() );
			$parse['path']     = empty( $parse['path'] ) ? '/' : $parse['path'];
			$total_keys_purged = $this->delete_keys_by_wildcard( $prefix . $parse['scheme'] . 'GET' . $parse['host'] . $parse['path'] . '*' );
			$this->log( '* ' . get_home_url() . ' Purged! * ' );

		}

		if ( $total_keys_purged ) {
			$this->log( "Total {$total_keys_purged} urls purged." );
		} else {
			$this->log( 'No Cache found.' );
		}

		$this->log( '* * * * *' );

	}

	/**
	 * Purge url.
	 *
	 * @param string $url URL to purge.
	 * @param bool   $feed Feed or not.
	 */
	public function purge_url( $url, $feed = true ) {

		global $nginx_helper_admin;

		$parse = wp_parse_url( $url );

		if ( ! isset( $parse['path'] ) ) {
			$parse['path'] = '';
		}

		$prefix          = $nginx_helper_admin->options['redis_prefix'];
		$_url_purge_base = $prefix . $parse['scheme'] . 'GET' . $parse['host'] . $parse['path'];
		$is_purged       = $this->delete_single_key( $_url_purge_base );

		if ( $is_purged ) {
			$this->log( '- Purged URL | ' . $url );
		} else {
			$this->log( '- Cache Not Found | ' . $url, 'ERROR' );
		}

		$this->log( '* * * * *' );

	}

	/**
	 * Custom purge urls.
	 */
	public function custom_purge_urls() {

		global $nginx_helper_admin;

		$parse           = wp_parse_url( site_url() );
		$prefix          = $nginx_helper_admin->options['redis_prefix'];
		$_url_purge_base = $prefix . $parse['scheme'] . 'GET' . $parse['host'];

		$purge_urls = isset( $nginx_helper_admin->options['purge_url'] ) && ! empty( $nginx_helper_admin->options['purge_url'] ) ?
			explode( "\r\n", $nginx_helper_admin->options['purge_url'] ) : array();

		/**
		 * Allow plugins/themes to modify/extend urls.
		 *
		 * @param array $purge_urls URLs which needs to be purged.
		 * @param bool  $wildcard   If wildcard in url is allowed or not. default true.
		 */
		$purge_urls = apply_filters( 'rt_nginx_helper_purge_urls', $purge_urls, true );

		if ( is_array( $purge_urls ) && ! empty( $purge_urls ) ) {

			foreach ( $purge_urls as $purge_url ) {

				$purge_url = trim( $purge_url );

				if ( strpos( $purge_url, '*' ) === false ) {

					$status    = $this->delete_single_key( $_url_purge_base . $purge_url );

					if ( $status ) {
						$this->log( '- Purge URL | ' . $parse['scheme'] . '://' . $parse['host'] . $purge_url );
					} else {
						$this->log( '- Cache Not Found | ' . $parse['scheme'] . '://' . $parse['host'] . $purge_url, 'ERROR' );
					}

				} else {

					$status    = $this->delete_keys_by_wildcard( $_url_purge_base . $purge_url );

					if ( $status ) {
						$this->log( '- Purge Wild Card URL | ' . $parse['scheme'] . '://' . $parse['host'] . $purge_url . ' | ' . $status . ' url purged' );
					} else {
						$this->log( '- Cache Not Found | ' . $parse['scheme'] . '://' . $parse['host'] . $purge_url, 'ERROR' );
					}

				}

			}

		}

	}

	/**
	 * Single Key Delete Example
	 * e.g. $key can be nginx-cache:httpGETexample.com/
	 *
	 * @param string $key Key.
	 *
	 * @return int
	 */
	public function delete_single_key( $key ) {

		try {
			return $this->redis_object->del( $key );
		} catch ( Exception $e ) {
			$this->log( $e->getMessage(), 'ERROR' );
		}

	}

	/**
	 * Delete Keys by wildcard.
	 * e.g. $key can be nginx-cache:httpGETexample.com*
	 *
	 * Lua Script block to delete multiple keys using wildcard
	 * Script will return count i.e. number of keys deleted
	 * if return value is 0, that means no matches were found
	 *
	 * Call redis eval and return value from lua script
	 *
	 * @param string $pattern pattern.
	 *
	 * @return mixed
	 */
	public function delete_keys_by_wildcard( $pattern ) {

		// Lua Script.
		$lua = <<<LUA
local k =  0
for i, name in ipairs(redis.call('KEYS', KEYS[1]))
do
    redis.call('DEL', name)
    k = k+1
end
return k
LUA;

		try {
			return $this->redis_object->eval( $lua, array( $pattern ), 1 );
		} catch ( Exception $e ) {
			$this->log( $e->getMessage(), 'ERROR' );
		}

	}

}
