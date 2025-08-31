<?php
/**
 * Plugin Name: Woo Jinja Tickets (Processing Email + Attachments)
 * Description: Ticket-Produkte mit Pflicht-Attendee-Namen (pro Stück). PDFs werden bei Zahlungseingang generiert und an die Bestellbestätigung angehängt; zusätzlich Downloadlinks in der Mail.
 * Author: you
 * Version: 2.1.0
 */

if (!defined('ABSPATH')) { exit; }

/** =========
 *  SETTINGS
 *  =========
 * Passe diese Werte an deine Umgebung an.
 */
const WJT_JINJA_ENDPOINT = 'http://ticket-engine:8000/tickets/render'; // z.B. Docker-Netz-Name
const WJT_API_KEY        = 'supersecret123'; // muss zur Ticket-Engine (ENV API_KEY) passen
const WJT_ATTACH_PDF     = true;  // << PDF wirklich anhängen
const WJT_DEBUG_NOTES    = true;  // Bestellnotizen für Debug

// An welche Woo-Emails sollen Anhänge/Links gehen?
const WJT_EMAIL_TARGETS = [
    'customer_processing_order',  // Bestellbestätigung nach Zahlung
    'customer_completed_order',   // zusätzlich bei "abgeschlossen"
    'customer_paid_for_order',    // PayPal-Bestellungen (wichtig!)
    // 'customer_on_hold_order',   // optional: Vorkasse/Manuell prüfen
];

// interne Meta-Keys
const WJT_META_IS_TICKET = '_wjt_is_ticket';
const WJT_META_ATTENDEES = '_wjt_attendees'; // JSON-Array (deprecated)
const WJT_META_PDF_URL   = '_wjt_pdf_url_';  // + Index
const WJT_META_PDF_PATH  = '_wjt_pdf_path_'; // + Index
const WJT_META_UUID      = '_wjt_uuid_';     // + Index - für QR-Code-Validierung
const WJT_META_STATUS    = '_wjt_status_';   // + Index - Ticket-Status (active/disabled)
const WJT_META_TICKET_NUMBER = '_wjt_ticket_number_'; // + Index - eindeutige Ticket-Nummer
const WJT_META_SCAN_COUNT = '_wjt_scan_count_'; // + Index - Anzahl Scans
const WJT_META_FIRST_SCAN = '_wjt_first_scan_'; // + Index - erstes Scan-Datum

/** =========================================
 *  ADMIN: Checkbox "Ticket-Produkt" im Backend
 *  ========================================= */
add_action('woocommerce_product_options_general_product_data', function(){
    echo '<div class="options_group">';
    woocommerce_wp_checkbox([
        'id'          => WJT_META_IS_TICKET,
        'label'       => __('Ticket-Produkt', 'woo-jinja-tickets'),
        'description' => __('Wenn aktiv, werden bei Zahlungseingang automatisch Tickets generiert.', 'woo-jinja-tickets'),
    ]);
    echo '</div>';
});

add_action('woocommerce_admin_process_product_object', function($product){
    $product->update_meta_data(WJT_META_IS_TICKET, isset($_POST[WJT_META_IS_TICKET]) ? 'yes' : 'no');
});



/** ============================================================
 *  PDF-GENERIERUNG: Bei Zahlungseingang/Processing erzeugen
 *  ============================================================ */

/**
 * Generiert für alle Ticket-Items die PDFs, falls noch nicht vorhanden.
 */
function wjt_generate_ticket_pdfs_if_needed( $order_id ) {
    $order = wc_get_order($order_id); if (!$order) return;

    foreach ($order->get_items() as $item_id => $item){
        $prod = $item->get_product(); if (!$prod) continue;
        if ($prod->get_meta(WJT_META_IS_TICKET) !== 'yes') continue;

        $qty = max(1, intval($item->get_quantity()));

        // Check: bereits generiert?
        $already = true;
        for ($i=0; $i<$qty; $i++){
            $hasUrl  = (bool) wc_get_order_item_meta($item_id, WJT_META_PDF_URL.$i, true);
            $hasPath = (bool) wc_get_order_item_meta($item_id, WJT_META_PDF_PATH.$i, true);
            if (!$hasUrl && !$hasPath) { $already = false; break; }
        }
        if ($already) continue;

        for ($idx = 0; $idx < $qty; $idx++){
            $ticket_uuid = wp_generate_uuid4();
            $ticket_id = wjt_generate_ticket_id($order_id, $idx);

            // Event-Informationen aus Produkt-Metadaten sammeln
            $product = $item->get_product();
            $product_id = $product ? $product->get_id() : 0;
            
                // Event-Daten aus Custom Fields laden
    $event_location = get_post_meta($product_id, '_wjt_event_location', true);
    $event_date = get_post_meta($product_id, '_wjt_event_date', true);
    $event_time = get_post_meta($product_id, '_wjt_event_time', true);
    $event_address = get_post_meta($product_id, '_wjt_event_address', true);
    $event_description = get_post_meta($product_id, '_wjt_event_description', true);
    $event_organizer = get_post_meta($product_id, '_wjt_event_organizer', true);
    $event_website = get_post_meta($product_id, '_wjt_event_website', true);
    $event_contact = get_post_meta($product_id, '_wjt_event_contact', true);
            
            // Fallback: Aus Produktbeschreibung oder Standard-Werten
            if (empty($event_organizer)) {
                $event_organizer = get_bloginfo('name'); // Website-Name als Veranstalter
            }
            
            // Event-Daten wurden erfolgreich geladen

            $payload = [
                'ticket_uuid' => $ticket_uuid,
                'ticket_number' => $ticket_id,
                'attendee'    => trim($order->get_billing_first_name().' '.$order->get_billing_last_name()),
                'event'       => $item->get_name(),
                'order_id'    => $order_id,
                'order_key'   => $order->get_order_key(),
                'quantity_idx'=> $idx + 1,
                'purchase_ts' => current_time('mysql'),
                'qr_validation_url' => home_url('/wp-admin/admin-ajax.php?action=wjt_validate_ticket&uuid=' . $ticket_uuid),
                'ticket_price' => $item->get_total() / $qty, // Preis pro Ticket
                'currency' => $order->get_currency(),
                
                // Event-Informationen für erweiterte PDF-Generierung
                'event_location' => $event_location ?: null,
                'event_date' => $event_date ?: null,
                'event_time' => $event_time ?: null,
                'event_address' => $event_address ?: null,
                'event_description' => $event_description ?: null,
                'event_organizer' => $event_organizer ?: null,
                'event_website' => $event_website ?: null,
                'event_contact' => $event_contact ?: null,
            ];


            $headers = ['Content-Type'=>'application/json'];
            if (WJT_API_KEY !== '') {
                $headers['X-API-Key'] = WJT_API_KEY;
            }

            $resp = wp_remote_post(WJT_JINJA_ENDPOINT, [
                'headers' => $headers,
                'body'    => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
                'timeout' => 60,
            ]);

            if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200){
                $msg = is_wp_error($resp) ? $resp->get_error_message() : 'HTTP '.wp_remote_retrieve_response_code($resp);
                if (WJT_DEBUG_NOTES) { $order->add_order_note('Ticket #'.($idx+1).' Fehler (Render): '.$msg); }
                continue;
            }

            $data = json_decode(wp_remote_retrieve_body($resp), true);
            $pdf_url  = is_array($data) && !empty($data['pdf_url']) ? esc_url_raw($data['pdf_url']) : '';
            $pdf_b64  = is_array($data) && !empty($data['pdf_base64']) ? $data['pdf_base64'] : '';

            // UUID, Ticket-ID und Status speichern für Validierung
            wc_update_order_item_meta($item_id, WJT_META_UUID.$idx, $ticket_uuid);
            wc_update_order_item_meta($item_id, WJT_META_TICKET_NUMBER.$idx, $ticket_id);
            wc_update_order_item_meta($item_id, WJT_META_STATUS.$idx, 'active');
            wc_update_order_item_meta($item_id, WJT_META_SCAN_COUNT.$idx, '0');
            wc_update_order_item_meta($item_id, WJT_META_FIRST_SCAN.$idx, '');

            // Pfad/URL speichern - NUR UUID (SICHER)
            if (WJT_ATTACH_PDF && $pdf_b64){
                $upload_dir = wp_upload_dir();
                $dir = trailingslashit($upload_dir['basedir']).'tickets';
                if (!file_exists($dir)) wp_mkdir_p($dir);
                
                // Nur UUID-Datei speichern (sicher, nicht erratbar)
                $path = trailingslashit($dir).$ticket_uuid.'.pdf';
                file_put_contents($path, base64_decode($pdf_b64));
                wc_update_order_item_meta($item_id, WJT_META_PDF_PATH.$idx, $path);
                
                // Ticket-Nummer für E-Mail-Anhang-Namen speichern
                wc_update_order_item_meta($item_id, WJT_META_TICKET_NUMBER.$idx, $ticket_id);
                
                if (WJT_DEBUG_NOTES) { $order->add_order_note('Ticket #'.($idx+1).' gespeichert: '.$path); }
            }
            if ($pdf_url){
                wc_update_order_item_meta($item_id, WJT_META_PDF_URL.$idx, $pdf_url);
                if (WJT_DEBUG_NOTES) { $order->add_order_note('Ticket #'.($idx+1).' Link: '.$pdf_url); }
            }
        }
    }
}

