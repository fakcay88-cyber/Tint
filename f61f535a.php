<?php
/**
 * Plugin Name: TintTotaal Bridge
 * Description: REST-brug die de Android-app 1-op-1 synchroniseert met de tint-afspraakmodule op tinttotaal.nl. Eén bron van waarheid: WordPress.
 * Version: 1.0.0
 * Author: TintTotaal
 *
 * ENDPOINTS (namespace /wp-json/tinttotaal/v1):
 *   GET  /config                 -> pakketten, prijzen, opties, tintpercentages
 *   GET  /availability?month=YYYY-MM -> beschikbare dagen + tijdsloten
 *   GET  /vehicle/{kenteken}     -> RDW-voertuiggegevens (proxy + cache)
 *   POST /discount               -> kortingscode valideren (server-side)
 *   POST /booking                -> boeking aanmaken (prijs wordt server-side herberekend)
 *   GET  /booking/{ref}          -> boekingsstatus (voor bevestigingsscherm app)
 *   POST /payment/{ref}          -> Mollie-checkout voor de aanbetaling
 *   POST /mollie-webhook         -> publiek: statusupdate van Mollie
 *
 * BEVEILIGING: elke request (behalve de Mollie-webhook) heeft header X-TT-API-Key nodig.
 * De key staat na activatie in wp_options onder 'tt_bridge_api_key'.
 * Overschrijven kan in wp-config.php: define('TT_BRIDGE_API_KEY', 'jouw-key');
 * Voor betalen: define('TT_MOLLIE_KEY', 'live_...of test_...');
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ------------------------------------------------------------------
 * 1. ACTIVATIE — boekingen in dezelfde database als de webmodule
 * ------------------------------------------------------------------ */
register_activation_hook( __FILE__, 'tt_bridge_activate' );
function tt_bridge_activate() {
    global $wpdb;
    $table   = $wpdb->prefix . 'tinttotaal_bookings';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        booking_ref VARCHAR(24) NOT NULL,
        source VARCHAR(10) NOT NULL DEFAULT 'app',
        modelsoort VARCHAR(40) NULL,
        voorruit_optie VARCHAR(40) NULL,
        tint_percentage VARCHAR(8) NULL,
        pakketten VARCHAR(255) NULL,
        extra_opties VARCHAR(255) NULL,
        totaalbedrag DECIMAL(10,2) NOT NULL DEFAULT 0,
        korting DECIMAL(10,2) NOT NULL DEFAULT 0,
        aanbetaling DECIMAL(10,2) NOT NULL DEFAULT 50,
        korting_code VARCHAR(40) NULL,
        datum DATE NULL,
        tijd TIME NULL,
        voornaam VARCHAR(100) NULL,
        achternaam VARCHAR(100) NULL,
        email VARCHAR(190) NULL,
        telefoon VARCHAR(40) NULL,
        kenteken VARCHAR(12) NULL,
        merk VARCHAR(80) NULL,
        automodel VARCHAR(120) NULL,
        bouwjaar VARCHAR(10) NULL,
        kleur VARCHAR(60) NULL,
        opmerkingen TEXT NULL,
        mollie_payment_id VARCHAR(40) NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'awaiting_payment',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY booking_ref (booking_ref),
        KEY datum_tijd (datum, tijd)
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    if ( ! get_option( 'tt_bridge_api_key' ) ) {
        update_option( 'tt_bridge_api_key', 'ttk_' . wp_generate_password( 36, false, false ) );
    }
}

function tt_bridge_api_key() {
    if ( defined( 'TT_BRIDGE_API_KEY' ) ) return TT_BRIDGE_API_KEY;
    return (string) get_option( 'tt_bridge_api_key' );
}

function tt_bridge_can( WP_REST_Request $request ) {
    $key = $request->get_header( 'X-TT-API-Key' );
    if ( ! $key || ! hash_equals( tt_bridge_api_key(), $key ) ) {
        return new WP_Error( 'tt_forbidden', 'Ongeldige API-key.', [ 'status' => 403 ] );
    }
    return true;
}

