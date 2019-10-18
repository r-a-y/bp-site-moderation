<?php
/*
Plugin Name: BP Site Moderation
Description: Moderate new sites created by users. Requires BuddyPress.
Author: r-a-y
Author URI: http://profiles.wordpress.org/r-a-y
Network: true
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'bp_loaded', array( 'BP_Site_Moderation', 'init' ) );

/**
 * Moderates new sites created by users.
 *
 * When a new site is created by a user, this plugin hijacks the site creation
 * process and automatically archives the site using multisite's native
 * functions.  A super admin can then approve a site by going to the BP
 * sites directory and clicking on the new "Pending" tab.
 */
class BP_Site_Moderation {
	/**
	 * Meta key used to mark pending sites in moderation queue.
	 *
	 * @var string
	 */
	protected $key = 'bp_moderate_user_id';

	/**
	 * Activity scope for our sites loop.
	 *
	 * @var string
	 */
	protected $scope = 'pending';

	/**
	 * Static initializer.
	 */
	public static function init() {
		return new self();
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( ! is_multisite() ) {
			return;
		}

		if ( ! bp_is_active( 'blogs' ) ) {
			return;
		}

		if ( defined( 'BP_SITE_MODERATION_SLUG' ) ) {
			$this->scope = sanitize_title( constant( 'BP_SITE_MODERATION_SLUG' ) );
		}

		// multisite hooks
		add_action( 'wpmu_new_blog',  array( $this, 'autoarchive' ), 0, 2 );
		add_action( 'unarchive_blog', array( $this, 'on_approval' ) );
		add_action( 'delete_blog',    array( $this, 'on_delete' ) );
		add_filter( 'ms_site_check',  array( $this, 'frontend_message' ) );

		/* BP hooks */
		// main stuff
		add_action( 'bp_actions',                    array( $this, 'action_handler' ) );
		add_action( 'bp_screens',                    array( $this, 'screen_handler' ), 3 );
		add_action( 'bp_blogs_directory_blog_types', array( $this, 'add_site_directory_tab' ), 99 );
		add_filter( 'bp_after_has_blogs_parse_args', array( $this, 'catch_pending_scope' ) );
		add_filter( 'bp_blogs_get_blogs',            array( $this, 'modify_get_blogs' ), 10, 2 );

		// cosmetic stuff
		add_action( 'bp_before_blogs_loop',          array( $this, 'add_template_notices_to_sites_directory' ) );
		add_action( 'bp_before_create_blog_content', array( $this, 'add_text_overrides' ) );
		add_action( 'bp_after_create_blog_content',  array( $this, 'remove_text_overrides' ) );
		add_action( 'wp_logout',                     array( $this, 'reset_blogs_scope_cookie' ) );

		// do what you feel like!
		do_action( 'bp_site_moderation_loaded', $this );
	}

	/** MULTISITE HOOKS ****************************************************/