// 1) Direkt wenn Payment-Gateway "complete" meldet
add_action('woocommerce_payment_complete', function($order_id){
    wjt_generate_ticket_pdfs_if_needed($order_id);
}, 10, 1);

// 2) Fallback: sobald Status "processing" wird (manche Gateways setzen so)
add_action('woocommerce_order_status_processing', function($order_id){
    wjt_generate_ticket_pdfs_if_needed($order_id);
    
    // Auto-Complete für Ticket-only Bestellungen (DELAYED um Checkout-Flow nicht zu stören)
    wp_schedule_single_event(time() + 30, 'wjt_delayed_auto_complete', array($order_id));
}, 10, 1);

// Delayed Auto-Complete Hook
add_action('wjt_delayed_auto_complete', 'wjt_auto_complete_ticket_only_orders');

/**
 * Auto-Complete Bestellungen die nur Tickets enthalten
 */
function wjt_auto_complete_ticket_only_orders($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;
    
    // Nur bei "processing" Status automatisch vervollständigen
    if ($order->get_status() !== 'processing') return;
    
    $has_physical_products = false;
    $has_tickets = false;
    
    foreach ($order->get_items() as $item_id => $item) {
        $product = $item->get_product();
        if (!$product) continue;
        
        if ($product->get_meta(WJT_META_IS_TICKET) === 'yes') {
            $has_tickets = true;
        } else {
            // Prüfen ob physisches Produkt (nicht virtual und nicht downloadable)
            if (!$product->is_virtual() && !$product->is_downloadable()) {
                $has_physical_products = true;
            }
        }
    }
    
    // Wenn nur Tickets und keine physischen Produkte → Auto-Complete OHNE E-Mail
    if ($has_tickets && !$has_physical_products) {
        // E-Mail-Hooks temporär deaktivieren
        remove_action('woocommerce_order_status_completed', array('WC_Emails', 'send_transactional_email'), 10);
        
        // Status direkt setzen
        $order->set_status('completed', 'Automatisch abgeschlossen: Bestellung enthält nur Tickets/digitale Produkte.');
        $order->save();
        
        // E-Mail-Hooks wieder aktivieren für andere Bestellungen
        add_action('woocommerce_order_status_completed', array('WC_Emails', 'send_transactional_email'), 10, 1);
        
        if (WJT_DEBUG_NOTES) {
            $order->add_order_note('🎫 Auto-Complete: Ticket-only Bestellung automatisch abgeschlossen (ohne E-Mail).');
        }
        
        error_log("WJT AUTO-COMPLETE: Order #$order_id silently completed (tickets only, no email)");
    }
}

/** ============================================================
 *  ADMIN: Testbestellungen löschen (AUSKOMMENTIERT FÜR PROD)
 *  ============================================================ */

/*
// AJAX Handler zum Finden und Löschen von Test-Bestellungen
add_action('wp_ajax_wjt_delete_test_orders', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    
    $product_name = sanitize_text_field($_POST['product_name'] ?? '');
    if (empty($product_name)) {
        wp_send_json_error('Produktname erforderlich');
    }
    
    // 1. Produkt finden
    $products = wc_get_products(array(
        'name' => $product_name,
        'limit' => 10,
        'status' => 'any'
    ));
    
    if (empty($products)) {
        wp_send_json_error("Kein Produkt mit Name '$product_name' gefunden");
    }
    
    $product_ids = array_map(function($p) { return $p->get_id(); }, $products);
    
    // 2. Bestellungen finden die diese Produkte enthalten
    $orders_to_delete = array();
    
    $orders = wc_get_orders(array(
        'limit' => -1,
        'status' => 'any'
    ));
    
    foreach ($orders as $order) {
        foreach ($order->get_items() as $item) {
            if (in_array($item->get_product_id(), $product_ids)) {
                $orders_to_delete[] = $order->get_id();
                break;
            }
        }
    }
    
    // 3. Bestellungen löschen
    $deleted_count = 0;
    foreach ($orders_to_delete as $order_id) {
        $order = wc_get_order($order_id);
        if ($order) {
            $order->delete(true); // true = force delete
            $deleted_count++;
        }
    }
    
    wp_send_json_success(array(
        'message' => "Erfolgreich $deleted_count Bestellungen gelöscht",
        'product_ids' => $product_ids,
        'deleted_orders' => $orders_to_delete
    ));
});
*/

/** ============================================================
 *  MAIL: Links + Attachments in gewünschten E-Mails
 *  ============================================================ */

// TEMP DEBUG: Log ALLE E-Mails
add_filter('woocommerce_email_attachments', function($attachments, $email_id, $order, $email) {
    if ($order instanceof WC_Order) {
        error_log("WJT DEBUG ALL EMAILS: Order #{$order->get_id()}, email_id='$email_id', attachments_count=" . count($attachments));
    } else {
        error_log("WJT DEBUG ALL EMAILS: email_id='$email_id', NO ORDER, attachments_count=" . count($attachments));
    }
    return $attachments;
}, 5, 4); // Priorität 5 = vor unserem Filter (10)
add_action('woocommerce_email_order_meta', function($order, $sent_to_admin, $plain_text, $email){
    if (!$email || !in_array($email->id, WJT_EMAIL_TARGETS, true)) return;

    foreach ($order->get_items() as $item_id => $item){
        $prod = $item->get_product(); if (!$prod) continue;
        if ($prod->get_meta(WJT_META_IS_TICKET) !== 'yes') continue;

        $qty = max(1, intval($item->get_quantity()));
        for ($i=0; $i < $qty; $i++){
            $url = wc_get_order_item_meta($item_id, WJT_META_PDF_URL.$i, true);
            if ($url){
                if ($plain_text){
                    echo "\nTicket #".($i+1).": ".$url."\n";
                } else {
                    echo '<p>Ticket #'.($i+1).': <a href="'.esc_url($url).'">'.esc_html($url).'</a></p>';
                }
            }
        }
    }
}, 10, 4);



