<?php

defined( 'ABSPATH' ) or exit;

if ( ! class_exists( 'GHU_Core' ) ) {
    class GHU_Core
    {
        public $update_data = array();
        public $active_plugins = array();


        function __construct() {
            add_action( 'admin_init', array( $this, 'admin_init' ) );
            add_filter( 'plugins_api', array( $this, 'plugins_api' ), 10, 3 );
            add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'set_update_data' ) );
            add_filter( 'upgrader_source_selection', array( $this, 'upgrader_source_selection' ), 10, 4 );
            add_filter( 'extra_plugin_headers', array( $this, 'extra_plugin_headers' ) );
        }


        function admin_init() {
            $now = strtotime( 'now' );
            $last_checked = (int) get_option( 'ghu_last_checked' );
            $check_interval = apply_filters( 'ghu_check_interval', ( 60 * 60 * 12 ) );
            $this->update_data = (array) get_option( 'ghu_update_data' );
            $active = (array) get_option( 'active_plugins' );

            foreach ( $active as $plugin_file ) {
                $this->active_plugins[ $plugin_file ] = true;
            }

            // transient expiration
            if ( ( $now - $last_checked ) > $check_interval ) {
                $this->update_data = $this->get_github_updates();

                update_option( 'ghu_update_data', $this->update_data );
                update_option( 'ghu_last_checked', $now );
            }
        }


        /**
         * Fetch the latest GitHub tags and build the plugin data array
         */
        function get_github_updates() {
            $plugin_data = array();
            $plugins = get_plugins();
            foreach ( $plugins as $plugin_file => $info ) {
                if ( isset( $this->active_plugins[ $plugin_file ] ) && ! empty( $info['GitHub Plugin URI'] ) ) {
                    $header_parts = parse_url( $info['GitHub Plugin URI'] );
                    $header_path = pathinfo( $header_parts['path'] );
                    $host = isset( $header_parts['host'] ) ? $header_parts['host'] : null;
                    $owner = trim( $header_path['dirname'], '/' );
                    $repo = $header_path['filename'];

                    if ( isset($host) && strtolower($host) != "github.com" )
                        break;

                    $request = wp_remote_get( "https://api.github.com/repos/$owner/$repo/tags" );

                    // WP error or rate limit exceeded
                    if ( is_wp_error( $request ) || 200 != wp_remote_retrieve_response_code( $request ) ) {
                        break;
                    }

                    $json = json_decode( $request['body'], true );

                    if ( is_array( $json ) && ! empty( $json ) ) {
                        $latest_tag = $json[0];
                        // TODO check slug for plugin without dir (no clear relation between plugin_file and slug in WP)
                        $plugin_data[ $plugin_file ] = array(
                            'plugin'            => $plugin_file,
                            'slug'              => trim( dirname( $plugin_file ), '/' ),
                            'name'              => $info['Name'],
                            'description'       => $info['Description'],
                            'new_version'       => $latest_tag['name'],
                            'url'               => "https://github.com/$owner/$repo/",
                            'package'           => $latest_tag['zipball_url']
                        );
                    }
                }
            }

            return $plugin_data;
        }


        /**
         * Get plugin info for the "View Details" popup
         */
        function plugins_api( $default = false, $action, $args ) {
            if ( 'plugin_information' == $action ) {
                foreach ($this->update_data as $plugin_file => $info) {
                    if ( $info['slug'] == $args->slug ) {
                        return (object) array(
                            'name'          => $info['name'],
                            'slug'          => $info['slug'],
                            'version'       => $info['new_version'],
                            'download_link' => $info['package'],
                            'sections' => array(
                                'description' => $info['description']
                            )
                        );
                    }
                }
            }

            return $default;
        }


        function set_update_data( $transient ) {
            if ( empty( $transient->checked ) ) {
                return $transient;
            }

            foreach ( $this->update_data as $plugin_file => $info ) {
                if ( isset( $this->active_plugins[ $plugin_file ] ) ) {
                    $plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file );
                    $version = $plugin_data['Version'];

                    if ( version_compare( $version, $info['new_version'], '<' ) ) {
                        $transient->response[ $plugin_file ] = (object) $info;
                    }
                }
            }

            return $transient;
        }


        /**
         * Rename the plugin folder
         */
        function upgrader_source_selection( $source, $remote_source, $upgrader, $hook_extra = null ) {
            global $wp_filesystem;

            $plugin = isset( $hook_extra['plugin'] ) ? $hook_extra['plugin'] : false;
            if ( isset( $this->update_data[ $plugin ] ) && $plugin ) {
                $new_source = trailingslashit( $remote_source ) . dirname( $plugin );
                $wp_filesystem->move( $source, $new_source );
                return trailingslashit( $new_source );
            }

            return $source;
        }


        /**
         * Parse the "GitHub URI" config too
         */
        function extra_plugin_headers( $headers ) {
            $headers[] = 'GitHub Plugin URI';
            return $headers;
        }
    }

    new GHU_Core();
}
