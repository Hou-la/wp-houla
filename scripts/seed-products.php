<?php
// Hardening: never allow this diagnostic/dev script to run via a web request.
if ( php_sapi_name() !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Bulk-create 25 WooCommerce sample products via direct SQL.
 * Run: wp eval-file seed-products.php
 *
 * - Uses $wpdb->insert() — no WC hooks, no thumbnail generation
 * - Generates 1x1 colored placeholder images (instant)
 * - Entire script runs in ~2 seconds
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;
$start = microtime( true );

// ── Categories ───────────────────────────────────────────────
WP_CLI::log( '→ Creating categories...' );

function seed_cat( $name, $slug, $parent = 0 ) {
    $term = get_term_by( 'slug', $slug, 'product_cat' );
    if ( $term ) return $term->term_id;
    $r = wp_insert_term( $name, 'product_cat', [ 'slug' => $slug, 'parent' => $parent ] );
    return is_wp_error( $r ) ? 0 : $r['term_id'];
}

$cat_bijoux      = seed_cat( 'Bijoux', 'bijoux' );
$cat_accessoires = seed_cat( 'Accessoires', 'accessoires' );
$cat_vetements   = seed_cat( 'Vêtements', 'vetements' );
$cat_maison      = seed_cat( 'Maison & Déco', 'maison-deco' );
$cat_bracelets   = seed_cat( 'Bracelets', 'bracelets', $cat_bijoux );
$cat_colliers    = seed_cat( 'Colliers', 'colliers', $cat_bijoux );
$cat_bagues      = seed_cat( 'Bagues', 'bagues', $cat_bijoux );
$cat_boucles     = seed_cat( "Boucles d'oreilles", 'boucles-oreilles', $cat_bijoux );
$cat_sacs        = seed_cat( 'Sacs & Pochettes', 'sacs-pochettes', $cat_accessoires );
$cat_echarpes    = seed_cat( 'Écharpes & Foulards', 'echarpes-foulards', $cat_accessoires );
$cat_tshirts     = seed_cat( 'T-shirts', 't-shirts', $cat_vetements );
$cat_sweats      = seed_cat( 'Sweats & Pulls', 'sweats-pulls', $cat_vetements );

// ── Tiny placeholder image (1-pixel JPEG, ~631 bytes) ────────
function seed_image( $seed, $title ) {
    global $wpdb;

    // Check if already created
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wphoula_seed' AND meta_value = %s LIMIT 1",
        $seed
    ) );
    if ( $existing ) return (int) $existing;

    $upload_dir = wp_upload_dir();
    $filename   = "product-{$seed}.jpg";
    $filepath   = $upload_dir['path'] . '/' . $filename;

    // Deterministic color
    $hash = md5( $seed );
    $r = hexdec( substr( $hash, 0, 2 ) );
    $g = hexdec( substr( $hash, 2, 2 ) );
    $b = hexdec( substr( $hash, 4, 2 ) );

    // 200x200 solid color (fast, enough for dev)
    $img = imagecreatetruecolor( 200, 200 );
    $bg  = imagecolorallocate( $img, $r, $g, $b );
    imagefill( $img, 0, 0, $bg );
    imagejpeg( $img, $filepath, 70 );
    imagedestroy( $img );

    // Direct insert as attachment
    $wpdb->insert( $wpdb->posts, [
        'post_author'    => 1,
        'post_title'     => $title,
        'post_status'    => 'inherit',
        'post_type'      => 'attachment',
        'post_mime_type' => 'image/jpeg',
        'guid'           => $upload_dir['url'] . '/' . $filename,
        'post_date'      => current_time( 'mysql' ),
        'post_date_gmt'  => current_time( 'mysql', 1 ),
    ] );
    $att_id = (int) $wpdb->insert_id;
    if ( ! $att_id ) return 0;

    update_post_meta( $att_id, '_wp_attached_file', _wp_relative_upload_path( $filepath ) );
    update_post_meta( $att_id, '_wp_attachment_metadata', [
        'width' => 200, 'height' => 200,
        'file'  => _wp_relative_upload_path( $filepath ),
        'sizes' => [],
    ] );
    update_post_meta( $att_id, '_wphoula_seed', $seed );

    return $att_id;
}