// E-Mail-Anhänge für Tickets mit Debug
add_filter('woocommerce_email_attachments', function($attachments, $email_id, $order){
    error_log("WJT EMAIL DEBUG: email_id=$email_id, WJT_ATTACH_PDF=" . (WJT_ATTACH_PDF ? 'true' : 'false'));
    
    if (!WJT_ATTACH_PDF) return $attachments;
    if (!$order instanceof WC_Order) return $attachments;
    if (!in_array($email_id, WJT_EMAIL_TARGETS, true)) {
        error_log("WJT EMAIL DEBUG: email_id '$email_id' not in targets: " . implode(', ', WJT_EMAIL_TARGETS));
        // TEMP DEBUG: Log trotzdem
        error_log("WJT EMAIL DEBUG: Order #{$order->get_id()} - NICHT-ZIEL-EMAIL: '$email_id'");
        return $attachments;
    }



    error_log("WJT EMAIL DEBUG: Processing order #{$order->get_id()}");

    foreach ($order->get_items() as $item_id => $item){
        $prod = $item->get_product(); if (!$prod) continue;
        $is_ticket = $prod->get_meta(WJT_META_IS_TICKET);
        error_log("WJT EMAIL DEBUG: Item $item_id, is_ticket='$is_ticket'");
        
        if ($is_ticket !== 'yes') continue;

        $qty = max(1, intval($item->get_quantity()));
        error_log("WJT EMAIL DEBUG: Processing $qty tickets for item $item_id");
        
        for ($i=0; $i < $qty; $i++){
            // UUID-Datei lesen (sicher gespeichert)
            $path = wc_get_order_item_meta($item_id, WJT_META_PDF_PATH.$i, true);
            if ($path && file_exists($path)){
                // Ticket-Nummer für schönen Dateinamen
                $ticket_number = wc_get_order_item_meta($item_id, WJT_META_TICKET_NUMBER.$i, true);
                if ($ticket_number) {
                    $safe_ticket_id = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $ticket_number);
                    $nice_filename = 'ticket_' . $safe_ticket_id . '.pdf';
                    
                    // Temporäre Kopie in SICHEREM Verzeichnis (geschützt durch .htaccess)
                    $upload_dir = wp_upload_dir();
                    $secure_temp_dir = $upload_dir['basedir'] . '/wjt-secure-temp/';
                    if (!file_exists($secure_temp_dir)) {
                        wp_mkdir_p($secure_temp_dir);
                        // .htaccess für zusätzlichen Schutz
                        file_put_contents($secure_temp_dir . '.htaccess', "Deny from all\n");
                        // index.php für extra Schutz
                        file_put_contents($secure_temp_dir . 'index.php', "<?php exit('Access denied');");
                    }
                    
                    $temp_path = $secure_temp_dir . $nice_filename;
                    copy($path, $temp_path);
                    error_log("WJT EMAIL DEBUG: Adding secure temp attachment: $temp_path");
                    $attachments[] = $temp_path;
                } else {
                    error_log("WJT EMAIL DEBUG: Adding UUID attachment: $path");
                    $attachments[] = $path;
                }
            } else {
                error_log("WJT EMAIL DEBUG: No PDF found for item $item_id index $i");
            }
        }
    }
    
    error_log("WJT EMAIL DEBUG: Final attachments count: " . count($attachments));
    return $attachments;
}, 10, 3);

// Temporäre E-Mail-Dateien aufräumen (DEAKTIVIERT FÜR DEBUGGING)
/*
add_action('woocommerce_email_sent', function($return_path, $email_id, $order) {
    if (!in_array($email_id, WJT_EMAIL_TARGETS, true)) return;
    
    // Sichere temporäre Dateien sofort löschen
    $upload_dir = wp_upload_dir();
    $secure_temp_dir = $upload_dir['basedir'] . '/wjt-secure-temp/';
    if (is_dir($secure_temp_dir)) {
        $files = glob($secure_temp_dir . 'ticket_*.pdf');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
                error_log("WJT EMAIL DEBUG: Cleaned up temp file: $file");
            }
        }
    }
}, 10, 3);
*/

/** ============================================================
 *  QR-CODE VALIDIERUNG: AJAX Endpoint für Ticket-Scanner
 *  ============================================================ */

// AJAX Handler für Ticket-Validierung (sowohl logged-in als auch public)
add_action('wp_ajax_wjt_validate_ticket', 'wjt_handle_ticket_validation');
add_action('wp_ajax_nopriv_wjt_validate_ticket', 'wjt_handle_ticket_validation');

function wjt_handle_ticket_validation() {
    $uuid = isset($_GET['uuid']) ? sanitize_text_field($_GET['uuid']) : '';
    
    if (empty($uuid)) {
        wp_send_json_error(['message' => 'Ticket-UUID fehlt']);
        return;
    }

    // Suche nach dem Ticket in allen Bestellungen
    global $wpdb;
    $meta_key = WJT_META_UUID . '%';
    $query = $wpdb->prepare(
        "SELECT order_item_id, meta_key, meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta 
         WHERE meta_key LIKE %s AND meta_value = %s",
        $meta_key,
        $uuid
    );
    $results = $wpdb->get_results($query);

    if (empty($results)) {
        wp_send_json_error(['message' => 'Ticket nicht gefunden', 'status' => 'invalid']);
        return;
    }

    $result = $results[0];
    $order_item_id = $result->order_item_id;
    
    // Hole die Bestellung
    $order_item = new WC_Order_Item_Product($order_item_id);
    $order = $order_item->get_order();
    
    if (!$order) {
        wp_send_json_error(['message' => 'Bestellung nicht gefunden', 'status' => 'invalid']);
        return;
    }

    // Check ob Bestellung bezahlt/gültig ist
    $valid_statuses = ['processing', 'completed'];
    $order_status = $order->get_status();
    
    if (!in_array($order_status, $valid_statuses)) {
        wp_send_json_error([
            'message' => 'Ticket ungültig - Bestellung nicht bezahlt',
            'status' => 'unpaid',
            'order_status' => $order_status
        ]);
        return;
    }

    // Ticket-Info zusammenstellen
    $product = $order_item->get_product();
    $ticket_info = [
        'status' => 'valid',
        'message' => 'Ticket gültig',
        'event' => $order_item->get_name(),
        'order_id' => $order->get_id(),
        'customer' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
        'purchase_date' => $order->get_date_created()->date('d.m.Y H:i'),
        'uuid' => $uuid
    ];

    wp_send_json_success($ticket_info);
}

// Scanner-Weiterleitung zur Ticket-Engine
function wjt_get_scanner_url($event_name = '') {
    $base_url = 'https://shop.bigfranky.de'; // Über Nginx-Proxy
    if ($event_name) {
        $token = wjt_generate_event_token($event_name);
        return $base_url . '/scanner/' . $token;
    }
    return $base_url . '/scanner/general';
}

function wjt_generate_event_token($event_name) {
    $secret_key = 'ticket_scanner_secret';
    $message = $event_name . ':' . date('Y-m');
    return substr(hash_hmac('sha256', $message, $secret_key), 0, 16);
}

/**
 * Generiert eine Ticket-ID pro Bestellung (1, 2, 3...)
 */
function wjt_generate_ticket_id($order_id, $ticket_index) {
    // Eindeutige ID basierend auf Bestellung + Index
    $order_prefix = str_pad($order_id, 4, '0', STR_PAD_LEFT); // 4-stellige Bestellnummer
    $ticket_suffix = $ticket_index + 1; // 1-basiert statt 0-basiert
    
    return $order_prefix . '-' . $ticket_suffix; // z.B. 0350-1, 0350-2, 0350-3
}

// ===== Admin: Verbesserte Übersicht "Tickets" =====
add_action('admin_menu', function(){
    add_menu_page(
        __('Tickets', 'woo-jinja-tickets'),
        __('Tickets', 'woo-jinja-tickets'),
        'manage_woocommerce',
        'wjt-tickets',
        'wjt_render_tickets_admin_page',
        'dashicons-tickets',
        56
    );
    
    // Event-spezifische Unterseiten
    add_submenu_page(
        'wjt-tickets',
        __('Events', 'woo-jinja-tickets'),
        __('Events', 'woo-jinja-tickets'),
        'manage_woocommerce',
        'wjt-events',
        'wjt_render_events_admin_page'
    );
});

