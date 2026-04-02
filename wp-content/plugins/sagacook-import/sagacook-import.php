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
 * Search: include rezept + produkt, and match taxonomy terms.
 */
add_filter( 'posts_search', 'sagacook_search_include_terms', 10, 2 );

function sagacook_search_include_terms( $search, $query ) {
    global $wpdb;

    if ( is_admin() || ! $query->is_search() || empty( $search ) ) {
        return $search;
    }

    $s = $query->get( 's' );
    if ( ! $s ) {
        return $search;
    }

    $like     = '%' . $wpdb->esc_like( $s ) . '%';
    $term_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT t.term_id
         FROM {$wpdb->terms} t
         INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
         WHERE tt.taxonomy IN ('tags', 'region')
         AND t.name LIKE %s",
        $like
    ) );

    if ( empty( $term_ids ) ) {
        return $search;
    }

    $ids_in   = implode( ',', array_map( 'intval', $term_ids ) );
    $post_ids = $wpdb->get_col(
        "SELECT DISTINCT tr.object_id
         FROM {$wpdb->term_relationships} tr
         INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
         WHERE tt.term_id IN ($ids_in)"
    );

    if ( empty( $post_ids ) ) {
        return $search;
    }

    $post_ids_in = implode( ',', array_map( 'intval', $post_ids ) );
    $search     .= " OR {$wpdb->posts}.ID IN ($post_ids_in)";

    return $search;
}

// Wrap the entire WHERE so the post_type restriction applies to all OR branches.
add_filter( 'posts_where', 'sagacook_search_restrict_types', 99, 2 );

function sagacook_search_restrict_types( $where, $query ) {
    global $wpdb;

    if ( is_admin() || ! $query->is_search() ) {
        return $where;
    }

    // Strip the leading AND so we can wrap cleanly.
    $inner = preg_replace( '/^\s*AND\s+/i', '', trim( $where ) );
    return " AND ($inner) AND {$wpdb->posts}.post_type = 'rezept' AND {$wpdb->posts}.post_status = 'publish'";
}

/**
 * Meta boxes for rezept CPT.
 */
add_action( 'add_meta_boxes', 'sagacook_add_meta_boxes' );

function sagacook_add_meta_boxes() {
    add_meta_box( 'sagacook_rezeptlinien', 'Rezeptlinien', 'sagacook_rezeptlinien_meta_box', 'rezept', 'normal', 'high' );
    add_meta_box( 'sagacook_bild_zutaten', 'Bild Zutaten',  'sagacook_bild_zutaten_meta_box',  'rezept', 'side' );
}