/* ------------------------------------------------------------------
 * 2. CONFIG — spiegelt de webmodule 1-op-1
 *    TODO: vul de echte prijzen in zoals de webmodule ze gebruikt.
 *    Via apply_filters() kun je alles uit je bestaande module halen.
 * ------------------------------------------------------------------ */
function tt_bridge_config() {
    $config = [
        'aanbetaling' => 50.00,
        'modelsoorten' => [
            [ 'key' => 'hatchback3', 'label' => 'Hatchback (3 deurs)', 'base' => 0.00 ],
            [ 'key' => 'hatchback5', 'label' => 'Hatchback (5 deurs)', 'base' => 0.00 ],
            [ 'key' => 'sedan',      'label' => 'Sedan/Coupé',         'base' => 0.00 ],
            [ 'key' => 'station',    'label' => 'Station',             'base' => 0.00 ],
            [ 'key' => 'suv',        'label' => 'SUV/MPV',             'base' => 0.00 ],
            [ 'key' => 'overige',    'label' => 'Overige',             'base' => 0.00 ],
        ],
        'tint_percentages' => [ '70', '35', '20', '5' ],
        'tint_labels' => [
            '70' => 'Licht 70%',
            '35' => 'Medium 35%',
            '20' => 'Donker 20%',
            '5'  => 'Privacy 5% (meest gekozen)',
        ],
        'voorruit_opties' => [ '70%', 'Sky Blue', 'Red Lava', 'Sunset Orange', 'Forest Green' ],
        'pakketten' => [
            [ 'key' => 'a_stijl',   'label' => 'A-stijl',   'hint' => 'Voorramen',      'note' => 'Max 70% toegestaan', 'prijzen' => [] ],
            [ 'key' => 'b_stijl',   'label' => 'B-stijl',   'hint' => 'Achterramen',    'note' => null,                 'prijzen' => [] ],
            [ 'key' => 'voorruit',  'label' => 'Voorruit',  'hint' => 'Volledige voorruit', 'note' => 'Max 70% toegestaan', 'prijzen' => [] ],
            [ 'key' => 'dakraam',  'label' => 'Dakraam',   'hint' => 'Glazen dak',     'note' => null,                 'prijzen' => [] ],
        ],
        'extra_opties' => [
            [ 'key' => 'xr_ceramic',  'label' => 'XR Ceramic',  'omschrijving' => 'Hoog warmtewerende keramische folie', 'prijs' => 0.00 ],
            [ 'key' => 'zonneband',   'label' => 'Zonneband',   'omschrijving' => null, 'prijs' => 105.00 ],
            [ 'key' => 'koplampen',   'label' => 'Koplampen tinten',   'omschrijving' => null, 'prijs' => 235.00 ],
            [ 'key' => 'achterlichten', 'label' => 'Achterlichten tinten', 'omschrijving' => null, 'prijs' => 275.00 ],
        ],
    ];
    // Koppel hier je bestaande module aan: haal prijzen/pakketten uit de plek
    // waar de webmodule ze vandaan haalt, zodat app en web identiek zijn.
    return apply_filters( 'tinttotaal_bridge_config', $config );
}

function tt_bridge_find( array $list, $key, $value ) {
    foreach ( $list as $item ) {
        if ( ( $item['key'] ?? null ) === $value ) return $item;
    }
    return null;
}

/* ------------------------------------------------------------------
 * 3. PRIJSBEREKENING — server-side, zodat de app nooit kan sjoemelen
 * ------------------------------------------------------------------ */