function wjt_render_tickets_admin_page(){
    if (!current_user_can('manage_woocommerce')) { return; }

    // Scanner-Seite Link (allgemein)
    $general_scanner_url = wjt_get_scanner_url();

    // Filter Parameter
    $days = isset($_GET['days']) ? max(1, intval($_GET['days'])) : 30;
    $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';

    // Bestellungen laden - inkl. completed und processing für Tickets
    if ($status_filter === 'all') {
        $status_array = ['wc-completed', 'wc-processing', 'wc-on-hold']; // Ticket-relevante Status
    } else {
        $status_array = [$status_filter];
    }
    $query = new WC_Order_Query([
        'limit'        => 500,
        'status'       => $status_array,
        'date_created' => '>' . (new DateTime("-{$days} days"))->format('Y-m-d H:i:s'),
        'orderby'      => 'date',
        'order'        => 'DESC',
        'return'       => 'objects',
    ]);
    $orders = $query->get_orders();

    echo '<div class="wrap">';
    echo '<h1>🎫 '.esc_html__('Ticket-Verwaltung', 'woo-jinja-tickets').'</h1>';
    
    // Aktionsbuttons
    echo '<div style="margin: 20px 0; display: flex; gap: 10px; flex-wrap: wrap;">';
    echo '<a href="'.esc_url($general_scanner_url).'" class="button button-primary" target="_blank">📱 Allgemeiner Scanner</a>';
    echo '<a href="'.admin_url('edit.php?post_type=product').'" class="button">➕ Ticket-Produkt erstellen</a>';
    echo '<a href="'.admin_url('admin-ajax.php?action=wjt_emergency_export').'" class="button button-secondary">📋 Notfall-Export</a>';
    // echo '<button id="delete-test-orders" class="button button-secondary" style="background: #dc3232; color: white;">🗑️ Testbestellungen löschen</button>'; // AUSKOMMENTIERT FÜR PROD
    echo '</div>';

    // Filter
    echo '<div style="background: #f9f9f9; padding: 15px; margin: 20px 0; border-radius: 5px;">';
    echo '<form method="get" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">';
    echo '<input type="hidden" name="page" value="wjt-tickets">';
    echo '<label>Zeitraum: <select name="days">';
    $day_options = [7 => '7 Tage', 30 => '30 Tage', 90 => '90 Tage', 365 => '1 Jahr'];
    foreach ($day_options as $value => $label) {
        $selected = $days === $value ? 'selected' : '';
        echo '<option value="'.$value.'" '.$selected.'>'.$label.'</option>';
    }
    echo '</select></label>';
    
    echo '<label>Status: <select name="status">';
    echo '<option value="all" '.($status_filter === 'all' ? 'selected' : '').'>Alle</option>';
    foreach (wc_get_order_statuses() as $status => $label) {
        $selected = $status_filter === $status ? 'selected' : '';
        echo '<option value="'.$status.'" '.$selected.'>'.$label.'</option>';
    }
    echo '</select></label>';
    
    echo '<button type="submit" class="button">Filtern</button>';
    echo '</form>';
    echo '</div>';

    // Statistiken
    $total_tickets = 0;
    $generated_tickets = 0;
    foreach ($orders as $order) {
        foreach ($order->get_items() as $item_id => $item) {
            $prod = $item->get_product();
            if (!$prod || $prod->get_meta(WJT_META_IS_TICKET) !== 'yes') continue;
            $qty = max(1, intval($item->get_quantity()));
            $total_tickets += $qty;
            
            for ($i = 0; $i < $qty; $i++) {
                $uuid = wc_get_order_item_meta($item_id, WJT_META_UUID.$i, true);
                if ($uuid) $generated_tickets++;
            }
        }
    }

    echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0;">';
    echo '<div style="background: #fff; border: 1px solid #ddd; padding: 15px; border-radius: 5px; text-align: center;">';
    echo '<h3 style="margin: 0; color: #0073aa;">📊 Gesamt</h3>';
    echo '<div style="font-size: 24px; font-weight: bold;">'.$total_tickets.'</div>';
    echo '<small>Verkaufte Tickets</small>';
    echo '</div>';
    
    echo '<div style="background: #fff; border: 1px solid #ddd; padding: 15px; border-radius: 5px; text-align: center;">';
    echo '<h3 style="margin: 0; color: #46b450;">✅ Generiert</h3>';
    echo '<div style="font-size: 24px; font-weight: bold;">'.$generated_tickets.'</div>';
    echo '<small>PDF erstellt</small>';
    echo '</div>';
    
    $pending = $total_tickets - $generated_tickets;
    echo '<div style="background: #fff; border: 1px solid #ddd; padding: 15px; border-radius: 5px; text-align: center;">';
    echo '<h3 style="margin: 0; color: #ffb900;">⏳ Pending</h3>';
    echo '<div style="font-size: 24px; font-weight: bold;">'.$pending.'</div>';
    echo '<small>Noch zu generieren</small>';
    echo '</div>';
    echo '</div>';

    // Tabelle
    echo '<table class="widefat fixed striped"><thead><tr>';
    echo '<th style="width: 50px;">#</th>';
    echo '<th style="width: 80px;">Bestellung</th>';
    echo '<th style="width: 120px;">Datum</th>';
    echo '<th>Produkt</th>';
    echo '<th style="width: 150px;">Kunde</th>';
    echo '<th style="width: 80px;">Status</th>';
    echo '<th style="width: 120px;">Tickets & Nummern</th>';
    echo '<th style="width: 120px;">Scanner-URL</th>';
    echo '<th style="width: 150px;">Ticket-Verwaltung</th>';
    echo '<th style="width: 100px;">Aktionen</th>';
    echo '</tr></thead><tbody>';

    $row = 0;
    foreach ($orders as $order){
        /** @var WC_Order $order */
        $order_link = admin_url('post.php?post='.$order->get_id().'&action=edit');
        $date = $order->get_date_created() ? $order->get_date_created()->date_i18n('d.m.Y H:i') : '-';
        $customer = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());

        foreach ($order->get_items() as $item_id => $item){
            $prod = $item->get_product(); if (!$prod) continue;
            if ($prod->get_meta(WJT_META_IS_TICKET) !== 'yes') continue;

            $qty = max(1, intval($item->get_quantity()));

            // Ticket Status, Nummern und Aktionen
            $ticket_display = [];
            $ticket_actions = [];
            $has_all_pdfs = true;
            for ($i=0; $i<$qty; $i++){
                $uuid = wc_get_order_item_meta($item_id, WJT_META_UUID.$i, true);
                $path = wc_get_order_item_meta($item_id, WJT_META_PDF_PATH.$i, true);
                $url  = wc_get_order_item_meta($item_id, WJT_META_PDF_URL.$i, true);
                $status = wc_get_order_item_meta($item_id, WJT_META_STATUS.$i, true) ?: 'active';
                $ticket_number = wc_get_order_item_meta($item_id, WJT_META_TICKET_NUMBER.$i, true) ?: '';
                $scan_count = intval(wc_get_order_item_meta($item_id, WJT_META_SCAN_COUNT.$i, true) ?: '0');
                $first_scan = wc_get_order_item_meta($item_id, WJT_META_FIRST_SCAN.$i, true);
                
                if ($uuid && ($path || $url)) {
                    $display_text = '#' . ($ticket_number ?: 'N/A');
                    
                    if ($status === 'disabled') {
                        $display_text .= ' 🚫';
                    } else if ($scan_count > 0) {
                        $display_text .= ' 🟡(' . $scan_count . 'x)';
                } else {
                        $display_text .= ' ✅';
                    }
                    
                    $ticket_display[] = $display_text;
                    
                    // Aktions-Buttons für jedes Ticket
                    $ticket_actions[] = '<div style="margin: 2px 0; font-size: 11px;">'.
                        '<strong>#'.$ticket_number.'</strong><br>'.
                        '<button onclick="toggleTicketStatus('.$item_id.', '.$i.', \''.$status.'\')" class="button button-small">'.
                        ($status === 'disabled' ? '🟢 Aktivieren' : '🔴 Deaktivieren').'</button> '.
                        '<a href="'.($url ?: admin_url('admin-ajax.php?action=wjt_download_ticket&item_id='.$item_id.'&idx='.$i)).'" class="button button-small" target="_blank">💾 Download</a>'.
                        ($scan_count > 0 ? '<br><small>Gescannt: '.$scan_count.'x</small>' : '').
                        '</div>';
                } elseif ($uuid) {
                    $ticket_display[] = '#' . $ticket_number . ' ⏳';
                    $has_all_pdfs = false;
                } else {
                    $ticket_display[] = '❌';
                    $has_all_pdfs = false;
                }
            }

            // Order Status Badge - Für Tickets ist "processing" und "completed" beide OK
            $order_status = $order->get_status();
            if ($order_status === 'completed' || $order_status === 'processing') {
                $status_color = '#46b450'; // Grün für gültige Ticket-Status
                $status_text = 'BEZAHLT';
            } else {
                $status_color = '#dc3232'; // Rot für andere Status
                $status_text = strtoupper($order_status);
            }

            echo '<tr>';
            echo '<td>'.(++$row).'</td>';
            echo '<td><a href="'.esc_url($order_link).'">#'.$order->get_id().'</a></td>';
            echo '<td>'.esc_html($date).'</td>';
            echo '<td><strong>'.esc_html($item->get_name()).'</strong><br><small>× '.$qty.'</small></td>';
            echo '<td>'.esc_html($customer).'</td>';
            echo '<td><span style="background: '.$status_color.'; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px;">'.$status_text.'</span></td>';
            echo '<td>'.implode('<br>', $ticket_display).'</td>';
            
            // Event-spezifische Scanner-URL
            $event_scanner_url = wjt_get_scanner_url($item->get_name());
            echo '<td>';
            echo '<a href="'.esc_url($event_scanner_url).'" target="_blank" class="button button-small" title="Scanner für '.esc_attr($item->get_name()).'">📱 Scanner</a>';
            echo '</td>';
            
            // Ticket-Verwaltung
            echo '<td>';
            echo implode('', $ticket_actions);
            echo '</td>';
            
            echo '<td>';
            if (!$has_all_pdfs && in_array($order->get_status(), ['processing', 'completed'])) {
                echo '<button onclick="regenerateTickets('.$order->get_id().')" class="button button-small">🔄 Regenerieren</button>';
            }
            echo '</td>';
            echo '</tr>';
        }
    }

    if ($row === 0){
        echo '<tr><td colspan="10" style="text-align: center; padding: 40px;">Keine Tickets im gewählten Zeitraum gefunden.</td></tr>';
    }

    echo '</tbody></table>';
    
    // JavaScript für Regenerierung
    ?>
    <script>
    function regenerateTickets(orderId) {
        if (!confirm('Tickets für Bestellung #' + orderId + ' neu generieren?')) return;
        
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=wjt_regenerate_tickets&order_id=' + orderId + '&_ajax_nonce=<?php echo wp_create_nonce('wjt_regenerate'); ?>'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('✅ Tickets wurden regeneriert!');
                location.reload();
            } else {
                alert('❌ Fehler: ' + data.data.message);
            }
        })
        .catch(error => {
            alert('❌ Fehler beim Regenerieren');
        });
    }
    
    function toggleTicketStatus(itemId, idx, currentStatus) {
        const action = currentStatus === 'active' ? 'deaktivieren' : 'aktivieren';
        if (!confirm('Ticket #' + (idx + 1) + ' wirklich ' + action + '?')) return;
        
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=wjt_toggle_ticket_status&item_id=' + itemId + '&idx=' + idx + '&current_status=' + currentStatus
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('✅ Ticket-Status geändert!');
                location.reload();
            } else {
                alert('❌ Fehler: ' + data.data.message);
            }
        })
        .catch(error => {
            alert('❌ Fehler beim Status-Wechsel');
        });
    }
    
    /*
    // Testbestellungen löschen (AUSKOMMENTIERT FÜR PROD)
    document.getElementById('delete-test-orders').addEventListener('click', function() {
        const productName = prompt('Produktname für zu löschende Testbestellungen:', 'Big Franky Big Show 3 Ticket');
        if (!productName) return;
        
        if (!confirm('ACHTUNG: Alle Bestellungen mit Produkt "' + productName + '" werden UNWIDERRUFLICH gelöscht!\n\nFortfahren?')) return;
        
        this.disabled = true;
        this.textContent = '⏳ Lösche...';
        
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=wjt_delete_test_orders&product_name=' + encodeURIComponent(productName)
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('✅ ' + data.data.message);
                location.reload();
            } else {
                alert('❌ Fehler: ' + data.data);
            }
        })
        .catch(e => {
            alert('❌ Netzwerkfehler: ' + e.message);
        })
        .finally(() => {
            this.disabled = false;
            this.textContent = '🗑️ Testbestellungen löschen';
        });
    });
    */
    </script>
    <?php
    
    echo '</div>';
}

