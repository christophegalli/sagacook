<?php
/**
 * Sagacook Import WP-CLI Command.
 *
 * Usage:
 *   wp sagacook import all
 *   wp sagacook import taxonomy
 *   wp sagacook import recipes
 *   wp sagacook import products
 *   wp sagacook import pages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sagacook_Import_CLI {

    /**
     * Import content from Drupal JSON exports.
     *
     * ## OPTIONS
     *
     * <type>
     * : The type of content to import.
     * ---
     * options:
     *   - all
     *   - taxonomy
     *   - recipes
     *   - products
     *   - pages
     *   - images
     * ---
     *
     * ## EXAMPLES
     *
     *     wp sagacook import all
     *     wp sagacook import taxonomy
     *     wp sagacook import recipes
     *     wp sagacook import products
     *     wp sagacook import pages
     *     wp sagacook import images
     *
     * @when after_wp_load
     */
    public function __invoke( $args, $assoc_args ) {
        $type     = $args[0];
        $importer = new Sagacook_Importer();

        switch ( $type ) {
            case 'all':
                WP_CLI::log( '=== Starting full Sagacook import ===' );
                $importer->import_taxonomy();
                $importer->import_recipes();
                $importer->import_products();
                $importer->import_pages();
                $importer->import_images();
                WP_CLI::success( '=== Full Sagacook import complete ===' );
                break;

            case 'taxonomy':
                $importer->import_taxonomy();
                break;

            case 'recipes':
                $importer->import_recipes();
                break;

            case 'products':
                $importer->import_products();
                break;

            case 'pages':
                $importer->import_pages();
                break;

            case 'images':
                $importer->import_images();
                break;

            default:
                WP_CLI::error( "Unknown import type: {$type}. Use: all, taxonomy, recipes, products, pages, images." );
        }
    }
}
