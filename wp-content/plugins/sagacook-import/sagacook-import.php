<?php
/**
 * Plugin Name: Sagacook Import
 * Description: Registers CPTs and taxonomies for the Sagacook recipe site and provides WP-CLI import commands.
 * Version: 1.0.0
 * Author: Sagacook
 * Text Domain: sagacook-import
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SAGACOOK_IMPORT_DIR', plugin_dir_path( __FILE__ ) );
define( 'SAGACOOK_EXPORT_DIR', ABSPATH . 'export/' );

/**
 * Register Custom Post Types and Taxonomies.
 */
add_action( 'init', 'sagacook_register_types' );

function sagacook_register_types() {
    // CPT: Rezept
    register_post_type( 'rezept', [
        'labels' => [
            'name'               => 'Rezepte',
            'singular_name'      => 'Rezept',
            'add_new'            => 'Neues Rezept',
            'add_new_item'       => 'Neues Rezept hinzufügen',
            'edit_item'          => 'Rezept bearbeiten',
            'view_item'          => 'Rezept ansehen',
            'all_items'          => 'Alle Rezepte',
            'search_items'       => 'Rezepte suchen',
            'not_found'          => 'Keine Rezepte gefunden',
            'not_found_in_trash' => 'Keine Rezepte im Papierkorb',
        ],
        'public'       => true,
        'has_archive'  => true,
        'rewrite'      => [ 'slug' => 'rezept' ],
        'supports'     => [ 'title', 'editor', 'thumbnail', 'custom-fields' ],
        'show_in_rest' => true,
    ] );

    // CPT: Produkt
    register_post_type( 'produkt', [
        'labels' => [
            'name'               => 'Produkte',
            'singular_name'      => 'Produkt',
            'add_new'            => 'Neues Produkt',
            'add_new_item'       => 'Neues Produkt hinzufügen',
            'edit_item'          => 'Produkt bearbeiten',
            'view_item'          => 'Produkt ansehen',
            'all_items'          => 'Alle Produkte',
            'search_items'       => 'Produkte suchen',
            'not_found'          => 'Keine Produkte gefunden',
            'not_found_in_trash' => 'Keine Produkte im Papierkorb',
        ],
        'public'       => true,
        'has_archive'  => true,
        'rewrite'      => [ 'slug' => 'produkt' ],
        'supports'     => [ 'title', 'editor', 'thumbnail', 'custom-fields' ],
        'show_in_rest' => true,
    ] );

    // Taxonomy: Kategorie (hierarchical)
    register_taxonomy( 'kategorie', 'rezept', [
        'labels' => [
            'name'          => 'Kategorien',
            'singular_name' => 'Kategorie',
            'all_items'     => 'Alle Kategorien',
            'edit_item'     => 'Kategorie bearbeiten',
            'add_new_item'  => 'Neue Kategorie hinzufügen',
            'search_items'  => 'Kategorien suchen',
        ],
        'hierarchical' => true,
        'public'       => true,
        'rewrite'      => [ 'slug' => 'kategorie' ],
        'show_in_rest' => true,
    ] );

    // Taxonomy: Region (hierarchical)
    register_taxonomy( 'region', 'rezept', [
        'labels' => [
            'name'          => 'Regionen',
            'singular_name' => 'Region',
            'all_items'     => 'Alle Regionen',
            'edit_item'     => 'Region bearbeiten',
            'add_new_item'  => 'Neue Region hinzufügen',
            'search_items'  => 'Regionen suchen',
        ],
        'hierarchical' => true,
        'public'       => true,
        'rewrite'      => [ 'slug' => 'region' ],
        'show_in_rest' => true,
    ] );

    // Taxonomy: Tags (non-hierarchical)
    register_taxonomy( 'tags', 'rezept', [
        'labels' => [
            'name'          => 'Stichworte',
            'singular_name' => 'Stichwort',
            'all_items'     => 'Alle Stichworte',
            'edit_item'     => 'Stichwort bearbeiten',
            'add_new_item'  => 'Neues Stichwort hinzufügen',
            'search_items'  => 'Stichworte suchen',
        ],
        'hierarchical' => false,
        'public'       => true,
        'rewrite'      => [ 'slug' => 'tags' ],
        'show_in_rest' => true,
    ] );
}

/**
 * Shortcodes for single recipe display.
 */
add_shortcode( 'rezeptlinien', 'sagacook_rezeptlinien_shortcode' );

function sagacook_rezeptlinien_shortcode() {
    $post_id = get_the_ID();
    if ( ! $post_id ) {
        return '';
    }

    $lines = get_post_meta( $post_id, '_rezeptlinien', true );
    if ( ! is_array( $lines ) || empty( $lines ) ) {
        return '';
    }

    $out = '<div class="rezeptlinien">';
    foreach ( $lines as $line ) {
        $zutaten     = isset( $line['zutaten'] ) ? $line['zutaten'] : null;
        $zubereitung = isset( $line['zubereitung'] ) ? $line['zubereitung'] : null;

        if ( ! $zutaten && ! $zubereitung ) {
            continue;
        }

        if ( $zutaten ) {
            $zutaten = preg_replace( '/(<p>(\s|&nbsp;)*<\/p>\s*)+$/i', '', $zutaten );
        }

        $out .= '<div class="rezeptlinien-line">';
        $out .= '<div class="rezeptlinien-zutaten">' . ( $zutaten ? wp_kses_post( $zutaten ) : '' ) . '</div>';
        $out .= '<div class="rezeptlinien-zubereitung">' . ( $zubereitung ? wp_kses_post( $zubereitung ) : '' ) . '</div>';
        $out .= '</div>';
    }
    $out .= '</div>';

    return $out;
}

add_shortcode( 'alle_tags', 'sagacook_alle_tags_shortcode' );
add_shortcode( 'alle_regionen', 'sagacook_alle_regionen_shortcode' );

function sagacook_alle_tags_shortcode()     { return sagacook_term_cloud( 'tags' ); }
function sagacook_alle_regionen_shortcode() { return sagacook_term_cloud( 'region' ); }

function sagacook_term_cloud( $taxonomy ) {
    $terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => true, 'orderby' => 'name', 'order' => 'ASC' ] );
    if ( is_wp_error( $terms ) || empty( $terms ) ) {
        return '';
    }

    $out = '<ul class="sagacook-alle-tags">';
    foreach ( $terms as $term ) {
        $out .= '<li><a href="' . esc_url( get_term_link( $term ) ) . '">' . esc_html( $term->name ) . '</a></li>';
    }
    $out .= '</ul>';

    return $out;
}

add_shortcode( 'bild_zutaten', 'sagacook_bild_zutaten_shortcode' );

function sagacook_bild_zutaten_shortcode() {
    $post_id = get_the_ID();
    if ( ! $post_id ) {
        return '';
    }

    $image_id = get_post_meta( $post_id, '_bild_zutaten_id', true );
    if ( ! $image_id ) {
        return '';
    }

    return wp_get_attachment_image( (int) $image_id, 'full' );
}

/**
 * Register WP-CLI command.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    add_action( 'cli_init', function () {
        require_once SAGACOOK_IMPORT_DIR . 'includes/class-importer.php';
        require_once SAGACOOK_IMPORT_DIR . 'includes/class-import-cli.php';
        WP_CLI::add_command( 'sagacook import', 'Sagacook_Import_CLI' );
    } );
}