function sagacook_rezeptlinien_meta_box( $post ) {
    $lines = get_post_meta( $post->ID, '_rezeptlinien', true );
    if ( ! is_array( $lines ) ) {
        $lines = [];
    }
    wp_nonce_field( 'sagacook_save_meta', 'sagacook_meta_nonce' );
    $editor_defaults = [
        'media_buttons' => false,
        'teeny'         => true,
        'textarea_rows' => 5,
    ];
    ?>
    <style>
    .sagacook-line { display: grid; grid-template-columns: 1fr 1fr 28px; gap: 8px; margin-bottom: 16px; border-top: 1px solid #e0e0e0; padding-top: 12px; }
    .sagacook-line:first-child { border-top: none; padding-top: 0; }
    .sagacook-line > div > label { font-weight: 600; font-size: 12px; display: block; margin-bottom: 4px; }
    .sagacook-line textarea { width: 100%; }
    </style>
    <div id="sagacook-lines">
    <?php foreach ( $lines as $i => $line ) : ?>
        <div class="sagacook-line">
            <div>
                <label>Zutaten</label>
                <?php wp_editor( $line['zutaten'] ?? '', 'sagacookzutaten' . $i,
                    $editor_defaults + [ 'textarea_name' => 'rezeptlinien[' . $i . '][zutaten]' ] ); ?>
            </div>
            <div>
                <label>Zubereitung</label>
                <?php wp_editor( $line['zubereitung'] ?? '', 'sagacookzubereitung' . $i,
                    $editor_defaults + [ 'textarea_name' => 'rezeptlinien[' . $i . '][zubereitung]' ] ); ?>
            </div>
            <div>
                <button type="button" class="button remove-line" title="Zeile entfernen" style="margin-top:22px">✕</button>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
    <button type="button" id="sagacook-add-line" class="button" style="margin-top:8px">+ Zeile hinzufügen</button>
    <p class="description" style="margin-top:6px;font-size:12px">Neue Zeilen nach dem Speichern mit dem Editor bearbeiten.</p>
    <script>
    (function() {
        var lines = document.getElementById('sagacook-lines');
        document.getElementById('sagacook-add-line').addEventListener('click', function() {
            var idx = lines.querySelectorAll('.sagacook-line').length;
            var div = document.createElement('div');
            div.className = 'sagacook-line';
            div.innerHTML =
                '<div><label>Zutaten</label>'
                + '<textarea name="rezeptlinien[' + idx + '][zutaten]" rows="5" style="width:100%"></textarea></div>'
                + '<div><label>Zubereitung</label>'
                + '<textarea name="rezeptlinien[' + idx + '][zubereitung]" rows="5" style="width:100%"></textarea></div>'
                + '<div><button type="button" class="button remove-line" style="margin-top:22px">✕</button></div>';
            lines.appendChild(div);
        });
        lines.addEventListener('click', function(e) {
            if (e.target.classList.contains('remove-line')) {
                if (confirm('Diese Zeile löschen?')) {
                    e.target.closest('.sagacook-line').remove();
                }
            }
        });
    })();
    </script>
    <?php
}

function sagacook_bild_zutaten_meta_box( $post ) {
    $image_id    = (int) get_post_meta( $post->ID, '_bild_zutaten_id', true );
    $preview_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';
    wp_enqueue_media();
    ?>
    <div id="sagacook-bild-wrap">
        <img id="sagacook-bild-preview" src="<?php echo esc_url( $preview_url ); ?>"
             style="max-width:100%;display:<?php echo $preview_url ? 'block' : 'none'; ?>;margin-bottom:8px">
        <input type="hidden" name="bild_zutaten_id" id="sagacook-bild-id" value="<?php echo esc_attr( $image_id ?: '' ); ?>">
        <button type="button" id="sagacook-bild-select" class="button">Bild auswählen</button>
        <button type="button" id="sagacook-bild-remove" class="button"
                style="display:<?php echo $image_id ? 'inline-block' : 'none'; ?>;margin-left:4px">Entfernen</button>
    </div>
    <script>
    (function() {
        var frame;
        document.getElementById('sagacook-bild-select').addEventListener('click', function() {
            if (!frame) {
                frame = wp.media({ title: 'Bild auswählen', button: { text: 'Auswählen' }, multiple: false });
                frame.on('select', function() {
                    var att = frame.state().get('selection').first().toJSON();
                    document.getElementById('sagacook-bild-id').value = att.id;
                    document.getElementById('sagacook-bild-preview').src = att.url;
                    document.getElementById('sagacook-bild-preview').style.display = 'block';
                    document.getElementById('sagacook-bild-remove').style.display = 'inline-block';
                });
            }
            frame.open();
        });
        document.getElementById('sagacook-bild-remove').addEventListener('click', function() {
            document.getElementById('sagacook-bild-id').value = '';
            document.getElementById('sagacook-bild-preview').src = '';
            document.getElementById('sagacook-bild-preview').style.display = 'none';
            this.style.display = 'none';
        });
    })();
    </script>
    <?php
}

add_action( 'save_post_rezept', 'sagacook_save_meta' );

function sagacook_save_meta( $post_id ) {
    if ( ! isset( $_POST['sagacook_meta_nonce'] )
         || ! wp_verify_nonce( $_POST['sagacook_meta_nonce'], 'sagacook_save_meta' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    // Save recipe lines.
    if ( isset( $_POST['rezeptlinien'] ) && is_array( $_POST['rezeptlinien'] ) ) {
        $lines = [];
        foreach ( $_POST['rezeptlinien'] as $line ) {
            $zutaten     = wp_kses_post( wp_unslash( $line['zutaten'] ?? '' ) );
            $zubereitung = wp_kses_post( wp_unslash( $line['zubereitung'] ?? '' ) );
            if ( trim( strip_tags( $zutaten ) ) !== '' || trim( strip_tags( $zubereitung ) ) !== '' ) {
                $lines[] = [ 'zutaten' => $zutaten, 'zubereitung' => $zubereitung ];
            }
        }
        update_post_meta( $post_id, '_rezeptlinien', $lines );
    }

    // Save ingredients image.
    $image_id = absint( $_POST['bild_zutaten_id'] ?? 0 );
    if ( $image_id ) {
        update_post_meta( $post_id, '_bild_zutaten_id', $image_id );
    } else {
        delete_post_meta( $post_id, '_bild_zutaten_id' );
    }
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

        if ( $zubereitung ) {
            $zubereitung = preg_replace_callback(
                '/(?:<p>- .+?<\/p>\s*)+/s',
                function ( $m ) {
                    preg_match_all( '/<p>- (.+?)<\/p>/s', $m[0], $items );
                    $lis = array_map( fn( $t ) => '<li>- ' . $t . '</li>', $items[1] );
                    return '<ul class="sagacook-remarks">' . implode( '', $lis ) . '</ul>';
                },
                $zubereitung
            );
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
add_shortcode( 'alle_kategorien', 'sagacook_alle_kategorien_shortcode' );

function sagacook_alle_tags_shortcode()       { return sagacook_term_cloud( 'tags' ); }
function sagacook_alle_regionen_shortcode()   { return sagacook_term_cloud( 'region' ); }
function sagacook_alle_kategorien_shortcode() { return sagacook_term_cloud( 'kategorie' ); }

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