function tt_bridge_calc( $config, $modelsoort, array $pakketten, array $extra_opties, $code ) {
    $totaal = 0.00;
    foreach ( $pakketten as $pk ) {
        $p = tt_bridge_find( $config['pakketten'], 'key', $pk );
        if ( $p ) {
            $totaal += (float) ( $p['prijzen'][ $modelsoort ] ?? 0 );
        }
    }
    foreach ( $extra_opties as $eo ) {
        $o = tt_bridge_find( $config['extra_opties'], 'key', $eo );
        if ( $o ) $totaal += (float) $o['prijs'];
    }

    // Kortingscodes — bewust NIET in /config, alleen server-side
    $korting   = 0.00;
    $kort_type = null;
    $code_up   = strtoupper( trim( (string) $code ) );
    if ( 'WELKOMTINT10' === $code_up ) {
        $kort_type = 'percent';
        $korting   = round( $totaal * 0.10, 2 );
    } elseif ( 'WELKOMTINTGRATIS' === $code_up ) {
        // Gratis zonneband bij online boeking
        if ( in_array( 'zonneband', $extra_opties, true ) ) {
            $kort_type = 'gratis_zonneband';
            $korting   = 105.00;
        }
    }
    return [
        'totaal'     => $totaal,
        'korting'    => $korting,
        'kort_type'  => $kort_type,
        'aanbetaling' => (float) $config['aanbetaling'],
        'resterend'  => max( 0, $totaal - $korting - (float) $config['aanbetaling'] ),
    ];
}

/* ------------------------------------------------------------------
 * 4. BESCHIKBAARHEID — zelfde sloten als de webmodule
 *    TODO: vervang de template door een koppeling met je echte agenda
 *    (bijv. via apply_filters of een query op de bestaande boekingen-
 *    tabel van de webmodule).
 * ------------------------------------------------------------------ */
function tt_bridge_availability( $month ) {
    global $wpdb;
    $table  = $wpdb->prefix . 'tinttotaal_bookings';
    $sloten = [ '09:00', '10:30', '12:00', '13:30', '15:00', '16:30' ];

    $taken = $wpdb->get_results( $wpdb->prepare(
        "SELECT datum, tijd FROM {$table}
         WHERE DATE_FORMAT(datum, '%%Y-%%m') = %s AND status NOT IN ('geannuleerd','cancelled')",
        $month
    ) );
    $bezet = [];
    foreach ( (array) $taken as $t ) {
        $bezet[ $t->datum ][ $t->tijd ] = true;
    }

    $days   = [];
    $start  = new DateTime( $month . '-01' );
    $end    = ( clone $start )->modify( 'last day of this month' );
    $now    = new DateTime( 'now', new DateTimeZone( 'Europe/Amsterdam' ) );
    for ( $d = $start; $d <= $end; $d->modify( '+1 day' ) ) {
        $date  = $d->format( 'Y-m-d' );
        $slots = [];
        foreach ( $sloten as $s ) {
            $slot_dt = DateTime::createFromFormat( 'Y-m-d H:i', $date . ' ' . $s, new DateTimeZone( 'Europe/Amsterdam' ) );
            $slots[] = [
                'time'      => $s,
                'available' => empty( $bezet[ $date ][ $s ] ) && $slot_dt > $now,
            ];
        }
        $days[] = [ 'date' => $date, 'slots' => $slots ];
    }
    // Koppel hier je echte agenda (Google Calendar, eigen planningstool, etc.)
    return apply_filters( 'tinttotaal_bridge_availability', $days, $month );
}

/* ------------------------------------------------------------------
 * 5. RDW-KENTEKENPROXY (met cache)
 * ------------------------------------------------------------------ */
