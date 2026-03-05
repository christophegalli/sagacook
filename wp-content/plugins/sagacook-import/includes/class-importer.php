<?php
/**
 * Sagacook Importer — core import logic for all content types.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sagacook_Importer {

    /**
     * Import taxonomy terms from taxonomy.json.
     */
    public function import_taxonomy() {
        $file = SAGACOOK_EXPORT_DIR . 'taxonomy.json';
        $data = json_decode( file_get_contents( $file ), true );

        if ( ! $data ) {
            WP_CLI::error( 'Could not read taxonomy.json' );
        }

        $vocabularies = [
            'region'    => 'region',
            'kategorie' => 'kategorie',
            'tags'      => 'tags',
        ];

        foreach ( $vocabularies as $json_key => $taxonomy ) {
            if ( empty( $data[ $json_key ] ) ) {
                WP_CLI::warning( "No terms found for {$json_key}" );
                continue;
            }

            $terms = $data[ $json_key ];
            $total = count( $terms );

            WP_CLI::log( "Importing {$total} terms into '{$taxonomy}'..." );

            foreach ( $terms as $i => $term_data ) {
                $name = $term_data['name'];
                $tid  = $term_data['tid'];

                // Check if term with this tid already exists.
                $existing = get_terms( [
                    'taxonomy'   => $taxonomy,
                    'meta_key'   => '_drupal_tid',
                    'meta_value' => $tid,
                    'hide_empty' => false,
                    'number'     => 1,
                ] );

                if ( ! empty( $existing ) && ! is_wp_error( $existing ) ) {
                    WP_CLI::log( sprintf( '  Skipping term %d/%d: %s (already exists)', $i + 1, $total, $name ) );
                    continue;
                }

                $result = wp_insert_term( $name, $taxonomy );

                if ( is_wp_error( $result ) ) {
                    // Term might already exist by name — get its ID.
                    $existing_term = get_term_by( 'name', $name, $taxonomy );
                    if ( $existing_term ) {
                        $term_id = $existing_term->term_id;
                    } else {
                        WP_CLI::warning( sprintf( '  Error importing term %s: %s', $name, $result->get_error_message() ) );
                        continue;
                    }
                } else {
                    $term_id = $result['term_id'];
                }

                update_term_meta( $term_id, '_drupal_tid', $tid );
                WP_CLI::log( sprintf( '  Imported term %d/%d: %s (tid %s → term_id %d)', $i + 1, $total, $name, $tid, $term_id ) );
            }

            WP_CLI::success( "Finished importing '{$taxonomy}' terms." );
        }
    }

    /**
     * Import recipes from recipes.json.
     */
    public function import_recipes() {
        $file = SAGACOOK_EXPORT_DIR . 'recipes.json';
        $data = json_decode( file_get_contents( $file ), true );

        if ( ! $data ) {
            WP_CLI::error( 'Could not read recipes.json' );
        }

        $total = count( $data );
        WP_CLI::log( "Importing {$total} recipes..." );

        foreach ( $data as $i => $recipe ) {
            $nid   = $recipe['nid'];
            $title = $recipe['title'];

            // Deduplication check.
            if ( $this->post_exists_by_nid( $nid, 'rezept' ) ) {
                WP_CLI::log( sprintf( '  Skipping recipe %d/%d: %s (nid %s already exists)', $i + 1, $total, $title, $nid ) );
                continue;
            }

            $post_id = wp_insert_post( [
                'post_title'   => $title,
                'post_content' => $recipe['beschreibung'] ?? '',
                'post_status'  => $recipe['status'] ?? 'publish',
                'post_date'    => $recipe['created'] ?? '',
                'post_type'    => 'rezept',
            ], true );

            if ( is_wp_error( $post_id ) ) {
                WP_CLI::warning( sprintf( '  Error importing recipe %s: %s', $title, $post_id->get_error_message() ) );
                continue;
            }

            // Post meta.
            update_post_meta( $post_id, '_drupal_nid', $nid );

            if ( ! empty( $recipe['rezeptlinien'] ) ) {
                update_post_meta( $post_id, '_rezeptlinien', $recipe['rezeptlinien'] );
            }

            if ( ! empty( $recipe['bild_essen'] ) ) {
                update_post_meta( $post_id, '_bild_essen', $recipe['bild_essen'] );
            }

            if ( ! empty( $recipe['bild_zutaten'] ) ) {
                update_post_meta( $post_id, '_bild_zutaten', $recipe['bild_zutaten'] );
            }

            // Taxonomy terms.
            if ( ! empty( $recipe['kategorien'] ) ) {
                $this->assign_terms( $post_id, $recipe['kategorien'], 'kategorie' );
            }

            if ( ! empty( $recipe['regionen'] ) ) {
                $this->assign_terms( $post_id, $recipe['regionen'], 'region' );
            }

            if ( ! empty( $recipe['stichworte'] ) ) {
                $this->assign_terms( $post_id, $recipe['stichworte'], 'tags' );
            }

            WP_CLI::log( sprintf( '  Importing recipe %d/%d: %s', $i + 1, $total, $title ) );
        }

        WP_CLI::success( "Finished importing {$total} recipes." );
    }

    /**
     * Import products from produkte.json.
     */
    public function import_products() {
        $file = SAGACOOK_EXPORT_DIR . 'produkte.json';
        $data = json_decode( file_get_contents( $file ), true );

        if ( ! $data ) {
            WP_CLI::error( 'Could not read produkte.json' );
        }

        $total = count( $data );
        WP_CLI::log( "Importing {$total} products..." );

        foreach ( $data as $i => $product ) {
            $nid   = $product['nid'];
            $title = $product['title'];

            if ( $this->post_exists_by_nid( $nid, 'produkt' ) ) {
                WP_CLI::log( sprintf( '  Skipping product %d/%d: %s (nid %s already exists)', $i + 1, $total, $title, $nid ) );
                continue;
            }

            $post_id = wp_insert_post( [
                'post_title'   => $title,
                'post_content' => $product['beschreibung'] ?? '',
                'post_status'  => $product['status'] ?? 'publish',
                'post_date'    => $product['created'] ?? '',
                'post_type'    => 'produkt',
            ], true );

            if ( is_wp_error( $post_id ) ) {
                WP_CLI::warning( sprintf( '  Error importing product %s: %s', $title, $post_id->get_error_message() ) );
                continue;
            }

            update_post_meta( $post_id, '_drupal_nid', $nid );

            if ( ! empty( $product['produktbild'] ) ) {
                update_post_meta( $post_id, '_produktbild', $product['produktbild'] );
            }

            WP_CLI::log( sprintf( '  Importing product %d/%d: %s', $i + 1, $total, $title ) );
        }

        WP_CLI::success( "Finished importing {$total} products." );
    }

    /**
     * Import pages from pages.json.
     */
    public function import_pages() {
        $file = SAGACOOK_EXPORT_DIR . 'pages.json';
        $data = json_decode( file_get_contents( $file ), true );

        if ( ! $data ) {
            WP_CLI::error( 'Could not read pages.json' );
        }

        $total = count( $data );
        WP_CLI::log( "Importing {$total} pages..." );

        foreach ( $data as $i => $page ) {
            $nid   = $page['nid'];
            $title = $page['title'];

            if ( $this->post_exists_by_nid( $nid, 'page' ) ) {
                WP_CLI::log( sprintf( '  Skipping page %d/%d: %s (nid %s already exists)', $i + 1, $total, $title, $nid ) );
                continue;
            }

            $post_id = wp_insert_post( [
                'post_title'   => $title,
                'post_content' => $page['body'] ?? '',
                'post_status'  => $page['status'] ?? 'publish',
                'post_date'    => $page['created'] ?? '',
                'post_type'    => 'page',
            ], true );

            if ( is_wp_error( $post_id ) ) {
                WP_CLI::warning( sprintf( '  Error importing page %s: %s', $title, $post_id->get_error_message() ) );
                continue;
            }

            update_post_meta( $post_id, '_drupal_nid', $nid );

            WP_CLI::log( sprintf( '  Importing page %d/%d: %s', $i + 1, $total, $title ) );
        }

        WP_CLI::success( "Finished importing {$total} pages." );
    }

    /**
     * Import recipe images into the media library and set as featured images.
     */
    public function import_images() {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $posts = get_posts( [
            'post_type'      => 'rezept',
            'post_status'    => 'any',
            'posts_per_page' => -1,
        ] );

        $total = count( $posts );

        // --- _bild_essen (featured image) ---
        WP_CLI::log( "=== Importing _bild_essen for {$total} recipes ===" );
        $imported = 0;
        $skipped  = 0;
        $errors   = 0;

        foreach ( $posts as $i => $post ) {
            $index = $i + 1;

            if ( has_post_thumbnail( $post->ID ) ) {
                $skipped++;
                continue;
            }

            $bild_essen = get_post_meta( $post->ID, '_bild_essen', true );
            if ( empty( $bild_essen ) ) {
                $skipped++;
                continue;
            }

            $attach_id = $this->import_single_image( $bild_essen, $post->ID );
            if ( is_wp_error( $attach_id ) ) {
                WP_CLI::warning( sprintf( '  Error %d/%d: %s — %s', $index, $total, $post->post_title, $attach_id->get_error_message() ) );
                $errors++;
                continue;
            }

            set_post_thumbnail( $post->ID, $attach_id );
            WP_CLI::log( sprintf( '  Imported %d/%d: %s → attachment %d', $index, $total, $post->post_title, $attach_id ) );
            $imported++;
        }

        WP_CLI::success( sprintf( '_bild_essen: %d imported, %d skipped, %d errors.', $imported, $skipped, $errors ) );

        // --- _bild_zutaten (ingredient image) ---
        WP_CLI::log( "=== Importing _bild_zutaten for {$total} recipes ===" );
        $imported = 0;
        $skipped  = 0;
        $errors   = 0;

        foreach ( $posts as $i => $post ) {
            $index = $i + 1;

            $existing = get_post_meta( $post->ID, '_bild_zutaten_id', true );
            if ( ! empty( $existing ) ) {
                $skipped++;
                continue;
            }

            $bild_zutaten = get_post_meta( $post->ID, '_bild_zutaten', true );
            if ( empty( $bild_zutaten ) ) {
                $skipped++;
                continue;
            }

            $attach_id = $this->import_single_image( $bild_zutaten, $post->ID );
            if ( is_wp_error( $attach_id ) ) {
                WP_CLI::warning( sprintf( '  Error %d/%d: %s — %s', $index, $total, $post->post_title, $attach_id->get_error_message() ) );
                $errors++;
                continue;
            }

            update_post_meta( $post->ID, '_bild_zutaten_id', $attach_id );
            WP_CLI::log( sprintf( '  Imported %d/%d: %s → attachment %d', $index, $total, $post->post_title, $attach_id ) );
            $imported++;
        }

        WP_CLI::success( sprintf( '_bild_zutaten: %d imported, %d skipped, %d errors.', $imported, $skipped, $errors ) );
    }

    /**
     * Import a single image: square it, upload, create attachment.
     * Returns attachment ID or WP_Error.
     */
    private function import_single_image( $relative_path, $parent_post_id ) {
        $file_path = SAGACOOK_EXPORT_DIR . $relative_path;

        if ( ! file_exists( $file_path ) ) {
            return new \WP_Error( 'not_found', 'file not found: ' . $file_path );
        }

        $squared = $this->make_square( $file_path );
        if ( ! $squared ) {
            return new \WP_Error( 'square_failed', 'failed to process image to square' );
        }

        $filename     = basename( $file_path );
        $file_content = file_get_contents( $squared );
        @unlink( $squared );
        $upload = wp_upload_bits( $filename, null, $file_content );

        if ( ! empty( $upload['error'] ) ) {
            return new \WP_Error( 'upload_failed', $upload['error'] );
        }

        $filetype  = wp_check_filetype( $upload['file'] );
        $attach_id = wp_insert_attachment( [
            'post_mime_type' => $filetype['type'],
            'post_title'     => sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ], $upload['file'], $parent_post_id );

        if ( is_wp_error( $attach_id ) ) {
            return $attach_id;
        }

        $metadata = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
        wp_update_attachment_metadata( $attach_id, $metadata );

        return $attach_id;
    }

    /**
     * Check if a post with the given Drupal nid already exists.
     */
    private function post_exists_by_nid( $nid, $post_type ) {
        $query = new WP_Query( [
            'post_type'      => $post_type,
            'post_status'    => 'any',
            'meta_key'       => '_drupal_nid',
            'meta_value'     => $nid,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );

        return $query->have_posts();
    }

    /**
     * Pad an image to a square with white background, expanding the shorter side.
     * Returns the path to a temporary squared JPEG, or false on failure.
     */
    private function make_square( $file_path ) {
        $info = @getimagesize( $file_path );
        if ( ! $info ) {
            return false;
        }

        $width  = $info[0];
        $height = $info[1];
        $mime   = $info['mime'];

        // Already square — just return a copy.
        if ( $width === $height ) {
            $tmp = tempnam( sys_get_temp_dir(), 'sq_' ) . '.jpg';
            copy( $file_path, $tmp );
            return $tmp;
        }

        switch ( $mime ) {
            case 'image/jpeg':
                $src = imagecreatefromjpeg( $file_path );
                break;
            case 'image/png':
                $src = imagecreatefrompng( $file_path );
                break;
            case 'image/gif':
                $src = imagecreatefromgif( $file_path );
                break;
            case 'image/webp':
                $src = imagecreatefromwebp( $file_path );
                break;
            default:
                return false;
        }

        if ( ! $src ) {
            return false;
        }

        $side = max( $width, $height );
        $dst  = imagecreatetruecolor( $side, $side );
        $white = imagecolorallocate( $dst, 255, 255, 255 );
        imagefill( $dst, 0, 0, $white );

        $offset_x = (int) ( ( $side - $width ) / 2 );
        $offset_y = (int) ( ( $side - $height ) / 2 );
        imagecopy( $dst, $src, $offset_x, $offset_y, 0, 0, $width, $height );

        $tmp = tempnam( sys_get_temp_dir(), 'sq_' ) . '.jpg';
        imagejpeg( $dst, $tmp, 90 );

        imagedestroy( $src );
        imagedestroy( $dst );

        return $tmp;
    }

    /**
     * Assign taxonomy terms to a post by name, creating if necessary.
     */
    private function assign_terms( $post_id, $terms_data, $taxonomy ) {
        $term_ids = [];

        foreach ( $terms_data as $term_data ) {
            $name = $term_data['name'];
            $term = get_term_by( 'name', $name, $taxonomy );

            if ( ! $term ) {
                // Create the term if it doesn't exist.
                $result = wp_insert_term( $name, $taxonomy );
                if ( is_wp_error( $result ) ) {
                    WP_CLI::warning( sprintf( '  Could not create term %s in %s: %s', $name, $taxonomy, $result->get_error_message() ) );
                    continue;
                }
                $term_ids[] = (int) $result['term_id'];
            } else {
                $term_ids[] = (int) $term->term_id;
            }
        }

        if ( ! empty( $term_ids ) ) {
            wp_set_object_terms( $post_id, $term_ids, $taxonomy );
        }
    }
}