	/**
	 * Auto-archive newly-created sites.
	 *
	 * This would have been a non-BuddyPress plugin if multisite recorded the user
	 * ID in the wp_blogs table or offered a blogmeta table like BuddyPress has...
	 *
	 * Skip check for super admins.
	 */
	public function autoarchive( $blog_id, $user_id ) {
		// do not archive sites created by super admins
		if ( is_super_admin( $user_id ) || is_super_admin( bp_loggedin_user_id() ) ) {
			return;
		}

		// archive the site
		update_archived( $blog_id, 1 );

		// save the user ID since we won't be able to grab this data later on without
		// having to do some DB queries
		bp_blogs_update_blogmeta( $blog_id, $this->key, $user_id );

		// add the user ID as an option on the site in question as well due to object
		// cache issues with using BP blogmeta functions on sub-sites
		update_blog_option( $blog_id, $this->key, $user_id );

		// do not record site in BP's blog table yet
		add_filter( 'bp_blogs_is_blog_recordable_for_user', '__return_false' );

		// send email to super admin
		$send_notification = get_site_option( 'registrationnotification' ) == 'yes';

		// disable the regular registration notification email
		remove_action( 'wpmu_new_blog', 'newblog_notify_siteadmin', 10, 2 );

		if ( false === (bool) $send_notification ) {
			return;
		}

		$email = get_site_option( 'admin_email' );
		if ( is_email( $email ) == false ) {
			return;
		}

		$blogname = get_blog_option( $blog_id, 'blogname' );

		wp_mail(
			$email,
			bp_get_email_subject( array(
				'text' => sprintf(
					__( 'New site - %s - added to moderation queue', 'bp-site-moderation' ),
					$blogname
				)
			) ),
			sprintf(
				__( 'Hi,

A new site titled, %1$s, was just created at:
%2$s

The user who created the site is %3$s:
%4$s

You can approve this site by logging in and going to:
%5$s

Or, to manage submissions in bulk, click on the following link and look for sites labelled "Archived":
%6$s

To disable these notifications, click on the following link and uncheck "Registration notification":
%7$s', 'bp-site-moderation' ),
				$blogname,
				get_home_url( $blog_id ),
				bp_core_get_username( $user_id ),
				bp_core_get_user_domain( $user_id ),
				bp_get_blogs_directory_permalink() . "{$this->scope}/",
				network_admin_url( 'sites.php' ),
				network_admin_url( 'settings.php' )
			)
		);
	}

	/**
	 * When a pending site is approved, do some clean-up stuff.
	 *
	 * By 'approved', we really mean 'unarchive' in multisite lingo.  Then, we
	 * remove BP's blogmeta markers, record the blog in BP, and send an email to
	 * the blog creator.
	 *
	 * @param int $blog_id The blog ID
	 */
	public function on_approval( $blog_id ) {
		$user_id = $this->get_blog_creator_id( $blog_id );

		if ( empty( $user_id ) ) {
			return;
		}

		// remove pending key
		bp_blogs_delete_blogmeta( $blog_id, $this->key );
		delete_blog_option( $blog_id, $this->key );

		// record entry in BP's blogs table
		bp_blogs_record_blog( $blog_id, $user_id );

		// don't send email to spammer
		if ( bp_is_user_spammer( $user_id ) ) {
			return;
		}

		// send email to blog creator
		$user = new WP_User( $user_id );

		$blogname = get_blog_option( $blog_id, 'blogname' );

		wp_mail(
			$user->user_email,
			bp_get_email_subject( array(
				'text' => sprintf(
					__( 'Your site - %s - is approved', 'bp-site-moderation' ),
					$blogname
				)
			) ),
			sprintf(
				__( 'Hi %1$s,

Your site, %2$s, has been approved.

You are now able to login at your site\'s dashboard:
%3$s

Use the same user account you used to register with our site.

Start creating!  If you have any other questions, please feel free to contact us.', 'bp-site-moderation' ),
				bp_core_get_user_displayname( $user_id ),
				$blogname,
				get_admin_url( $blog_id )
			)
		);
	}

	/**
	 * When a pending site is deleted, send decline email to user.
	 *
	 * @param int $blog_id
	 */
	public function on_delete( $blog_id ) {
		$user_id = $this->get_blog_creator_id( $blog_id );

		if ( empty( $user_id ) ) {
			return;
		}

		$user = new WP_User( $user_id );

		// send email to blog creator if not a spammer
		if ( ! bp_is_user_spammer( $user_id ) && true === (bool) apply_filters( 'bpsm_send_decline_email_to_user', true ) ) {
			$blogname = get_blog_option( $blog_id, 'blogname' );

			wp_mail(
				$user->user_email,
				bp_get_email_subject( array(
					'text' => sprintf(
						__( 'Your site submission - %s - is declined', 'bp-site-moderation' ),
						$blogname
					)
				) ),
				sprintf(
					__( 'Hi %1$s,

Your site submission, %2$s (%3$s), was declined by the site administrator.

If you have questions about this, please feel free to contact us.', 'bp-site-moderation' ),
					bp_core_get_user_displayname( $user_id ),
					$blogname,
					get_home_url( $blog_id )
				)
			);

		}
	}

	/**
	 * When a user visits a pending moderation site, display a custom message.
	 *
	 * @param null $retval Default value for site checks. Overriden by this method.
	 */
	public function frontend_message( $retval ) {
		$blog = get_blog_details();

		if ( false === $this->is_pending( $blog->blog_id ) ) {
			return $retval;
		}

		// don't block super admins!
		if ( is_super_admin() ) {
			return $retval;
		}

		wp_die( __( 'This site is pending moderation by a site administrator.', 'bp-site-moderation' ), __( 'Site Temporarily Unavailable', 'bp-site-moderation' ), array( 'response' => 410 ) );
	}

	/** BP HOOKS ***********************************************************/

	/**
	 * Action handler when the 'Approve' button is clicked.
	 */
	public function action_handler() {
		if ( empty( $_GET['blog_id'] ) || ! is_user_logged_in() || ! is_super_admin() ) {
			return;
		}

		$action = false;

		if ( ! empty( $_GET['bpsm-approve'] ) || ! empty( $_GET['bpsm-decline'] ) ) {
			$nonce   = ! empty( $_GET['bpsm-approve'] ) ? $_GET['bpsm-approve'] : $_GET['bpsm-decline'];
			$action  = ! empty( $_GET['bpsm-approve'] ) ? 'approve' : 'decline';
		}

		if ( ! $action ) {
			return;
		}

		if ( ! wp_verify_nonce( $nonce, "bp_site_moderation_{$action}" ) ) {
			return;
		}

		$blog_id = (int) $_GET['blog_id'];
		$blogname = get_blog_option( $blog_id, 'blogname' );

		// approve
		if ( 'approve' === $action ) {
			// remove site from moderation queue
			update_archived( $blog_id, 0 );

			// add feedback message
			$message = sprintf( __( 'You just approved the site, %s', 'bp-site-moderation' ), $blogname );

		// decline
		} else {
			// need multisite admin functions file
			if ( ! function_exists( 'wpmu_delete_blog' ) ) {
				require ABSPATH . 'wp-admin/includes/ms.php';
			}

			// delete site entirely
			wpmu_delete_blog( $blog_id, true );

			// add feedback message
			$message = sprintf( __( 'You just deleted the site, %s', 'bp-site-moderation' ), $blogname );
		}

		bp_core_add_message( $message );

		// for redirect, fallback to the sites directory if no referer
		$redirect = wp_get_referer() ? wp_get_referer() : bp_get_blogs_directory_permalink();
		bp_core_redirect( $redirect );
		exit();
	}

	/**
	 * Screen handler to enable the 'Pending' tab on the Sites directory.
	 */
	public function screen_handler() {
		if ( ! is_multisite() || ! is_user_logged_in() || ! bp_is_blogs_component() || ! bp_is_current_action( $this->scope ) ) {
			return;
		}

		if ( ! is_super_admin() ){
			bp_core_redirect( bp_get_blogs_directory_permalink() );
			exit();
		}

		// if we have a post value already, let's add our scope to the existing cookie value
		if ( ! empty( $_POST['cookie'] ) ) {
			$_POST['cookie'] .= "%3B%20bp-blogs-scope%3D{$this->scope}";
		} else {
			$_POST['cookie'] = "bp-blogs-scope%3D{$this->scope}";
		}

		// set the activity scope by faking an ajax request (loophole!)
		if ( ! defined( 'DOING_AJAX' ) ) {
			// reset the selected tab
			@setcookie( 'bp-blogs-scope', $this->scope, 0, '/' );

			//reset the dropdown menu to active
			@setcookie( 'bp-blogs-filter', 'active', 0, '/' );
		}

		// this is a dirty hack for theme compat to work...
		// interested in what i'm doing? check out:
		// https://buddypress.trac.wordpress.org/browser/tags/2.1.1/src/bp-blogs/bp-blogs-screens.php#L92
		if ( bp_use_theme_compat_with_current_theme() ) {
			buddypress()->current_action = '';
		} else {
			do_action( 'bp_blogs_screen_index' );
		}

		bp_core_load_template( apply_filters( 'bp_blogs_screen_index', 'blogs/index' ) );
	}

	/**
	 * Resets BP's blogs scope cookie to 'all' during logout.
	 *
	 * Only reset if the blogs scope is set to 'pending' since the 'Pending' tab
	 * will not be available when logged out.
	 */
	public function reset_blogs_scope_cookie() {
		if ( ! empty( $_COOKIE['bp-blogs-scope'] ) && $this->scope === $_COOKIE['bp-blogs-scope'] ) {
			@setcookie( 'bp-blogs-scope', 'all', 0, '/' );
		}
	}

	/**
	 * Add a "Pending" tab on the sites directory page only for super admins.
	 */
	public function add_site_directory_tab() {
		if ( ! is_super_admin() ) {
			return;
		}

		$scope = esc_attr( $this->scope );
	?>

		<li id="blogs-<?php echo $scope; ?>"><a href="<?php bp_blogs_directory_permalink() . '/<?php echo $scope; ?>/'; ?>"><?php _e( 'Pending', 'bp-site-moderation' ); ?></a></li>

	<?php
	}

	/**
	 * See if our "Pending" site scope is in action.
	 *
	 * If so, set an internal flag to keep track.
	 *
	 * @param array $r Arguments from bp_has_blogs() loop.
	 * @return array
	 */
	public function catch_pending_scope( $r ) {
		if ( empty( $r['scope'] ) ) {
			return $r;
		}

		if ( $this->scope === $r['scope'] && is_super_admin() ) {
			$this->do_modify_blogs = true;
		}

		return $r;
	}

	/**
	 * Fetch our pending blogs and override the existing blogs in the blogs loop.
	 */
	public function modify_get_blogs( $retval, $r ) {
		global $wpdb;

		if ( empty( $this->do_modify_blogs ) ) {
			return $retval;
		}

		// Make sure we fetch all pending sites
		$r['user_id'] = 0;

		// remove all buttons in blogs loop
		remove_all_actions( 'bp_directory_blogs_actions' );

		// customize the blog loop
		add_action( 'bp_directory_blogs_actions', array( $this, 'blogs_loop_right_side' ) );
		add_action( 'bp_directory_blogs_item',    array( $this, 'add_site_url_to_loop' ) );
		add_filter( 'bp_get_blog_permalink',      array( $this, 'modify_blogs_loop_permalink' ) );
		add_filter( 'bp_get_blog_avatar',         array( $this, 'toggle_blog_permalink' ) );

		return $this->get(
			$r['type'],
			$r['per_page'],
			$r['page'],
			$r['user_id'],
			$r['search_terms'],
			$r['update_meta_cache']
		);
	}


	/**
	 * Modify right side of blogs loop.
	 *
	 * - Add 'Approve' / Decline buttons to blogs loop.
	 * - Add 'Created by X' line
	 */
	public function blogs_loop_right_side() {
		$this->add_button( 'approve' );
		$this->add_button( 'decline' );

		global $blogs_template;
	?>

		<span class="item-site-creator" style="font-size:90%;"><?php printf( __( 'Created by %s', 'bp-site-moderation' ), '<a href="' . bp_core_get_user_domain( $blogs_template->blog->admin_user_id ) . '">' . bp_core_get_username( $blogs_template->blog->admin_user_id )  . '</a>' ); ?></span>

	<?php
	}

	/**
	 * Set a flag to determine whether the blog avatar is rendering.
	 *
	 * This is done so we can to determine whether we should use the user profile
	 * link or network admin edit link when overriding the blog permalink.
	 *
	 * @param string $retval Blog avatar.
	 */
	public function toggle_blog_permalink( $retval ) {
		$this->did_user_avatar = true;
		return $retval;
	}

	/**
	 * Switch blog permalink to user profile link or network admin edit site link.
	 *
	 * @param string $retval The blog permalink
	 * @return string
	 */
	public function modify_blogs_loop_permalink( $retval ) {
		// switch to user profile link on first instance
		if ( empty( $this->did_user_avatar ) ) {
			global $blogs_template;
			$this->did_user_avatar = true;
			return bp_core_get_user_domain( $blogs_template->blog->admin_user_id );

		// switch to network admin edit site link on second instance
		} else {
			$this->did_user_avatar = false;
			return network_admin_url( 'site-info.php?id=' . bp_get_blog_id() );
		}
	}

	/**
	 * Adds the site URL to the blogs loop.
	 *
	 * This is so the super admin can clearly see the URL.
	 */
	public function add_site_url_to_loop() {
	?>

		<span class="item-site-url" style="font-size:90%;"><a href="<?php echo get_home_url( bp_get_blog_id() ); ?>"><?php echo get_home_url( bp_get_blog_id() ); ?></a></span>

	<?php
	}

	/**
	 * BP bug - need to add 'template_notices' hook to sites directory.
	 */
	public function add_template_notices_to_sites_directory() {
		if ( ! did_action( 'template_notices' ) ) {
			do_action( 'template_notices' );
		}
	}

	/** GETTEXT ************************************************************/

	public function gettext_overrides( $translated_text = '', $untranslated_text = '', $domain = '' ) {
		switch ( $untranslated_text ) {
			case 'Congratulations! You have successfully registered a new site.' :
				$translated_text = __( 'Congratulations!', 'bp-site-moderation' );
				break;

			case '%s is your new site.' :
				$translated_text = sprintf( __( 'Your site request has been submitted and will be reviewed shortly. While you wait, why not <a href="%1$s">update your avatar</a> or <a href="%2$s">explore some recent blog posts</a>.', 'bp-site-moderation' ),
					esc_url( bp_loggedin_user_domain() ),
					esc_url( bp_get_activity_directory_permalink() )
				);
				break;

			case '<a href="%1$s">Log in</a> as "%2$s" using your existing password.' :
				$translated_text = '';
				break;
		}

		return $translated_text;
	}

	public function add_text_overrides() {
		add_filter( 'gettext', array( $this, 'gettext_overrides' ), 10, 3 );
	}

	public function remove_text_overrides() {
		remove_filter( 'gettext', array( $this, 'gettext_overrides' ), 10, 3 );
	}

	/** HELPERS ************************************************************/

	/**
	 * Helper function to determine if a site is pending moderation.
	 *
	 * Basically, an alias for the get_blog_creator_id() method.
	 *
	 * @param int $blog_id The blog ID to check.
	 * @return bool
	 */
	public function is_pending( $blog_id = 0 ) {
		return (bool) $this->get_blog_creator_id( $blog_id );
	}

	/**
	 * Helper function to grab the user ID of the pending site creator.
	 *
	 * @param int $blog_id The blog ID to check.
	 * @return bool
	 */
	public function get_blog_creator_id( $blog_id = 0 ) {
		$user_id = get_blog_option( $blog_id, $this->key );

		if ( empty( $user_id ) ) {
			return 0;
		}

		return $user_id;
	}

	/**
	 * Fetch pending sites.
	 *
	 * Sucks that we have to duplicate BP_Blogs_Blog::get(), but it's necessary
	 * b/c we need to modify the DB query.
	 */
	public function get( $type, $limit = false, $page = false, $user_id = 0, $search_terms = false, $update_meta_cache = true, $include_blog_ids = false ) {
		global $bp, $wpdb;

		$pag_sql = ( $limit && $page ) ? $wpdb->prepare( " LIMIT %d, %d", intval( ( $page - 1 ) * $limit), intval( $limit ) ) : '';

		// altered to use the correct user ID for our implementation of get()
		$user_sql = !empty( $user_id ) ? $wpdb->prepare( " AND b.meta_value = %d", $user_id ) : '';

		if ( ! empty( $search_terms ) ) {
			$search_terms_sql = $wpdb->prepare( ' AND bm2.meta_value LIKE %s', '%' . bp_esc_like( $search_terms ) . '%' );
		} else {
			$search_terms_sql = '';
		}

		switch ( $type ) {
			case 'active': default:
				$order_sql = "ORDER BY bm.meta_value DESC";
				break;
			case 'alphabetical':
				$order_sql = "ORDER BY bm2.meta_value ASC";
				break;
			case 'newest':
				$order_sql = "ORDER BY wb.registered DESC";
				break;
			case 'random':
				$order_sql = "ORDER BY RAND()";
				break;
		}

		$sql = array();

		// this is exactly the same as before
		$sql['select'] = "SELECT b.blog_id, b.meta_value as admin_user_id, u.user_email as admin_user_email, wb.domain, wb.path, bm.meta_value as last_activity, bm2.meta_value as name";

		// switched b alias from {$bp->blogs->table_name} to {$bp->blogs->table_name_blogmeta}
		$sql['from'] = "FROM {$bp->blogs->table_name_blogmeta} b, {$bp->blogs->table_name_blogmeta} bm, {$bp->blogs->table_name_blogmeta} bm2, {$wpdb->base_prefix}blogs wb, {$wpdb->users} u";

		// removed wb.archived clause (we need to check for archived blogs)
		// removed $include_sql (not necessary)
		// removed GROUP BY clause (interfered with total count)
		$sql['where'] = "WHERE b.blog_id = wb.blog_id AND b.meta_value = u.ID AND b.blog_id = bm.blog_id AND b.blog_id = bm2.blog_id {$user_sql} AND wb.spam = 0 AND wb.mature = 0 AND wb.deleted = 0 AND b.meta_key = '{$this->key}' AND bm.meta_key = 'last_activity' AND bm2.meta_key = 'name' {$search_terms_sql} {$user_sql} {$order_sql} {$pag_sql}";

		// get paged blogs
		$paged_blogs_sql = implode( ' ', $sql );
		$paged_blogs = $wpdb->get_results( $paged_blogs_sql );

		// formulate the total blogs count SQL from the paged blogs SQL
		$total_blogs_sql = str_replace( "b.blog_id, b.meta_value as admin_user_id, u.user_email as admin_user_email, wb.domain, wb.path, bm.meta_value as last_activity, bm2.meta_value as name", 'COUNT(DISTINCT b.blog_id)', $paged_blogs_sql );
		$total_blogs_sql = str_replace( $order_sql, '', $total_blogs_sql );

		if ( ! empty( $pag_sql ) ) {
			$total_blogs_sql = str_replace( $pag_sql, '', $total_blogs_sql );
		}

		$total_blogs = $wpdb->get_var( $total_blogs_sql );

		$blog_ids = array();
		foreach ( (array) $paged_blogs as $blog ) {
			$blog_ids[] = (int) $blog->blog_id;
		}

		// commented this out b/c it's unnecessary for our needs
		//$paged_blogs = BP_Blogs_Blog::get_blog_extras( $paged_blogs, $blog_ids, $type );

		if ( $update_meta_cache ) {
			bp_blogs_update_meta_cache( $blog_ids );
		}

		return array( 'blogs' => $paged_blogs, 'total' => $total_blogs );
	}

	/**
	 * Helper method to generate either an "Approve" or "Decline" button.
	 *
	 * @param string $type Either 'approve' or 'decline'.
	 */
	public function add_button( $type = 'approve' ) {
		$r = array(
			'id'            => "bpsm-{$type}",
			'component'     => 'blogs',
			'link_text'     => 'approve' == $type ? __( 'Approve', 'bp-site-moderation' ) : __( 'Decline', 'bp-site-moderation' ),
			'wrapper_class' => 'blog-button',
			'link_class'    => 'blog-button',
		);

		// BP Nouveau-specific button arguments.
		if ( function_exists( 'bp_nouveau' ) ) {
			$r['parent_element'] = 'li';
			$r['wrapper_class']  = '';
			$r['link_class']    .= ' button';
		}

		if ( 'decline' === $type ) {
			$r['link_class'] .= ' confirm';
		}

		$r['link_href'] = wp_nonce_url(
			add_query_arg( 'blog_id', bp_get_blog_id(), home_url( '/' ) ),
			"bp_site_moderation_{$type}",
			"bpsm-{$type}"
		);

		// Output button
		bp_button( apply_filters( 'bp_site_moderation_button_args', $r ) );
	}
}