function tt_bridge_vehicle( $kenteken ) {
    $plate = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', $kenteken ) );
    if ( strlen( $plate ) < 5 ) {
        return new WP_Error( 'tt_kenteken', 'Ongeldig kenteken.', [ 'status' => 400 ] );
    }
    $cache_key = 'tt_rdw_' . $plate;
    $cached    = get_transient( $cache_key );
    if ( $cached ) return rest_ensure_response( $cached );

    $res = wp_remote_get( add_query_arg( 'kenteken', $plate, 'https://opendata.rdw.nl/resource/m9v7-vj6c.json' ) );
    if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
        return new WP_Error( 'tt_rdw', 'RDW momenteel niet bereikbaar.', [ 'status' => 502 ] );
    }
    $rows = json_decode( wp_remote_retrieve_body( $res ), true );
    if ( empty( $rows[0] ) ) {
        $out = [ 'found' => false, 'kenteken' => $plate ];
        set_transient( $cache_key, $out, HOUR_IN_SECONDS );
        return rest_ensure_response( $out );
    }
    $r   = $rows[0];
    $out = [
        'found'        => true,
        'kenteken'     => $plate,
        'merk'         => $r['merk'] ?? null,
        'model'        => $r['handelsbenaming'] ?? null,
        'type_voertuig' => $r['voertuigsoort'] ?? null,
        'kleur'        => $r['kleur'] ?? ( $r['eerste_kleur'] ?? null ),
        'bouwjaar'     => substr( (string) ( $r['datum_eerste_toelating'] ?? '' ), 0, 4 ) ?: null,
    ];
    set_transient( $cache_key, $out, DAY_IN_SECONDS );
    return rest_ensure_response( $out );
}

/* ------------------------------------------------------------------
 * 6. MOLLIE — aanbetaling van €50
 * ------------------------------------------------------------------ */
function tt_bridge_mollie_key() {
    if ( defined( 'TT_MOLLIE_KEY' ) ) return TT_MOLLIE_KEY;
    return (string) get_option( 'tt_mollie_key' );
}