// AJAX Handler für Ticket-Regenerierung
add_action('wp_ajax_wjt_regenerate_tickets', function() {
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Keine Berechtigung']);
        return;
    }
    
    if (!wp_verify_nonce($_POST['_ajax_nonce'], 'wjt_regenerate')) {
        wp_send_json_error(['message' => 'Nonce-Verifizierung fehlgeschlagen']);
        return;
    }
    
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    if (!$order_id) {
        wp_send_json_error(['message' => 'Ungültige Bestellungs-ID']);
        return;
    }
    
    // Lösche bestehende Ticket-Metadaten
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(['message' => 'Bestellung nicht gefunden']);
        return;
    }
    
    foreach ($order->get_items() as $item_id => $item) {
        $prod = $item->get_product();
        if (!$prod || $prod->get_meta(WJT_META_IS_TICKET) !== 'yes') continue;
        
        $qty = max(1, intval($item->get_quantity()));
        for ($i = 0; $i < $qty; $i++) {
            wc_delete_order_item_meta($item_id, WJT_META_UUID.$i);
            wc_delete_order_item_meta($item_id, WJT_META_PDF_URL.$i);
            wc_delete_order_item_meta($item_id, WJT_META_PDF_PATH.$i);
        }
    }
    
    // Tickets neu generieren
    wjt_generate_ticket_pdfs_if_needed($order_id);
    
    wp_send_json_success(['message' => 'Tickets erfolgreich regeneriert']);
});

// AJAX Handler für Ticket-Status ändern
add_action('wp_ajax_wjt_toggle_ticket_status', function() {
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Keine Berechtigung']);
        return;
    }
    
    $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
    $idx = isset($_POST['idx']) ? intval($_POST['idx']) : 0;
    $current_status = isset($_POST['current_status']) ? sanitize_text_field($_POST['current_status']) : 'active';
    
    if (!$item_id) {
        wp_send_json_error(['message' => 'Ungültige Item-ID']);
        return;
    }
    
    $new_status = $current_status === 'active' ? 'disabled' : 'active';
    wc_update_order_item_meta($item_id, WJT_META_STATUS.$idx, $new_status);
    
    wp_send_json_success([
        'message' => 'Ticket-Status geändert',
        'new_status' => $new_status
    ]);
});

// AJAX Handler für Ticket-Download
add_action('wp_ajax_wjt_download_ticket', function() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Keine Berechtigung');
        return;
    }
    
    $item_id = isset($_GET['item_id']) ? intval($_GET['item_id']) : 0;
    $idx = isset($_GET['idx']) ? intval($_GET['idx']) : 0;
    
    if (!$item_id) {
        wp_die('Ungültige Parameter');
        return;
    }
    
    $path = wc_get_order_item_meta($item_id, WJT_META_PDF_PATH.$idx, true);
    
    if ($path && file_exists($path)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="ticket-'.$item_id.'-'.$idx.'.pdf"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    } else {
        wp_die('Ticket-Datei nicht gefunden');
    }
});