// ── Create product via direct SQL ────────────────────────────
function seed_product( $data ) {
    global $wpdb;

    // Skip if SKU exists
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s LIMIT 1",
        $data['sku']
    ) );
    if ( $exists ) {
        WP_CLI::log( "  ✓ {$data['name']} (exists)" );
        return (int) $exists;
    }

    // Attachment
    $img_id = 0;
    if ( ! empty( $data['img_seed'] ) ) {
        $img_id = seed_image( $data['img_seed'], $data['name'] );
    }

    // Insert product post
    $wpdb->insert( $wpdb->posts, [
        'post_author'  => 1,
        'post_title'   => $data['name'],
        'post_excerpt' => $data['desc'] ?? '',
        'post_status'  => 'publish',
        'post_type'    => 'product',
        'post_name'    => sanitize_title( $data['name'] ),
        'post_date'    => current_time( 'mysql' ),
        'post_date_gmt'=> current_time( 'mysql', 1 ),
    ] );
    $id = (int) $wpdb->insert_id;
    if ( ! $id ) {
        WP_CLI::warning( "  ✗ Failed: {$data['name']}" );
        return 0;
    }

    // Core WC meta
    $stock = $data['stock'] ?? 50;
    $meta = [
        '_sku'              => $data['sku'],
        '_regular_price'    => $data['price'],
        '_price'            => ! empty( $data['sale_price'] ) ? $data['sale_price'] : $data['price'],
        '_manage_stock'     => 'yes',
        '_stock'            => $stock,
        '_stock_status'     => $stock > 0 ? 'instock' : 'outofstock',
        '_visibility'       => 'visible',
        '_virtual'          => ! empty( $data['virtual'] ) ? 'yes' : 'no',
        '_downloadable'     => ! empty( $data['downloadable'] ) ? 'yes' : 'no',
    ];
    if ( ! empty( $data['sale_price'] ) ) {
        $meta['_sale_price'] = $data['sale_price'];
    }
    if ( ! empty( $data['weight'] ) ) {
        $meta['_weight'] = $data['weight'];
    }
    if ( $img_id > 0 ) {
        $meta['_thumbnail_id'] = $img_id;
    }
    if ( ! empty( $data['ean'] ) ) {
        $meta['_global_unique_id'] = $data['ean'];
    }

    foreach ( $meta as $key => $value ) {
        $wpdb->insert( $wpdb->postmeta, [
            'post_id'    => $id,
            'meta_key'   => $key,
            'meta_value' => $value,
        ] );
    }

    // Category assignment
    if ( ! empty( $data['cat_ids'] ) ) {
        foreach ( (array) $data['cat_ids'] as $cat_id ) {
            if ( $cat_id > 0 ) {
                $wpdb->replace( $wpdb->term_relationships, [
                    'object_id'        => $id,
                    'term_taxonomy_id' => $cat_id,
                ] );
            }
        }
    }

    // Product type term (simple)
    static $simple_tt_id = null;
    if ( $simple_tt_id === null ) {
        $simple_term = get_term_by( 'slug', 'simple', 'product_type' );
        $simple_tt_id = $simple_term ? $simple_term->term_taxonomy_id : 0;
    }
    if ( $simple_tt_id > 0 ) {
        $wpdb->replace( $wpdb->term_relationships, [
            'object_id'        => $id,
            'term_taxonomy_id' => $simple_tt_id,
        ] );
    }

    // Product visibility term
    static $visible_tt_id = null;
    if ( $visible_tt_id === null ) {
        $vt = get_term_by( 'slug', 'visible', 'product_visibility' );
        if ( ! $vt ) {
            wp_insert_term( 'visible', 'product_visibility', [ 'slug' => 'visible' ] );
            $vt = get_term_by( 'slug', 'visible', 'product_visibility' );
        }
        $visible_tt_id = $vt ? $vt->term_taxonomy_id : 0;
    }
    if ( $visible_tt_id > 0 ) {
        $wpdb->replace( $wpdb->term_relationships, [
            'object_id'        => $id,
            'term_taxonomy_id' => $visible_tt_id,
        ] );
    }

    $dp = $data['price'] . '€';
    if ( ! empty( $data['sale_price'] ) ) $dp = $data['sale_price'] . '€ (was ' . $data['price'] . '€)';
    $img_flag = $img_id > 0 ? '📷' : '';
    WP_CLI::log( "  ✓ {$data['name']} — {$data['sku']} — $dp — stock:$stock — $img_flag" );

    return $id;
}