function tt_bridge_mollie_create( $booking ) {
    $key = tt_bridge_mollie_key();
    if ( ! $key ) {
        return new WP_Error( 'tt_mollie', 'Zet TT_MOLLIE_KEY in wp-config.php of tt_mollie_key in wp_options.', [ 'status' => 500 ] );
    }
    $body = [
        'amount'      => [ 'currency' => 'EUR', 'value' => number_format( (float) $booking->aanbetaling, 2, '.', '' ) ],
        'description' => 'TintTotaal aanbetaling ' . $booking->booking_ref,
        'redirectUrl' => home_url( '/?tt_redirect=' . $booking->booking_ref ),
        'webhookUrl'  => rest_url( TT_NS . '/mollie-webhook' ),
        'metadata'    => [ 'booking_ref' => $booking->booking_ref, 'source' => $booking->source ],
    ];
    $res = wp_remote_post( 'https://api.mollie.com/v2/payments', [
        'headers' => [ 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( $body ),
        'timeout' => 15,
    ] );
    if ( is_wp_error( $res ) || 201 !== wp_remote_retrieve_response_code( $res ) ) {
        return new WP_Error( 'tt_mollie', 'Mollie-payments aanmaken mislukt.', [ 'status' => 502 ] );
    }
    $data = json_decode( wp_remote_retrieve_body( $res ), true );
    return [
        'payment_id'  => $data['id'] ?? null,
        'checkout_url' => $data['_links']['checkout']['href'] ?? null,
        'status'      => $data['status'] ?? null,
    ];
}

/* ------------------------------------------------------------------
 * 7. ROUTES
 * ------------------------------------------------------------------ */
const TT_NS = 'tinttotaal/v1';

add_action( 'rest_api_init', function () {

    register_rest_route( TT_NS, '/config', [
        'methods'             => 'GET',
        'permission_callback' => 'tt_bridge_can',
        'callback'            => fn() => rest_ensure_response( tt_bridge_config() ),
    ] );

    register_rest_route( TT_NS, '/availability', [
        'methods'             => 'GET',
        'permission_callback' => 'tt_bridge_can',
        'callback'            => function ( WP_REST_Request $req ) {
            $month = sanitize_text_field( $req->get_param( 'month' ) );
            if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
                return new WP_Error( 'tt_month', 'Geef month op als YYYY-MM.', [ 'status' => 400 ] );
            }
            return rest_ensure_response( [ 'month' => $month, 'days' => tt_bridge_availability( $month ) ] );
        },
    ] );

    register_rest_route( TT_NS, '/vehicle/(?P<kenteken>[A-Za-z0-9\-]+)', [
        'methods'             => 'GET',
        'permission_callback' => 'tt_bridge_can',
        'callback'            => fn( $req ) => tt_bridge_vehicle( $req['kenteken'] ),
    ] );

    register_rest_route( TT_NS, '/discount', [
        'methods'             => 'POST',
        'permission_callback' => 'tt_bridge_can',
        'callback'            => function ( WP_REST_Request $req ) {
            $config = tt_bridge_config();
            $b      = $req->get_json_params();
            $calc   = tt_bridge_calc(
                $config,
                sanitize_text_field( $b['modelsoort'] ?? '' ),
                array_map( 'sanitize_key', (array) ( $b['pakketten'] ?? [] ) ),
                array_map( 'sanitize_key', (array) ( $b['extra_opties'] ?? [] ) ),
                sanitize_text_field( $b['code'] ?? '' )
            );
            $valid = ( $calc['kort_type'] !== null );
            return rest_ensure_response( [
                'valid'         => $valid,
                'type'          => $calc['kort_type'],
                'kortingsbedrag' => $calc['korting'],
                'resterend'     => $calc['resterend'],
                'message'       => $valid ? 'Kortingscode toegepast.' : 'Ongeldige of niet-geldige kortingscode.',
            ] );
        },
    ] );

    register_rest_route( TT_NS, '/booking', [
        'methods'             => 'POST',
        'permission_callback' => 'tt_bridge_can',
        'callback'            => 'tt_bridge_create_booking',
    ] );

    register_rest_route( TT_NS, '/booking/(?P<ref>[A-Za-z0-9\-]+)', [
        'methods'             => 'GET',
        'permission_callback' => 'tt_bridge_can',
        'callback'            => 'tt_bridge_get_booking',
    ] );

    register_rest_route( TT_NS, '/payment/(?P<ref>[A-Za-z0-9\-]+)', [
        'methods'             => 'POST',
        'permission_callback' => 'tt_bridge_can',
        'callback'            => function ( WP_REST_Request $req ) {
            global $wpdb;
            $table = $wpdb->prefix . 'tinttotaal_bookings';
            $b     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_ref = %s", $req['ref'] ) );
            if ( ! $b ) return new WP_Error( 'tt_404', 'Boeking niet gevonden.', [ 'status' => 404 ] );
            if ( 'confirmed' === $b->status ) {
                return rest_ensure_response( [ 'payment_id' => $b->mollie_payment_id, 'checkout_url' => null, 'status' => 'paid' ] );
            }
            $pay = tt_bridge_mollie_create( $b );
            if ( is_wp_error( $pay ) ) return $pay;
            $wpdb->update( $table, [ 'mollie_payment_id' => $pay['payment_id'] ], [ 'booking_ref' => $b->booking_ref ] );
            return rest_ensure_response( $pay );
        },
    ] );

    // Publiek — Mollie heeft geen API-key
    register_rest_route( TT_NS, '/mollie-webhook', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'tt_bridge_mollie_webhook',
    ] );
} );

