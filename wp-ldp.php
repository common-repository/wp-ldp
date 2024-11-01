<?php
/**
 * The WP-LDP Plugin main file
 * @package     WPLDP
 * @author      Benoit Alessandroni
 * @copyright   2017 Assemblee Virtuelle
 * @license     GPL-v2.0+
 *
 * @wordpress-plugin
 * Plugin Name: WP LDP
 * Plugin URI: https://github.com/assemblee-virtuelle/wpldp
 * Description: This is a plugin which aims to emulate the default caracteristics of a Linked Data Platform compatible server
 * Text Domain: wpldp
 * Version: 2.0.7
 * Author: Sylvain LE BON, Benoit ALESSANDRONI
 * Author URI: http://www.happy-dev.fr/team/sylvain, http://benoit-alessandroni.fr/
 * License: GPL2
 */

namespace WpLdp;

defined( 'ABSPATH' ) || die( 'No script kiddies please!' );

require_once( 'class-utils.php' );
require_once( 'class-container-taxonomy.php' );
require_once( 'class-site-taxonomy.php' );
require_once( 'class-settings.php' );
require_once( 'class-api.php' );

if ( ! class_exists( '\WpLdp\WpLdp' ) ) {
	/**
	 * Handles everything related to the resource post type.
	 *
	 * @category Class
	 * @package WPLDP
	 * @author  Benoit Alessandroni
	 * @license https://www.gnu.org/licenses/gpl-2.0.txt GNU/GPLv2
	 */
	class WpLdp {

		/**
		 * The front page url, defaulted as 'wp-ldp/front'.
		 */
		const FRONT_PAGE_URL = 'wp-ldp/front';

		/*
		 * The resource post type name.
		 */
		const RESOURCE_POST_TYPE = 'ldp_resource';

		/**
		 * @var $version_number The current plugin version number.
		 */
		protected static $version_number = '2.0.7';

		/**
		 * __construct - Default constructor.
		 *
		 * @return {WpLdp}  instance of the object.
		 */
		public function __construct() {
			register_activation_hook( __FILE__, array( $this, 'wpldp_rewrite_flush' ) );
			register_deactivation_hook( __FILE__, array( $this, 'wpldp_flush_rewrite_rules_on_deactivation' ) );

			register_activation_hook( __FILE__, array( $this, 'generate_menu_item' ) );
			register_deactivation_hook( __FILE__, array( $this, 'remove_menu_item' ) );

			// Entry point of the plugin.
			add_action( 'init', array( $this, 'wpldp_plugin_update' ) );
			add_action( 'init', array( $this, 'load_translations_file' ) );
			add_action( 'init', array( $this, 'create_ldp_type' ) );
			add_action( 'init', array( $this, 'add_poc_rewrite_rule' ) );

			add_action( 'edit_form_advanced', array( $this, 'wpldp_edit_form_advanced' ) );
			add_action( 'save_post', array( $this, 'save_ldp_meta_for_post' ) );

			add_action( 'add_meta_boxes', array( $this, 'display_container_meta_box' ) );
			add_action( 'add_meta_boxes', array( $this, 'display_media_meta_box' ) );

			add_filter( 'post_type_link', array( $this, 'ldp_resource_post_link' ), 10, 3 );

			add_action( 'admin_enqueue_scripts', array( $this, 'ldp_enqueue_stylesheet' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'ldp_enqueue_script' ) );

			add_action( 'wp_enqueue_scripts', array( $this, 'wpldpfront_enqueue_stylesheet' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'wpldpfront_enqueue_script' ) );
		}


		/**
		 * Automatic database upgrade mechanism, planned for the future.
		 *
		 * @return void
		 */
		function wpldp_plugin_update() {
			$plugin_version = get_option( 'wpldp_version' );
			$update_option = null;

			if ( self::$version_number !== $plugin_version ) {
				if ( self::$version_number >= '1.1.0' ) {
					// Force reinitializing the ldp containers models.
					global $wpldp_settings;
					if ( ! empty( $wpldp_settings ) ) {
						$wpldp_settings->initialize_container( true );
					}

					$actor_term = get_term_by( 'slug', 'actor', 'ldp_container' );
					$person_term = get_term_by( 'slug', 'person', 'ldp_container' );
					if ( ! empty( $actor_term ) && ! is_wp_error( $actor_term ) ) {
						wp_delete_term(
							$actor_term->term_id,
							'ldp_container',
							array(
								'default' => $person_term->term_id,
							)
						);
					}

					$project_term = get_term_by( 'slug', 'project', 'ldp_container' );
					$initiative_term = get_term_by( 'slug', 'initiative', 'ldp_container' );
					if ( ! empty( $project_term ) && ! is_wp_error( $project_term ) ) {
						wp_delete_term(
							$project_term->term_id,
							'ldp_container',
							array(
								'default' => $initiative_term->term_id,
							)
						);
					}

					$resource_term = get_term_by( 'slug', 'resource', 'ldp_container' );
					if ( ! empty( $resource_term ) && ! is_wp_error( $resource_term ) ) {
						wp_delete_term(
							$resource_term->term_id,
							'ldp_container'
						);
					}

					$idea_term = get_term_by( 'slug', 'idea', 'ldp_container' );
					if ( ! empty( $idea_term ) && ! is_wp_error( $idea_term ) ) {
						wp_delete_term(
							$idea_term->term_id,
							'ldp_container'
						);
					}
				}

				if ( self::$version_number > $plugin_version ) {
					$update_option = $this->wpldp_db_upgrade();
				}
			}

			update_option( 'wpldp_version', self::$version_number );
		}

		/**
		 * Executes desired database upgrade.
		 *
		 * @return {bool} Is this a success ?
		 */
		private function wpldp_db_upgrade() {
			$flush_cache = wp_cache_flush();
			global $wpdb;
			$wpdb->query(
				"UPDATE $wpdb->postmeta
				SET `meta_key` = replace( `meta_key` , 'ldp_', '' );"
			);

			$wpdb->query(
				"DELETE FROM $wpdb->options
				WHERE `option_name` LIKE '%transient%';"
			);

			$result = $wpdb->get_results(
				"SELECT `option_name`
				FROM $wpdb->options
				WHERE `option_name` LIKE '%ldp_container_%';"
			);

			foreach ( $result as $current ) {
				$option = get_option( $current->option_name );
				if ( ! empty( $option ) ) {
					if ( ! empty( $option['ldp_model'] ) ) {
						$option['ldp_model'] = str_replace( 'ldp_', '', $option['ldp_model'] );
					}

					if ( ! empty( $option['ldp_included_fields_list'] ) ) {
						$option['ldp_included_fields_list'] = str_replace( 'ldp_', '', $option['ldp_included_fields_list'] );
					}
					update_option( $current->option_name, $option, false );
				}
			}

			$flush_cache = wp_cache_flush();
			return true;
		}


		/**
		 * Loads proper text domain.
		 *
		 * @return void
		 */
		function load_translations_file() {
			$path        = dirname( plugin_basename( __FILE__ ) ) . '/languages';
			load_plugin_textdomain( 'wpldp', "", $path );
			load_theme_textdomain( 'wpldp', $path );
		}


		/**
		 * Rewrites rule for accessing the Proof of concept page.
		 *
		 * @return void
		 */
		public function add_poc_rewrite_rule() {
			global $wp_rewrite;
			$poc_url = plugins_url( 'public/index.php', __FILE__ );
			$poc_url = substr( $poc_url, strlen( home_url() ) + 1 );
			$wp_rewrite->add_external_rule( '([_0-9a-zA-Z-]+/)?' . Wpldp::FRONT_PAGE_URL, $poc_url );
		}

		/**
		 * Adds custom LDP Resource post type.
		 *
		 * @return void
		 */
		public function create_ldp_type() {
			register_post_type( 'ldp_resource',
				array(
					'labels'  => array(
						'name'               => __( 'Resources', 'wpldp' ),
						'singular_name'      => __( 'Resource', 'wpldp' ),
						'all_items'          => __( 'All resources', 'wpldp' ),
						'add_new_item'       => __( 'Add a resource', 'wpldp' ),
						'edit_item'          => __( 'Edit a resource', 'wpldp' ),
						'new_item'           => __( 'New resource', 'wpldp' ),
						'view_item'          => __( 'See the resource', 'wpldp' ),
						'search_items'       => __( 'Search for a resource', 'wpldp' ),
						'not_found'          => __( 'No corresponding resource', 'wpldp' ),
						'not_found_in_trash' => __( 'No corresponding resource in the trash', 'wpldp' ),
						'add_new'            => __( 'Add a resource', 'wpldp' ),
					),
					'description'           => __( 'LDP Resource', 'wpldp' ),
					'public'                => true,
					'show_in_nav_menu'      => true,
					'show_in_menu'          => true,
					'show_in_admin_bar'     => true,
					'supports'              => array(
						'title',
					),
					'has_archive'           => true,
					'rewrite'               => array(
						'slug' => \WpLdp\Api::LDP_API_URL . '%ldp_container%',
					),
					'menu_icon'             => 'dashicons-image-filter',
				)
			);
		}

		/**
		 * Adds custom filter for handling the custom permalink.
		 *
		 * @param {string} $post_link The current post link.
		 * @param {int}    $id The current post ID, if defined.
		 * @return {string} $post_link The actual post linl.
		 */
		function ldp_resource_post_link( $post_link, $id = 0 ) {
			$post = get_post( $id );

			if ( Wpldp::RESOURCE_POST_TYPE === get_post_type( $post ) ) {
				if ( is_object( $post ) ) {
					$terms = wp_get_object_terms( $post->ID, 'ldp_container' );
					if ( ! empty( $terms ) ) {
						return str_replace( '%ldp_container%', $terms[0]->slug, $post_link );
					}
				}
			}

			return $post_link;
		}

		/**
		 * Removes the original meta box on the ldp_resource edition page and
		 * replace it with radio buttons selectors to avoid multiple selection.
		 *
		 * @param {string} $post_type The current post type.
		 * @return void
		 */
		function display_container_meta_box( $post_type ) {
			remove_meta_box( 'ldp_containerdiv', $post_type, 'side' );

			if ( Wpldp::RESOURCE_POST_TYPE === $post_type ) {
				add_meta_box(
					'ldp_containerdiv',
					__( 'Containers', 'wpldp' ),
					array( $this, 'container_meta_box_callback' ),
					$post_type,
					'normal',
					'high'
				);
			}
		}

		/**
		 * Generates the HTML for the radio button based meta box.
		 *
		 * @param {WP_Post} $post The current post instance.
		 * @return void
		 */
		function container_meta_box_callback( $post ) {
			wp_nonce_field(
				'wpldp_save_container_box_data',
				'wpldp_container_box_nonce'
			);

			$value = get_the_terms( $post->ID, 'ldp_container' )[0];
			$terms = get_terms(
				'ldp_container',
				array(
					'hide_empty' => 0,
				)
			);
			?><ul><?php
			foreach ( $terms as $term ) {
				?>
					<li id="ldp_container-<?php echo $term->term_id; ?>" class="category">
						<label class="selectit">
				<?php if ( ! empty( $value ) && $term->term_id === $value->term_id ) { ?>
					<input id="in-ldp_container-<?php echo $term->term_id; ?>" type="radio" name="tax_input[ldp_container][]" value="<?php echo $term->term_id; ?>" checked>
				<?php } else { ?>
					<input id="in-ldp_container-<?php echo $term->term_id; ?>" type="radio" name="tax_input[ldp_container][]" value="<?php echo $term->term_id; ?>">
				<?php } ?>
				<?php echo $term->name; ?>
				</input>
				</label>
				</li>
			<?php } ?>
		</ul><?php
		}

		/**
		 * Adds an access to the media library from the ldp_resource edition page.
		 *
		 * @param {string} $post_type The current post type.
		 * @return void
		 */
		function display_media_meta_box( $post_type ) {
			if ( Wpldp::RESOURCE_POST_TYPE === $post_type ) {
				add_meta_box(
					'ldp_mediadiv',
					__( 'Media', 'wpldp' ),
					array( $this, 'media_meta_box_callback' ),
					$post_type,
					'side'
				);
			}
		}

		/**
		 * Add specific metabox for uploading a file to the media library from a resource edit.
		 *
		 * @param {WP_Post} $post The current post instance.
		 * @return void
		 */
		public function media_meta_box_callback( $post ) {
			?>
			<p><?php echo __( 'If you need to upload a media during your editing, click here.', 'wpldp' ); ?></p>
			<a href="#" class="button insert-media add-media" data-editor="content" title="Add Media">
				<span class="wp-media-buttons-icon"></span> Add Media
			</a><?php
		}

		/**
		 * Renders the form for entering the data.
		 *
		 * @param  {WP_Post} $post Current post we are working on.
		 * @return void
		 */
		public function wpldp_edit_form_advanced( $post ) {
			if ( Wpldp::RESOURCE_POST_TYPE === $post->post_type ) {
				$resource_uri = Utils::get_resource_uri( $post );

				$term = get_the_terms( $post->post_id, 'ldp_container' );
				if ( ! empty( $term ) && ! empty( $resource_uri ) ) {
					$term_id = $term[0]->term_id;
					$term_meta = get_option( "ldp_container_$term_id" );

					if ( empty( $term_meta ) || ! isset( $term_meta['ldp_model'] ) ) {
						$ldp_model = '{"people":
							{"fields":
								[{
									"title": "What\'s your name?",
									"name": "ldp_name"
								},
								{
									"title": "Who are you?",
									"name": "ldp_description"
								}]
							}
						}';
					} else {
						$ldp_model = json_encode( json_decode( $term_meta['ldp_model'] ) );
					}

					?>
					<br>
					<div id="ldpform"></div>
					<script>
						var store = new MyStore({
							container: '<?php echo $resource_uri; ?>',
							context: "<?php echo get_option( 'ldp_context', 'http://lov.okfn.org/dataset/lov/context' ); ?>",
							template: "{{{form '<?php echo $term[0]->slug; ?>'}}}",
							models: <?php echo $ldp_model; ?>
						});
						var wpldp = new wpldp( store );
						wpldp.init();
						wpldp.render('#ldpform', '<?php echo $resource_uri; ?>', undefined, undefined, '<?php echo $term[0]->slug; ?>');
					</script>
					<?php
				}
			}
		}


		/**
		 * Saves the LDP Resource Post Meta on save.
		 *
		 * @param  {int} $resource_id The current resource id.
		 * @return void
		 */
		public function save_ldp_meta_for_post( $resource_id ) {
			$fields = Utils::get_resource_fields_list( $resource_id );

			if ( ! empty( $fields ) ) {
				foreach ( $_POST as $key => $value ) {
					foreach ( $fields as $field ) {
						$field_name = \WpLdp\Utils::get_field_name( $field );
						if ( isset( $field_name ) ) {
							if ( $key === $field_name ||
							( substr( $key, 0, strlen( $field_name ) ) === $field_name )
							) {
								if ( is_array( $value ) ) {
									$array_to_save = array();
									foreach ( $value as $site ) {
										if ( ! empty( $site ) ) {
											$array_to_save[] = $site;
										}

										if ( strpos( $site, 'ldp' ) !== false &&
												( strpos( $site, 'http://' ) !== false ||
												  strpos( $site, 'https://' ) !== false )
											) {
											$site_url = explode( \WpLdp\Api::LDP_API_URL, $site );
											$site_url = $site_url[0] . \WpLdp\Api::LDP_API_URL;

											$term = get_terms(
												array(
													'taxonomy' => 'ldp_site',
													'meta_query' => array(
														'key' => 'ldp_site_url',
														'value' => $site_url,
														'compare' => 'LIKE',
													),
												)
											);

											if ( empty( $term ) || ! is_array( $term ) ) {
												$site_url_parsed = wp_parse_url( $site );
												$term = wp_insert_term(
													$site_url_parsed['host'] . ' ' . $site_url_parsed['path'],
													'ldp_site'
												);

												if ( ! is_wp_error( $term ) ) {
													update_term_meta( $term['term_id'], 'ldp_site_url', $site_url );
												}
											}
										}
									}

									update_post_meta( $resource_id, $key, $array_to_save );
								} else {
									update_post_meta( $resource_id, $key, $value );
								}
							}
						}
					}
				}
			}
		}

		/**
		 * Loads requested javascript, on the admin only.
		 *
		 * @return void
		 */
		public function ldp_enqueue_script() {
			global $pagenow, $post_type;
			$screen = get_current_screen();
			if ( Wpldp::RESOURCE_POST_TYPE === $post_type ) {
				wp_enqueue_media();

				// Loading the LDP-framework library.
				wp_register_script(
					'ldpjs',
					plugins_url( 'library/js/LDP-framework/ldpframework.js', __FILE__ ),
					array( 'jquery' )
				);
				wp_enqueue_script( 'ldpjs' );

				// Loading the JqueryUI library.
				wp_register_script(
					'jqueryui',
					plugins_url( 'library/js/jquery-ui/jquery-ui.min.js', __FILE__ ),
					array( 'jquery' )
				);
				wp_enqueue_script( 'jqueryui' );

				// Loading the JSONEditor library.
				wp_register_script(
					'jsoneditorjs',
					plugins_url( 'library/js/node_modules/jsoneditor/dist/jsoneditor.min.js', __FILE__ )
				);
				wp_enqueue_script( 'jsoneditorjs' );

				// Loading the Handlebars library.
				wp_register_script(
					'handlebarsjs',
					plugins_url( 'library/js/handlebars/handlebars.js', __FILE__ ),
					array( 'ldpjs' )
				);
				wp_enqueue_script( 'handlebarsjs' );

				// Loading the Handlebars library.
				wp_register_script(
					'select2',
					plugins_url( 'library/js/select2/dist/js/select2.full.min.js', __FILE__ ),
					array( 'jquery' )
				);
				wp_enqueue_script( 'select2' );

				// Loading the Plugin-javascript file.
				wp_register_script(
					'wpldpjs',
					plugins_url( 'wpldp.js', __FILE__ ),
					array( 'jquery', 'select2' )
				);
				wp_localize_script( 'wpldpjs', 'site_rest_url', get_rest_url() );
				wp_enqueue_script( 'wpldpjs' );

				// Loading the Wikipedia autocomplete library.
				wp_register_script(
					'lookup',
					plugins_url( 'public/resources/js/wikipedia.js', __FILE__ ),
					array( 'ldpjs' )
				);
				wp_enqueue_script( 'lookup' );
			}
		}

		/**
		 * Loads all proper javascript resource on the frontend.
		 *
		 * @return void
		 */
		public function wpldpfront_enqueue_script() {
			$current_url = $_SERVER['REQUEST_URI'];
			if ( strstr( $current_url, Wpldp::FRONT_PAGE_URL ) ) {
				// Loading the JqueryUI library.
				wp_register_script(
					'jqueryui',
					plugins_url( 'library/js/jquery-ui/jquery-ui.min.js', __FILE__ ),
					array( 'jquery' )
				);
				wp_enqueue_script( 'jqueryui' );

				// Loading the LDP-framework library.
				wp_register_script(
					'ldpjs',
					plugins_url( 'library/js/LDP-framework/ldpframework.js', __FILE__ ),
					array( 'jquery' )
				);
				wp_enqueue_script( 'ldpjs' );

				// Loading the Plugin-javascript file.
				wp_register_script(
					'wpldpjs',
					plugins_url( 'wpldp.js', __FILE__ ),
					array( 'jquery' )
				);
				wp_localize_script( 'wpldpjs', 'site_rest_url', get_rest_url() );
				wp_enqueue_script( 'wpldpjs' );

				// Loading the BootstrapJS library.
				wp_register_script(
					'bootstrapjs',
					plugins_url( 'public/library/bootstrap/js/bootstrap.min.js', __FILE__ ),
					array( 'ldpjs' )
				);
				wp_enqueue_script( 'bootstrapjs' );

				// Loading the Handlebars library.
				wp_register_script(
					'handlebarsjs',
					plugins_url( 'library/js/handlebars/handlebars.js', __FILE__ ),
					array( 'ldpjs' )
				);
				wp_enqueue_script( 'handlebarsjs' );

				// Loading the project specific JS library.
				wp_register_script(
					'avpocjs',
					plugins_url( 'public/resources/js/av.js', __FILE__ ),
					array( 'ldpjs' )
				);
				wp_enqueue_script( 'avpocjs' );
			}
		}

		/**
		 * Loads requested stylesheet in the admin only.
		 *
		 * @return void
		 */
		public function ldp_enqueue_stylesheet() {
			// Loading the WP-LDP stylesheet.
			wp_register_style(
				'wpldpcss',
				plugins_url( 'resources/css/wpldp.css', __FILE__ )
			);
			wp_enqueue_style( 'wpldpcss' );

			// Loading the JSONEditor stylesheet.
			wp_register_style(
				'jsoneditorcss',
				plugins_url( 'library/js/node_modules/jsoneditor/dist/jsoneditor.min.css', __FILE__ )
			);
			wp_enqueue_style( 'jsoneditorcss' );

			// Loading the JQueryUI stylesheet.
			wp_register_style(
				'jqueryuicss',
				plugins_url( 'library/js/jquery-ui/jquery-ui.css', __FILE__ )
			);
			wp_enqueue_style( 'jqueryuicss' );

			// Loading the JQueryUIStructure stylesheet.
			wp_register_style(
				'jqueryuistructurecss',
				plugins_url( 'library/js/jquery-ui/jquery-ui.structure.css', __FILE__ )
			);
			wp_enqueue_style( 'jqueryuistructurecss' );
		}


		/**
		 * Loads specific stylesheets for the plugin frontend.
		 *
		 * @return void
		 */
		public function wpldpfront_enqueue_stylesheet() {
			$current_url = $_SERVER['REQUEST_URI'];
			if ( strstr( $current_url, Wpldp::FRONT_PAGE_URL ) ) {
				// Loading the WP-LDP stylesheet.
				wp_register_style(
					'bootstrapcss',
					plugins_url( 'public/library/bootstrap/css/bootstrap.min.css', __FILE__ )
				);
				wp_enqueue_style( 'bootstrapcss' );

				// Loading the WP-LDP stylesheet.
				wp_register_style(
					'font-asewomecss',
					plugins_url( 'public/library/font-awesome/css/font-awesome.min.css', __FILE__ )
				);
				wp_enqueue_style( 'font-asewomecss' );
			}
		}

		/**
		 * Adds a menu item to the primary navigation menu to access
		 * the WP-ldp front page to navigate into our pairs.
		 *
		 * @return void
		 */
		public static function generate_menu_item() {
			$menu_name = 'primary';
			$locations = get_nav_menu_locations();

			if ( ! empty( $locations ) && isset( $locations[ $menu_name ] ) ) {
				$menu_id = $locations[ $menu_name ] ;

				if ( ! empty( $menu_id ) ) {
					wp_update_nav_menu_item(
						$menu_id,
						0,
						array(
							'menu-item-title' => __( 'Ecosystem', 'wpldp' ),
							'menu-item-classes' => 'home',
							'menu-item-url' => home_url( Wpldp::FRONT_PAGE_URL, 'relative' ),
							'menu-item-status' => 'publish',
						)
					);
				}
			}
		}

		/**
		 * Removes the additional menu item on plugin deactivation.
		 *
		 * @return void
		 */
		public static function remove_menu_item() {
			$menu_name = 'primary';
			$locations = get_nav_menu_locations();
			$menu_id = $locations[ $menu_name ] ;
			$items = wp_get_nav_menu_items( $menu_id );
			$menu_object = wp_get_nav_menu_object( $menu_id );
			foreach ( $items as $key => $item ) {
				if ( strstr( $item->url, Wpldp::FRONT_PAGE_URL ) ) {
					wp_delete_post( $item->ID, true );
					unset( $items[ $key ] );
				}
			}
		}

		/**
		 * Forces the flush of rewrite rules on plugin activation
		 * to prevent impossibility to access the LDP resources.
		 *
		 * @return void
		 */
		public function wpldp_rewrite_flush() {
			// Register post type to activate associated rewrite rules.
			delete_option( 'rewrite_rules' );
			$this->create_ldp_type();
			$this->add_poc_rewrite_rule();
			// Flush rules to be certain of the possibility to access the new CPT.
			flush_rewrite_rules( true );
		}

		/**
		 * Same thing - for deactivation only.
		 *
		 * @return void
		 */
		public function wpldp_flush_rewrite_rules_on_deactivation() {
			flush_rewrite_rules( true );
			delete_option( 'rewrite_rules' );
		}
	}

	$wpldp = new WpLdp();
} else {
	exit( 'Class WpLdp already exists' );
}