// ── Product definitions ──────────────────────────────────────
WP_CLI::log( '→ Creating 25 products...' );

$products = [
    [ 'name' => 'Bracelet Perles Naturelles', 'price' => '29.90', 'sku' => 'BPN-001', 'desc' => 'Bracelet en perles naturelles fait main, taille ajustable. Pierre: agate bleue.', 'cat_ids' => [ $cat_bracelets ], 'stock' => 50, 'weight' => '0.05', 'img_seed' => 'bracelet-perles', 'ean' => '3760123456789' ],
    [ 'name' => 'Bracelet Jonc Doré', 'price' => '39.00', 'sku' => 'BJD-002', 'desc' => 'Bracelet jonc en plaqué or 18 carats, diamètre 6cm. Finition polie miroir.', 'cat_ids' => [ $cat_bracelets ], 'stock' => 30, 'sale_price' => '29.00', 'weight' => '0.03', 'img_seed' => 'bracelet-jonc', 'ean' => '3760123456796' ],
    [ 'name' => 'Bracelet Cuir Tressé Homme', 'price' => '25.00', 'sku' => 'BCT-003', 'desc' => 'Bracelet en cuir véritable tressé avec fermoir magnétique en acier inoxydable.', 'cat_ids' => [ $cat_bracelets ], 'stock' => 80, 'weight' => '0.04', 'img_seed' => 'bracelet-cuir', 'ean' => '3760123456802' ],

    [ 'name' => 'Collier Lune Dorée', 'price' => '45.00', 'sku' => 'CLD-004', 'desc' => 'Collier pendentif lune en plaqué or, chaîne 45cm réglable. Emballage cadeau inclus.', 'cat_ids' => [ $cat_colliers ], 'stock' => 25, 'weight' => '0.02', 'img_seed' => 'collier-lune', 'ean' => '3760123456819' ],
    [ 'name' => 'Collier Perle Baroque', 'price' => '65.00', 'sku' => 'CPB-005', 'desc' => 'Collier perle baroque d\'eau douce sur chaîne argent 925 rhodié. Pièce unique.', 'cat_ids' => [ $cat_colliers ], 'stock' => 10, 'sale_price' => '52.00', 'weight' => '0.03', 'img_seed' => 'collier-perle', 'ean' => '3760123456826' ],
    [ 'name' => 'Chaîne Maille Figaro', 'price' => '35.00', 'sku' => 'CMF-006', 'desc' => 'Chaîne maille figaro en acier inoxydable doré, longueur 50cm. Garantie 2 ans.', 'cat_ids' => [ $cat_colliers ], 'stock' => 60, 'weight' => '0.04', 'img_seed' => 'chaine-maille', 'ean' => '3760123456833' ],

    [ 'name' => 'Bague Fleur de Lotus', 'price' => '19.90', 'sku' => 'BFL-007', 'desc' => 'Bague ajustable en argent 925, motif fleur de lotus ajouré. Tailles 48 à 56.', 'cat_ids' => [ $cat_bagues ], 'stock' => 45, 'weight' => '0.01', 'img_seed' => 'bague-lotus', 'ean' => '3760123456840' ],
    [ 'name' => 'Bague Chevalière Onyx', 'price' => '55.00', 'sku' => 'BCO-008', 'desc' => 'Chevalière homme en acier 316L, pierre onyx noire naturelle. Tailles 60 à 68.', 'cat_ids' => [ $cat_bagues ], 'stock' => 20, 'weight' => '0.02', 'img_seed' => 'bague-onyx', 'ean' => '3760123456857' ],

    [ 'name' => 'Boucles Étoiles Argent', 'price' => '24.50', 'sku' => 'BEA-009', 'desc' => 'Boucles d\'oreilles étoiles en argent 925 massif. Fermoir poussette. Hypoallergénique.', 'cat_ids' => [ $cat_boucles ], 'stock' => 40, 'weight' => '0.01', 'img_seed' => 'boucles-etoile', 'ean' => '3760123456864' ],
    [ 'name' => 'Créoles Dorées Larges', 'price' => '32.00', 'sku' => 'CDL-010', 'desc' => 'Créoles plaqué or 3 microns, diamètre 4cm. Style minimaliste chic. Fermoir clapet.', 'cat_ids' => [ $cat_boucles ], 'stock' => 35, 'sale_price' => '24.00', 'weight' => '0.01', 'img_seed' => 'creoles-dorees', 'ean' => '3760123456871' ],

    [ 'name' => 'Pochette Velours Noir', 'price' => '15.00', 'sku' => 'PVN-011', 'desc' => 'Pochette cadeau en velours noir avec cordon doré tressé. 12x9cm.', 'cat_ids' => [ $cat_sacs ], 'stock' => 200, 'weight' => '0.02', 'img_seed' => 'pochette-velours', 'ean' => '3760123456888' ],
    [ 'name' => 'Sac Bandoulière Cuir', 'price' => '89.00', 'sku' => 'SBC-012', 'desc' => 'Sac bandoulière en cuir de vachette grainé. 22x15x6cm. Bandoulière réglable.', 'cat_ids' => [ $cat_sacs ], 'stock' => 15, 'sale_price' => '69.00', 'weight' => '0.35', 'img_seed' => 'sac-cuir', 'ean' => '3760123456895' ],
    [ 'name' => 'Trousse Maquillage Fleurie', 'price' => '18.50', 'sku' => 'TMF-013', 'desc' => 'Trousse de toilette motif floral, doublure imperméable. 20x12x8cm.', 'cat_ids' => [ $cat_sacs ], 'stock' => 55, 'weight' => '0.08', 'img_seed' => 'trousse-fleurie', 'ean' => '3760123456901' ],

    [ 'name' => 'Foulard Soie Imprimé', 'price' => '42.00', 'sku' => 'FSI-014', 'desc' => 'Foulard 100% soie naturelle, imprimé géométrique exclusif. 90x90cm. Fabriqué en France.', 'cat_ids' => [ $cat_echarpes ], 'stock' => 20, 'weight' => '0.06', 'img_seed' => 'foulard-soie', 'ean' => '3760123456918' ],
    [ 'name' => 'Écharpe Laine Mérinos', 'price' => '55.00', 'sku' => 'ELM-015', 'desc' => 'Écharpe mérinos extra-fine 19.5 microns. 180x30cm. Certificat OEKO-TEX.', 'cat_ids' => [ $cat_echarpes ], 'stock' => 12, 'sale_price' => '39.90', 'weight' => '0.12', 'img_seed' => 'echarpe-laine', 'ean' => '3760123456925' ],

    [ 'name' => 'T-shirt Coton Bio Blanc', 'price' => '28.00', 'sku' => 'TCB-016', 'desc' => 'T-shirt unisexe coton biologique certifié GOTS. Col rond. 155g/m².', 'cat_ids' => [ $cat_tshirts ], 'stock' => 100, 'weight' => '0.18', 'img_seed' => 'tshirt-blanc', 'ean' => '3760123456932' ],
    [ 'name' => 'T-shirt Oversize Noir', 'price' => '32.00', 'sku' => 'TON-017', 'desc' => 'T-shirt oversize unisexe noir, coton peigné 220g/m². Logo brodé poitrine.', 'cat_ids' => [ $cat_tshirts ], 'stock' => 75, 'weight' => '0.22', 'img_seed' => 'tshirt-noir', 'ean' => '3760123456949' ],

    [ 'name' => 'Sweat Capuche Bleu Marine', 'price' => '59.00', 'sku' => 'SCB-018', 'desc' => 'Sweat à capuche unisexe molleton brossé 300g/m². Poche kangourou. XS à XXL.', 'cat_ids' => [ $cat_sweats ], 'stock' => 40, 'weight' => '0.45', 'img_seed' => 'sweat-bleu', 'ean' => '3760123456956' ],
    [ 'name' => 'Pull Col Roulé Crème', 'price' => '49.90', 'sku' => 'PCC-019', 'desc' => 'Pull col roulé maille fine, 60% laine recyclée 40% acrylique. Lavable 30°.', 'cat_ids' => [ $cat_sweats ], 'stock' => 0, 'weight' => '0.30', 'img_seed' => 'pull-creme', 'ean' => '3760123456963' ],

    [ 'name' => 'Bougie Parfumée Ambre', 'price' => '22.00', 'sku' => 'BPA-020', 'desc' => 'Bougie artisanale parfum ambre et bois de santal. Cire de soja. 180g, durée ~40h.', 'cat_ids' => [ $cat_maison ], 'stock' => 90, 'weight' => '0.25', 'img_seed' => 'bougie-ambre', 'ean' => '3760123456970' ],
    [ 'name' => 'Diffuseur Huiles Essentielles', 'price' => '38.00', 'sku' => 'DHE-021', 'desc' => 'Diffuseur céramique blanche mate, bâtonnets rotin. Lavande/eucalyptus. 200ml.', 'cat_ids' => [ $cat_maison ], 'stock' => 25, 'sale_price' => '29.90', 'weight' => '0.40', 'img_seed' => 'diffuseur-huile', 'ean' => '3760123456987' ],
    [ 'name' => 'Mug Artisanal Grès', 'price' => '16.00', 'sku' => 'MAG-022', 'desc' => 'Mug grès émaillé fait main. 350ml. Lave-vaisselle et micro-ondes. Bleu océan.', 'cat_ids' => [ $cat_maison ], 'stock' => 65, 'weight' => '0.35', 'img_seed' => 'mug-gres', 'ean' => '3760123456994' ],

    [ 'name' => 'Guide Entretien Bijoux (PDF)', 'price' => '4.90', 'sku' => 'GEB-023', 'desc' => 'Guide PDF 25 pages: entretenir bijoux argent, or et pierres. Téléchargement immédiat.', 'cat_ids' => [ $cat_accessoires ], 'stock' => 999, 'img_seed' => 'guide-bijoux', 'ean' => '3760123457007', 'virtual' => true, 'downloadable' => true ],
    [ 'name' => 'Carte Cadeau 50€', 'price' => '50.00', 'sku' => 'GFT-024', 'desc' => 'Carte cadeau 50€ sur toute la boutique. Validité 1 an. Code envoyé par email.', 'cat_ids' => [], 'stock' => 999, 'img_seed' => 'carte-cadeau', 'virtual' => true ],

    [ 'name' => 'Bracelet Édition Limitée Or Rose', 'price' => '79.00', 'sku' => 'BEL-025', 'desc' => 'Bracelet édition limitée plaqué or rose 18K, série numérotée. Coffret luxe inclus.', 'cat_ids' => [ $cat_bracelets ], 'stock' => 0, 'weight' => '0.04', 'img_seed' => 'bracelet-or-rose', 'ean' => '3760123457014' ],
];

$created = 0;
foreach ( $products as $p ) {
    if ( seed_product( $p ) > 0 ) $created++;
}

// Recount terms
$cats = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids' ] );
if ( ! is_wp_error( $cats ) ) wp_update_term_count_now( $cats, 'product_cat' );

// Clear WC transients
if ( function_exists( 'wc_delete_product_transients' ) ) {
    wc_delete_product_transients();
}

$elapsed = round( microtime( true ) - $start, 1 );
WP_CLI::success( "$created products created in {$elapsed}s" );
