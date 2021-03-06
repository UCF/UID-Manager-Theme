<?php
require_once 'functions/base.php';      // Base theme functions
require_once 'functions/feeds.php';     // Where functions related to feed data live
require_once 'custom-taxonomies.php';   // Where per theme taxonomies are defined
require_once 'custom-post-types.php';   // Where per theme post types are defined
require_once 'functions/config.php';    // Where per theme settings are registered
require_once 'functions/admin.php';     // Admin/login functions
require_once 'shortcodes.php';          // Per theme shortcodes
require_once 'third-party/gw-gravity-forms-notes-merge-tag.php'; // Gravity Wiz // Gravity Forms // Notes Merge Tag

/**
 * Conditionally displays a <h1> or <span> for the site's primary title
 * depending on the current page being viewed.
 **/
function display_site_title() {
	$elem = ( is_home() || is_front_page() ) ? 'h1' : 'span';
	ob_start();
?>
	<<?php echo $elem; ?> class="site-title">
		<a href="<?php echo bloginfo( 'url' ); ?>"><?php echo bloginfo( 'name' ); ?></a>
	</<?php echo $elem; ?>>
<?php
	return ob_get_clean();
}


/**
 * Authenticates the username/password combination with LDAP.
 *
 * @param string  $username The username to authenticate.
 * @param string  $password The password to authenticate.
 * @return obj or string returns error string or ldap object
 *
 * @author Brandon T. Groves
 */
function ldap_auth( $username, $password ) {
	$ldapbind = false;
	putenv('LDAPTLS_REQCERT=never');

	if ( $ldap_con=ldap_connect( 'ldaps://' . LDAP_HOST . ':' . LDAP_PORT ) ) {
		ldap_set_option($ldap_con, LDAP_OPT_PROTOCOL_VERSION, 3);
		ldap_set_option($ldap_con, LDAP_OPT_REFERRALS, 0);

		if( $ldapbind = ldap_bind( $ldap_con, $username . "@" . LDAP_DOMAIN, $password ) ) {
			if ( $user_obj = ldap_query( $ldap_con, $username ) ) {
				return $user_obj;
			} else {
				return 'role_error';
			}
		} else {
			return 'login_error';
		}
	} else {
		return 'conn_error';
	}
}


/**
 * LDAP Query to check to see if user is faculty or staff.
 *
 * @param string $ldap_con LDAP connection.
 * @param string $username The username to authenticate.
 * @return bool true if username is facutly or staff, otherwise false
 *
 * @author RJ Bruneel
 */
function ldap_query( $ldap_con, $username ) {
	$ldap_base_dn = 'OU=People,DC=net,DC=ucf,DC=edu';
	$search_filter = '(&(sAMAccountName=' . $username . ')(|(&(ucfPortalRole=CF_STAFF)(ucfPortalRole=FX_ENTERPRISE_EMAIL))(&(ucfPortalRole=CF_FACULTY)(ucfPortalRole=FX_ENTERPRISE_EMAIL))))';
	$result = ldap_search( $ldap_con, $ldap_base_dn, $search_filter );
	$info = ldap_get_entries($ldap_con, $result);

	return ( $info["count"] == 0 ) ? false : $result;
}


/**
 * Sets the session data for ldap authentication.
 *
 * @author Brandon T. Groves
 */
function ldap_set_session_data( $user ) {
	$timeout = 15 * 60;
	$_SESSION['timeout'] = time() + $timeout;
	$_SESSION['user'] = $user;
	$_SESSION['ip'] = $_SERVER['REMOTE_ADDR'];
}


/**
 * Returns true/false depending on if the current user is already authenticated
 * successfully against LDAP.
 **/
function ldap_is_authenticated() {
	return isset( $_SESSION['user'] ) && isset( $_SESSION['ip'] ) && $_SESSION['ip'] == $_SERVER['REMOTE_ADDR'];
}


/**
 * Returns true/false if the current user's LDAP session has expired.
 **/
function ldap_session_timed_out() {
	return isset( $_SESSION['timeout'] ) && $_SESSION['timeout'] < time();
}


/**
 * Destroys the session data for ldap authentication.
 *
 * @author Brandon T. Groves
 */