// Notfall-Export aller Ticket-Informationen
add_action('wp_ajax_wjt_emergency_export', function() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Keine Berechtigung');
        return;
    }
    
    // CSV-Header für Download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="notfall-tickets-'.date('Y-m-d-H-i').'.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV-Kopfzeile
    fputcsv($output, [
        'Bestellung',
        'Datum',
        'Status',
        'Kunde',
        'Event',
        'Ticket-ID',
        'UUID',
        'Ticket-Status',
        'Scanner-URL'
    ]);
    
    // Alle Bestellungen mit Tickets
    $query = new WC_Order_Query([
        'limit' => -1,
        'status' => array_keys(wc_get_order_statuses()),
        'return' => 'objects',
    ]);
    $orders = $query->get_orders();
    
    foreach ($orders as $order) {
        $customer = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $date = $order->get_date_created() ? $order->get_date_created()->date('d.m.Y H:i') : '-';
        
        foreach ($order->get_items() as $item_id => $item) {
            $prod = $item->get_product();
            if (!$prod || $prod->get_meta(WJT_META_IS_TICKET) !== 'yes') continue;
            
            $qty = max(1, intval($item->get_quantity()));
            
            for ($i = 0; $i < $qty; $i++) {
                $uuid = wc_get_order_item_meta($item_id, WJT_META_UUID.$i, true);
                $ticket_id = wc_get_order_item_meta($item_id, WJT_META_TICKET_NUMBER.$i, true);
                $status = wc_get_order_item_meta($item_id, WJT_META_STATUS.$i, true) ?: 'active';
                $scanner_url = wjt_get_scanner_url($item->get_name());
                
                fputcsv($output, [
                    '#' . $order->get_id(),
                    $date,
                    strtoupper($order->get_status()),
                    $customer,
                    $item->get_name(),
                    $ticket_id ?: wjt_generate_ticket_id($order->get_id(), $i),
                    $uuid ?: 'Nicht generiert',
                    $status === 'disabled' ? 'DEAKTIVIERT' : 'AKTIV',
                    $scanner_url
                ]);
            }
        }
    }
    
    fclose($output);
    exit;
});

// AJAX-Handler für Ticket-Validierung und Scan-Tracking
add_action('wp_ajax_wjt_validate_and_track_scan', 'wjt_handle_scan_validation');
add_action('wp_ajax_nopriv_wjt_validate_and_track_scan', 'wjt_handle_scan_validation');

function wjt_handle_scan_validation() {
    // API-Key prüfen (optional)
    $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
    if ($api_key !== 'supersecret123') {
        wp_send_json_error(['message' => 'Unauthorized'], 401);
        return;
    }
    
    $uuid = isset($_POST['uuid']) ? sanitize_text_field($_POST['uuid']) : '';
    if (empty($uuid)) {
        wp_send_json_error(['message' => 'UUID required'], 400);
        return;
    }
    
    // Ticket anhand UUID finden
    global $wpdb;
    
    $query = "
        SELECT oim.order_item_id, oim.meta_key, oim.meta_value,
               oi.order_id, oi.order_item_name
        FROM {$wpdb->prefix}woocommerce_order_itemmeta oim
        JOIN {$wpdb->prefix}woocommerce_order_items oi ON oim.order_item_id = oi.order_item_id
        WHERE oim.meta_key LIKE '_wjt_uuid_%' 
        AND oim.meta_value = %s
    ";
    
    $result = $wpdb->get_row($wpdb->prepare($query, $uuid));
    
    if (!$result) {
        // Debug: Schaue nach exakter UUID
        $exact_query = "
            SELECT COUNT(*) as exact_count
            FROM {$wpdb->prefix}woocommerce_order_itemmeta oim
            WHERE oim.meta_key LIKE '_wjt_uuid_%' 
            AND oim.meta_value = %s
        ";
        $exact_count = $wpdb->get_var($wpdb->prepare($exact_query, $uuid));
        
        // Debug: Zeige die neuesten UUIDs
        $recent_query = "
            SELECT oim.meta_value, oim.meta_key, oi.order_id, p.post_date
            FROM {$wpdb->prefix}woocommerce_order_itemmeta oim
            JOIN {$wpdb->prefix}woocommerce_order_items oi ON oim.order_item_id = oi.order_item_id
            JOIN {$wpdb->prefix}posts p ON oi.order_id = p.ID
            WHERE oim.meta_key LIKE '_wjt_uuid_%' 
            ORDER BY p.post_date DESC
            LIMIT 5
        ";
        $similar_uuids = $wpdb->get_results($recent_query);
        
        $debug_info = [];
        foreach ($similar_uuids as $similar) {
            $debug_info[] = 'Order #' . $similar->order_id . ': ' . $similar->meta_value . ' (' . $similar->post_date . ')';
        }
        
        wp_send_json([
            'status' => 'invalid', 
            'message' => 'Ticket nicht gefunden',
            'debug' => [
                'searched_uuid' => $uuid,
                'exact_matches' => $exact_count,
                'recent_tickets' => $debug_info,
                'note' => 'Versuchen Sie eine UUID aus den recent_tickets'
            ]
        ]);
        return;
    }
    
    // Index aus Meta-Key extrahieren
    $meta_key_parts = explode('_', $result->meta_key);
    $ticket_index = end($meta_key_parts);
    
    $item_id = $result->order_item_id;
    $order_id = $result->order_id;
    $event_name = $result->order_item_name;
    
    // Ticket-Status prüfen
    $status = wc_get_order_item_meta($item_id, WJT_META_STATUS.$ticket_index, true) ?: 'active';
    if ($status === 'disabled') {
        wp_send_json(['status' => 'disabled', 'message' => 'Ticket ist deaktiviert']);
        return;
    }
    
    // Scan-Count prüfen für Warnung
    $current_scan_count = intval(wc_get_order_item_meta($item_id, WJT_META_SCAN_COUNT.$ticket_index, true) ?: '0');
    $new_scan_count = $current_scan_count + 1;
    
    // Erstes Scan-Datum prüfen
    $first_scan = wc_get_order_item_meta($item_id, WJT_META_FIRST_SCAN.$ticket_index, true);
    $is_first_scan = empty($first_scan);
    
    // Warnung bei mehrfachem Scan
    $warning_message = '';
    if ($current_scan_count > 0) {
        $warning_message = '⚠️ ACHTUNG: Ticket bereits ' . $current_scan_count . 'x gescannt!';
        if ($first_scan) {
            $first_scan_formatted = date('d.m.Y \u\m H:i', strtotime($first_scan));
            $warning_message .= ' Erstes Scan: ' . $first_scan_formatted;
        }
    }
    
    // Scan-Count erhöhen
    wc_update_order_item_meta($item_id, WJT_META_SCAN_COUNT.$ticket_index, $new_scan_count);
    
    // Erstes Scan-Datum setzen (falls noch nicht vorhanden)
    if ($is_first_scan) {
        wc_update_order_item_meta($item_id, WJT_META_FIRST_SCAN.$ticket_index, current_time('mysql'));
    }
    
    // Bestellinformationen laden
    $order = wc_get_order($order_id);
    $customer = '';
    $purchase_date = '';
    if ($order) {
        $customer = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $purchase_date = $order->get_date_created() ? $order->get_date_created()->date_i18n('d.m.Y \u\m H:i') : '';
    }
    
    // Ticket-Nummer
    $ticket_number = wc_get_order_item_meta($item_id, WJT_META_TICKET_NUMBER.$ticket_index, true) ?: '';
    
    // Basis-Nachricht
    $main_message = $is_first_scan ? 'Ticket gültig - Einlass gewährt!' : 'Ticket bereits gescannt!';
    
    wp_send_json([
        'status' => 'valid',
        'message' => $main_message,
        'warning' => $warning_message,
        'event' => $event_name,
        'customer' => $customer,
        'purchase_date' => $purchase_date,
        'order_id' => $order_id,
        'ticket_number' => $ticket_number,
        'uuid' => $uuid,
        'scan_count' => $new_scan_count,
        'first_scan' => $first_scan ?: current_time('mysql'),
        'scan_time' => current_time('mysql'),
        'is_duplicate' => !$is_first_scan
    ]);
}