function tt_bridge_create_booking( WP_REST_Request $req ) {
    global $wpdb;
    $config = tt_bridge_config();
    $b      = $req->get_json_params();

    $required = [ 'modelsoort', 'datum', 'tijd', 'voornaam', 'achternaam', 'email', 'telefoon' ];
    foreach ( $required as $f ) {
        if ( empty( $b[ $f ] ) ) return new WP_Error( 'tt_input', "Veld '{$f}' is verplicht.", [ 'status' => 400 ] );
    }
    $pakketten    = array_values( array_filter( array_map( 'sanitize_key', (array) ( $b['pakketten'] ?? [] ) ) ) );
    $extra_opties = array_values( array_filter( array_map( 'sanitize_key', (array) ( $b['extra_opties'] ?? [] ) ) ) );
    if ( empty( $pakketten ) ) return new WP_Error( 'tt_input', 'Kies minimaal één tintpakket.', [ 'status' => 400 ] );

    $modelsoort = sanitize_key( $b['modelsoort'] );
    if ( ! tt_bridge_find( $config['modelsoorten'], 'key', $modelsoort ) ) {
        return new WP_Error( 'tt_input', 'Onbekend modelsoort.', [ 'status' => 400 ] );
    }
    $datum = sanitize_text_field( $b['datum'] );
    $tijd  = sanitize_text_field( $b['tijd'] );
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $datum ) || ! preg_match( '/^\d{2}:\d{2}$/', $tijd ) ) {
        return new WP_Error( 'tt_input', 'Ongeldige datum of tijd.', [ 'status' => 400 ] );
    }

    // Dubbelboeking voorkomen — zelfde check als de webmodule zou moeten doen
    $table = $wpdb->prefix . 'tinttotaal_bookings';
    $bestaat = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table} WHERE datum = %s AND tijd = %s AND status NOT IN ('geannuleerd','cancelled')",
        $datum, $tijd
    ) );
    if ( $bestaat ) return new WP_Error( 'tt_slot', 'Dit tijdslot is net bezet geraakt. Kies een ander moment.', [ 'status' => 409 ] );

    // Prijs server-side herberekenen — app-bedrag is nooit leidend
    $calc = tt_bridge_calc( $config, $modelsoort, $pakketten, $extra_opties, sanitize_text_field( $b['korting_code'] ?? '' ) );

    $ref = 'TT' . gmdate( 'ymd' ) . '-' . strtoupper( wp_generate_password( 4, false, false ) );
    $ok  = $wpdb->insert( $table, [
        'booking_ref'     => $ref,
        'source'          => sanitize_key( $b['source'] ?? 'app' ),
        'modelsoort'      => $modelsoort,
        'voorruit_optie'  => sanitize_text_field( $b['voorruit_optie'] ?? '' ),
        'tint_percentage' => sanitize_text_field( $b['tint_percentage'] ?? '' ),
        'pakketten'       => implode( ',', $pakketten ),
        'extra_opties'    => implode( ',', $extra_opties ),
        'totaalbedrag'    => $calc['totaal'],
        'korting'         => $calc['korting'],
        'aanbetaling'      => $calc['aanbetaling'],
        'korting_code'    => sanitize_text_field( $b['korting_code'] ?? '' ),
        'datum'           => $datum,
        'tijd'            => $tijd,
        'voornaam'        => sanitize_text_field( $b['voornaam'] ),
        'achternaam'      => sanitize_text_field( $b['achternaam'] ),
        'email'           => sanitize_email( $b['email'] ),
        'telefoon'        => sanitize_text_field( $b['telefoon'] ),
        'kenteken'        => strtoupper( sanitize_text_field( $b['kenteken'] ?? '' ) ),
        'merk'            => sanitize_text_field( $b['merk'] ?? '' ),
        'automodel'       => sanitize_text_field( $b['automodel'] ?? '' ),
        'bouwjaar'        => sanitize_text_field( $b['bouwjaar'] ?? '' ),
        'kleur'           => sanitize_text_field( $b['kleur'] ?? '' ),
        'opmerkingen'     => sanitize_textarea_field( $b['opmerkingen'] ?? '' ),
        'created_at'      => current_time( 'mysql' ),
    ], [ '%s','%s','%s','%s','%s','%s','%s','%f','%f','%f','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s' ] );
    if ( ! $ok ) return new WP_Error( 'tt_db', 'Boeking opslaan mislukt.', [ 'status' => 500 ] );

    // Koppel hier je bestaande notificatie-flow (bevestigingsmail, adminmail, kalender)
    do_action( 'tinttotaal_booking_created', $wpdb->insert_id, $b, 'app' );

    return rest_ensure_response( [
        'booking_ref'  => $ref,
        'totaalbedrag' => $calc['totaal'],
        'korting'      => $calc['korting'],
        'aanbetaling'  => $calc['aanbetaling'],
        'resterend'    => $calc['resterend'],
    ] );
}