function ldap_destroy_session() {
	$_SESSION = array();
	session_destroy();
}


/**
 * Aborts loading a template if the user hasn't authenticated.
 * Intended for use at the top of individual template files, before
 * get_header().
 *
 * NOTE: this is probably the easiest solution for this site without knowing
 * ahead of time which templates need LDAP authentication.  If we end up
 * needing the entire site to be behind an authentication wall, hooking into
 * 'init' or 'after_setup_theme' might be better.
 **/
function ldap_required() {
	session_start();

	if ( isset( $_GET["logout"] ) ) {
		ldap_destroy_session();
	}

	$ldap_error = false;
	$ldap_obj = false;

	if ( ldap_session_timed_out() ) {
		ldap_destroy_session();
	}

	// Set session data and continue if the user is already authenticated or
	// authenticates successfully.  Else, load the login screen.
	if ( ldap_is_authenticated() ) {
		ldap_set_session_data( $_SESSION['user'] );
		session_write_close();
	}
	else if (
		isset( $_POST['uid-submit-auth'] )
		&& isset( $_POST['uid-username'] )
		&& strlen( $_POST['uid-username'] ) > 0
		&& isset( $_POST['uid-password'] )
		&& strlen( $_POST['uid-password'] ) > 0
	) {
		$ldap_auth = ldap_auth( $_POST['uid-username'], $_POST['uid-password'] );
		if (
			$ldap_auth && wp_verify_nonce( $_REQUEST['uid_auth_nonce'], 'uid-auth' )
		) {
			if( is_resource( $ldap_auth ) ) {
				$ldap_obj = $ldap_auth;
				ldap_set_session_data( $_POST['uid-username'] );
				session_write_close();
			} else {
				$ldap_error = $ldap_auth;
				require_once THEME_INCLUDES_DIR . '/ldap-login.php';
				die;
			}
		}
	}
	else {
		ldap_destroy_session();
		require_once THEME_INCLUDES_DIR . '/ldap-login.php';
		die;
	}
}


/**
 * Adds post meta data values as $post object properties for convenience.
 * Excludes WordPress' internal custom keys (prefixed with '_').
 **/
function attach_post_metadata_properties( $post ) {
	$metas = get_post_meta( $post->ID );
	foreach ( $metas as $key => $val ) {
		if ( substr( $key, 0, 1 ) !== '_' ) {
			$val = is_array( $val ) ? maybe_unserialize( $val[0] ) : maybe_unserialize( $val );
			$post->$key = $val;
		}
	}
	return $post;
}

function api_get_uils( $request ) {
	$posts = get_posts( array(
		'orderby' => 'title',
		'order'   => 'ASC',
		'post_type' => 'uil',
		'nopaging' => true,
		'posts_per_page' => -1,
		's' => $request->get_param( 's' )
	) );

	if ( empty( $posts ) ) {
		return null;
	}

	return $posts;
}

add_action( 'rest_api_init', function() {
	register_rest_route( 'rest', '/uils', array(
		'methods'  => 'GET',
		'callback' => 'api_get_uils'
	) );
} );

function get_amazon_url() {
	return AMAZON_AWS_URL . get_theme_mod_or_default( 'amazon_bucket' ) . "/" . get_theme_mod_or_default( 'amazon_folder' ) . "/" ;
}

function google_tag_manager() {
	ob_start();
	$gtm_id = get_theme_mod_or_default( 'ga_account' );
	if ( $gtm_id ) :
?>
<!-- Google Tag Manager -->
<noscript><iframe src="//www.googletagmanager.com/ns.html?id=<?php echo $gtm_id; ?>"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'//www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','<?php echo $gtm_id; ?>');</script>
<!-- End Google Tag Manager -->
<?php
	endif;
	return ob_get_clean();
}

// Template redirect, force ssl
function ucf_brand_force_ssl() {
	global $wp;

	if ( FORCE_SSL_ADMIN && ! is_ssl() ) {
		$url = home_url( $wp->request, 'https' );
		wp_redirect( $url, 301 );
	}
}

if ( FORCE_SSL_ADMIN && function_exists( 'ucf_brand_force_ssl' ) ) {
	add_action( 'template_redirect', 'ucf_brand_force_ssl' );
}

?>