/**
 * Event-spezifische Admin-Seite
 */
function wjt_render_events_admin_page() {
    if (!current_user_can('manage_woocommerce')) { return; }

    // Alle Ticket-Produkte direkt per SQL laden (robuster)
    global $wpdb;
    $ticket_product_ids = $wpdb->get_col("
        SELECT DISTINCT p.ID 
        FROM {$wpdb->posts} p 
        JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
        WHERE p.post_type = 'product' 
        AND p.post_status = 'publish' 
        AND pm.meta_key = '_wjt_is_ticket'
        AND pm.meta_value = 'yes'
    ");
    
    $ticket_products = [];
    foreach ($ticket_product_ids as $product_id) {
        $product = wc_get_product($product_id);
        if ($product) {
            $ticket_products[] = $product;
        }
    }

    // Event-Statistiken sammeln
    $events_stats = [];
    foreach ($ticket_products as $product) {
        $product_name = $product->get_name();
        $product_id = $product->get_id();
        
        // Bestellungen für dieses Produkt
        global $wpdb;
        $orders_query = "
            SELECT DISTINCT oi.order_id 
            FROM {$wpdb->prefix}woocommerce_order_items oi
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
            JOIN {$wpdb->prefix}posts p ON oi.order_id = p.ID
            WHERE oi.order_item_name = %s 
            AND oi.order_item_type = 'line_item'
            AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
        ";
        
        $order_ids = $wpdb->get_col($wpdb->prepare($orders_query, $product_name));
        
        $total_sold = 0;
        $total_generated = 0;
        $total_scanned = 0;
        $total_active = 0;
        $total_disabled = 0;
        
        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) continue;
            
            foreach ($order->get_items() as $item_id => $item) {
                if ($item->get_name() !== $product_name) continue;
                
                $qty = intval($item->get_quantity());
                $total_sold += $qty;
                
                for ($i = 0; $i < $qty; $i++) {
                    $uuid = wc_get_order_item_meta($item_id, WJT_META_UUID.$i, true);
                    $status = wc_get_order_item_meta($item_id, WJT_META_STATUS.$i, true) ?: 'active';
                    $scan_count = intval(wc_get_order_item_meta($item_id, WJT_META_SCAN_COUNT.$i, true) ?: '0');
                    
                    if ($uuid) {
                        $total_generated++;
                        if ($status === 'disabled') {
                            $total_disabled++;
                        } else {
                            $total_active++;
                            if ($scan_count > 0) {
                                $total_scanned++;
                            }
                        }
                    }
                }
            }
        }
        
        $events_stats[] = [
            'name' => $product_name,
            'product_id' => $product_id,
            'total_sold' => $total_sold,
            'total_generated' => $total_generated,
            'total_scanned' => $total_scanned,
            'total_active' => $total_active,
            'total_disabled' => $total_disabled,
            'scanner_url' => wjt_get_scanner_url($product_name)
        ];
    }

    echo '<div class="wrap">';
    echo '<h1>🎪 Event-Übersicht</h1>';
    
    // Debug-Info
    if (empty($ticket_products)) {
        echo '<div class="notice notice-warning"><p><strong>Keine Ticket-Produkte gefunden.</strong></p>';
        
        // Zeige alle Produkte mit Meta-Daten an
        $all_products_debug = $wpdb->get_results("
            SELECT p.ID, p.post_title, pm.meta_key, pm.meta_value 
            FROM {$wpdb->posts} p 
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wjt_is_ticket'
            WHERE p.post_type = 'product' 
            AND p.post_status = 'publish' 
            ORDER BY p.ID
        ");
        
        echo '<h4>Debug: Alle Produkte und ihre Ticket-Meta:</h4><ul>';
        foreach ($all_products_debug as $debug_product) {
            echo '<li>ID '.$debug_product->ID.': '.$debug_product->post_title.' → Meta: '.$debug_product->meta_value.'</li>';
        }
        echo '</ul></div>';
    } else {
        echo '<div class="notice notice-success"><p><strong>'.count($ticket_products).' Ticket-Produkt(e) gefunden.</strong></p></div>';
    }
    
    echo '<table class="widefat fixed striped">';
    echo '<thead><tr>';
    echo '<th>Event/Produkt</th>';
    echo '<th style="width: 100px;">Verkauft</th>';
    echo '<th style="width: 100px;">Generiert</th>';
    echo '<th style="width: 100px;">Aktiv</th>';
    echo '<th style="width: 100px;">Gescannt</th>';
    echo '<th style="width: 100px;">Deaktiviert</th>';
    echo '<th style="width: 150px;">Scanner</th>';
    echo '<th style="width: 150px;">Aktionen</th>';
    echo '</tr></thead>';
    echo '<tbody>';
    
    foreach ($events_stats as $event) {
        echo '<tr>';
        echo '<td><strong>'.esc_html($event['name']).'</strong></td>';
        echo '<td>'.$event['total_sold'].'</td>';
        echo '<td>'.$event['total_generated'].'</td>';
        echo '<td>'.$event['total_active'].'</td>';
        echo '<td>'.($event['total_scanned'] > 0 ? '🟡 '.$event['total_scanned'] : '0').'</td>';
        echo '<td>'.($event['total_disabled'] > 0 ? '🚫 '.$event['total_disabled'] : '0').'</td>';
        echo '<td><a href="'.esc_url($event['scanner_url']).'" target="_blank" class="button button-small">📱 Scanner</a></td>';
        echo '<td>';
        echo '<a href="'.admin_url('admin.php?page=wjt-tickets&event='.urlencode($event['name'])).'" class="button">📋 Details</a> ';
        echo '<a href="'.admin_url('admin-ajax.php?action=wjt_export_event&event='.urlencode($event['name'])).'" class="button">📥 Export</a>';
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
}

// AJAX-Handler für Event-spezifischen Export
add_action('wp_ajax_wjt_export_event', function() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Unauthorized', 'Error', 401);
    }
    
    $event_name = isset($_GET['event']) ? sanitize_text_field($_GET['event']) : '';
    if (empty($event_name)) {
        wp_die('Event name required', 'Error', 400);
    }
    
    // CSV-Export für spezifisches Event
    $filename = 'tickets_export_' . sanitize_file_name($event_name) . '_' . date('Y-m-d_H-i-s') . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // CSV-Header
    fputcsv($output, [
        'Ticket-ID', 'UUID', 'Event', 'Bestellung', 'Kunde', 'Datum', 
        'Status', 'Scan-Anzahl', 'Erstes Scan', 'Download-URL'
    ]);
    
    // Bestellungen für dieses Event laden
    global $wpdb;
    $orders_query = "
        SELECT DISTINCT oi.order_id 
        FROM {$wpdb->prefix}woocommerce_order_items oi
        JOIN {$wpdb->prefix}posts p ON oi.order_id = p.ID
        WHERE oi.order_item_name = %s 
        AND oi.order_item_type = 'line_item'
        AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
        ORDER BY p.post_date DESC
    ";
    
    $order_ids = $wpdb->get_col($wpdb->prepare($orders_query, $event_name));
    
    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) continue;
        
        foreach ($order->get_items() as $item_id => $item) {
            if ($item->get_name() !== $event_name) continue;
            
            $qty = intval($item->get_quantity());
            $customer = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            $date = $order->get_date_created() ? $order->get_date_created()->date_i18n('d.m.Y H:i') : '';
            
            for ($i = 0; $i < $qty; $i++) {
                $uuid = wc_get_order_item_meta($item_id, WJT_META_UUID.$i, true);
                $ticket_number = wc_get_order_item_meta($item_id, WJT_META_TICKET_NUMBER.$i, true);
                $status = wc_get_order_item_meta($item_id, WJT_META_STATUS.$i, true) ?: 'active';
                $scan_count = wc_get_order_item_meta($item_id, WJT_META_SCAN_COUNT.$i, true) ?: '0';
                $first_scan = wc_get_order_item_meta($item_id, WJT_META_FIRST_SCAN.$i, true) ?: '';
                $url = wc_get_order_item_meta($item_id, WJT_META_PDF_URL.$i, true) ?: '';
                
                fputcsv($output, [
                    $ticket_number ?: wjt_generate_ticket_id($order_id, $i),
                    $uuid ?: 'N/A',
                    $event_name,
                    '#'.$order_id,
                    $customer,
                    $date,
                    $status,
                    $scan_count,
                    $first_scan,
                    $url
                ]);
            }
        }
    }
    
    fclose($output);
    exit;
});