function tt_bridge_get_booking( WP_REST_Request $req ) {
    global $wpdb;
    $table = $wpdb->prefix . 'tinttotaal_bookings';
    $b     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_ref = %s", $req['ref'] ), ARRAY_A );
    if ( ! $b ) return new WP_Error( 'tt_404', 'Boeking niet gevonden.', [ 'status' => 404 ] );
    return rest_ensure_response( $b );
}

function tt_bridge_mollie_webhook( WP_REST_Request $req ) {
    global $wpdb;
    $id = $req->get_param( 'id' ); // Mollie stuurt alleen payment id
    $key = tt_bridge_mollie_key();
    if ( ! $id || ! $key ) return rest_ensure_response( [ 'ok' => false ] );

    $res = wp_remote_get( 'https://api.mollie.com/v2/payments/' . rawurlencode( $id ), [
        'headers' => [ 'Authorization' => 'Bearer ' . $key ],
        'timeout' => 15,
    ] );
    if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
        return new WP_Error( 'tt_mollie', 'Betaling ophalen mislukt.', [ 'status' => 502 ] );
    }
    $p   = json_decode( wp_remote_retrieve_body( $res ), true );
    $ref = $p['metadata']['booking_ref'] ?? null;
    if ( ! $ref ) return rest_ensure_response( [ 'ok' => true ] );

    $table = $wpdb->prefix . 'tinttotaal_bookings';
    $nieuwe_status = 'paid' === ( $p['status'] ?? '' ) ? 'confirmed' : ( $p['status'] ?? 'unknown' );
    $wpdb->update( $table, [ 'status' => $nieuwe_status ], [ 'mollie_payment_id' => $id ] );

    if ( 'confirmed' === $nieuwe_status ) {
        $booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE mollie_payment_id = %s", $id ) );
        // Verstuur hier (of via je bestaande flow) de bevestigingsmail
        do_action( 'tinttotaal_booking_confirmed', $booking, 'app' );
    }
    return rest_ensure_response( [ 'ok' => true, 'status' => $p['status'] ?? null ] );
}

/* ------------------------------------------------------------------
 * 8. TERUGSTUREN NA DE APP na Mollie-betaling
 *    Mollie redirect naar https://tinttotaal.nl/?tt_redirect=REF
 *    Deze pagina stuurt (mobiels) door naar tinttotaal://betaald?ref=REF
 * ------------------------------------------------------------------ */
add_action( 'template_redirect', function () {
    if ( ! isset( $_GET['tt_redirect'] ) ) return;
    $ref = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) wp_unslash( $_GET['tt_redirect'] ) );
    $app_url  = 'tinttotaal://betaald?ref=' . rawurlencode( $ref );
    $site_url = home_url( '/raamfolie/autoraamfolie/#stap-1' );
    header( 'Content-Type: text/html; charset=utf-8' );
    echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>TintTotaal</title></head>';
    echo '<body style="font-family:sans-serif;text-align:center;padding:3rem"><h2>Bedankt!</h2>';
    echo '<p>Je aanbetaling is verwerkt.</p>';
    echo '<script>location.replace(' . wp_json_encode( $app_url ) . ');</script>';
    echo '<p><a href="' . esc_url( $app_url ) . '">Terug naar de app</a> &middot; <a href="' . esc_url( $site_url ) . '">Naar de website</a></p>';
    echo '</body></html>';
    exit;
} );