// Leichtgewichtiger Scanner-Link für Admins - DEAKTIVIERT FÜR PRODUKTSEITE
// TODO: Scanner-Link wieder aktivieren wenn Event-spezifische Scanner implementiert sind
// oder nur im Admin-Bereich anzeigen
/*
add_action('wp_footer', function() {
    // Nur für eingeloggte Admins
    if (!current_user_can('manage_woocommerce')) return;
    
    $scanner_url = wjt_get_scanner_url();
    ?>
    <style>
        .wjt-scanner-link {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 50px;
            height: 50px;
            background: #2c3e50;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            opacity: 0.7;
            transition: opacity 0.3s ease;
            text-decoration: none;
        }
        .wjt-scanner-link:hover { opacity: 1; }
        .wjt-scanner-link span { color: white; font-size: 20px; }
    </style>
    <a href="<?php echo esc_url($scanner_url); ?>" target="_blank" class="wjt-scanner-link" title="Scanner">
        <span>📱</span>
    </a>
    <?php
});
*/

// ===== EVENT METADATEN FÜR WOOCOMMERCE PRODUKTE =====

// Metabox für Event-Daten zu WooCommerce Produkten hinzufügen
add_action('add_meta_boxes', 'wjt_add_event_metabox');
function wjt_add_event_metabox() {
    add_meta_box(
        'wjt_event_details',
        'Event-Informationen für Tickets',
        'wjt_event_metabox_callback',
        'product',
        'normal',
        'high'
    );
}

// Metabox Content rendern
function wjt_event_metabox_callback($post) {
    // Nonce für Sicherheit
    wp_nonce_field('wjt_save_event_meta', 'wjt_event_meta_nonce');
    
    // Aktuelle Werte laden
    $event_location = get_post_meta($post->ID, '_wjt_event_location', true);
    $event_date = get_post_meta($post->ID, '_wjt_event_date', true);
    $event_time = get_post_meta($post->ID, '_wjt_event_time', true);
    $event_address = get_post_meta($post->ID, '_wjt_event_address', true);
    $event_description = get_post_meta($post->ID, '_wjt_event_description', true);
    $event_organizer = get_post_meta($post->ID, '_wjt_event_organizer', true);
    $event_website = get_post_meta($post->ID, '_wjt_event_website', true);
    $event_contact = get_post_meta($post->ID, '_wjt_event_contact', true);
    $is_ticket = get_post_meta($post->ID, '_wjt_is_ticket', true);
    
    ?>
    <style>
        .wjt-event-metabox { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .wjt-event-field { margin-bottom: 15px; }
        .wjt-event-field label { display: block; font-weight: 600; margin-bottom: 5px; }
        .wjt-event-field input, .wjt-event-field textarea { width: 100%; }
        .wjt-event-field textarea { height: 60px; resize: vertical; }
        .wjt-event-notice { background: #e7f3ff; border: 1px solid #b3d9ff; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        @media (max-width: 768px) { .wjt-event-metabox { grid-template-columns: 1fr; } }
    </style>
    
    <div class="wjt-event-notice">
        <p><strong>Hinweis:</strong> Diese Event-Informationen erscheinen automatisch auf den generierten Tickets, wenn das Produkt als "Ticket-Produkt" markiert ist.</p>
        <label style="margin-top: 10px; display: block;">
            <input type="checkbox" name="wjt_is_ticket" value="yes" <?php checked($is_ticket, 'yes'); ?>>
            <strong>Dies ist ein Ticket-Produkt</strong>
        </label>
    </div>
    
    <div class="wjt-event-metabox">
        <div class="wjt-event-left">
            <div class="wjt-event-field">
                <label for="wjt_event_location">Veranstaltungsort</label>
                <input type="text" id="wjt_event_location" name="wjt_event_location" 
                       value="<?php echo esc_attr($event_location); ?>" 
                       placeholder="z.B. Konzerthalle, Club XY">
            </div>
            
            <div class="wjt-event-field">
                <label for="wjt_event_date">Datum</label>
                <input type="date" id="wjt_event_date" name="wjt_event_date" 
                       value="<?php echo esc_attr($event_date); ?>">
            </div>
            
            <div class="wjt-event-field">
                <label for="wjt_event_time">Uhrzeit</label>
                <input type="time" id="wjt_event_time" name="wjt_event_time" 
                       value="<?php echo esc_attr($event_time); ?>">
            </div>
            
            <div class="wjt-event-field">
                <label for="wjt_event_organizer">Veranstalter</label>
                <input type="text" id="wjt_event_organizer" name="wjt_event_organizer" 
                       value="<?php echo esc_attr($event_organizer); ?>" 
                       placeholder="Standard: <?php echo esc_attr(get_bloginfo('name')); ?>">
            </div>
        </div>
        
        <div class="wjt-event-right">
            <div class="wjt-event-field">
                <label for="wjt_event_address">Adresse</label>
                <textarea id="wjt_event_address" name="wjt_event_address" 
                          placeholder="Vollständige Adresse des Veranstaltungsortes"><?php echo esc_textarea($event_address); ?></textarea>
            </div>
            
            <div class="wjt-event-field">
                <label for="wjt_event_description">Event-Beschreibung</label>
                <textarea id="wjt_event_description" name="wjt_event_description" 
                          placeholder="Kurze Beschreibung für das Ticket"><?php echo esc_textarea($event_description); ?></textarea>
            </div>
            
            <div class="wjt-event-field">
                <label for="wjt_event_website">Website</label>
                <input type="url" id="wjt_event_website" name="wjt_event_website" 
                       value="<?php echo esc_attr($event_website); ?>" 
                       placeholder="https://...">
            </div>
            
            <div class="wjt-event-field">
                <label for="wjt_event_contact">Kontakt</label>
                <input type="text" id="wjt_event_contact" name="wjt_event_contact" 
                       value="<?php echo esc_attr($event_contact); ?>" 
                       placeholder="E-Mail oder Telefon">
            </div>
        </div>
    </div>
    <?php
}

// Event-Metadaten speichern
add_action('save_post', 'wjt_save_event_metadata');
function wjt_save_event_metadata($post_id) {
    // Prüfungen
    if (!isset($_POST['wjt_event_meta_nonce']) || !wp_verify_nonce($_POST['wjt_event_meta_nonce'], 'wjt_save_event_meta')) {
        return;
    }
    
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    
    // Event-Daten speichern
    $event_fields = [
        '_wjt_event_location' => 'wjt_event_location',
        '_wjt_event_date' => 'wjt_event_date',
        '_wjt_event_time' => 'wjt_event_time',
        '_wjt_event_address' => 'wjt_event_address',
        '_wjt_event_description' => 'wjt_event_description',
        '_wjt_event_organizer' => 'wjt_event_organizer',
        '_wjt_event_website' => 'wjt_event_website',
        '_wjt_event_contact' => 'wjt_event_contact',
    ];
    
    foreach ($event_fields as $meta_key => $post_key) {
        if (isset($_POST[$post_key])) {
            update_post_meta($post_id, $meta_key, sanitize_text_field($_POST[$post_key]));
        }
    }
    
    // Ticket-Status speichern
    $is_ticket = isset($_POST['wjt_is_ticket']) ? 'yes' : 'no';
    update_post_meta($post_id, '_wjt_is_ticket', $is_ticket);
}
