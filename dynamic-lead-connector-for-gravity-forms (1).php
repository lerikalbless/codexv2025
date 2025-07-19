<?php
/*
Plugin Name: Premista Gravity Connector
Description: Connecte dynamiquement les soumissions Gravity Forms à l'API CreditDom (Premista), en respectant les mappages et validations définis.
Version: 1.1
Author: SIOUNANDAM Jean-Pascal
*/

if (!defined('ABSPATH')) exit;

// 🔧 Activation : Valeurs par défaut
register_activation_hook(__FILE__, function() {
    update_option('pgc_api_uri', 'https://libra.credit-libra.fr/Services/Service_CreditDom.php');
    update_option('pgc_src', 'internet');
    update_option('pgc_debug', false);
});

// 🛠️ Menu admin
add_action('admin_menu', function() {
    add_options_page('Premista Connector', 'Premista Connector', 'manage_options', 'premista-connector', 'pgc_options_page');
});

function pgc_options_page() {
    if (!current_user_can('manage_options')) wp_die('Accès refusé');
    ?>
    <div class="wrap">
        <h1>Paramètres du connecteur Premista</h1>
        <form method="post" action="options.php">
            <?php settings_fields('pgc_options'); do_settings_sections('pgc_options'); ?>
            <table class="form-table">
                <tr><th>URL de l'API</th><td><input type="text" name="pgc_api_uri" value="<?php echo esc_attr(get_option('pgc_api_uri')); ?>" size="60"></td></tr>
                <tr><th>Source (src)</th><td><input type="text" name="pgc_src" value="<?php echo esc_attr(get_option('pgc_src')); ?>"></td></tr>
                <tr><th>Mode Debug</th><td><input type="checkbox" name="pgc_debug" value="1" <?php checked(get_option('pgc_debug'), 1); ?>></td></tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

add_action('admin_init', function() {
    register_setting('pgc_options', 'pgc_api_uri');
    register_setting('pgc_options', 'pgc_src');
    register_setting('pgc_options', 'pgc_debug');
});

function pgc_map_default($data) {
    $defaults = [
        'email' => 'aucun@email.fr', 'nom' => '', 'prenom' => '', 'salaire' => '0', 'adresse' => 'aucune adresse',
        'cp' => '00000', 'ville' => '', 'tel_mobile' => '', 'tel_domicile' => '',
        'civilite' => 'M', 'situation_familiale' => '1', 'locataire' => '2'
    ];
    return array_merge($defaults, $data);
}

function pgc_translate_enums(&$data) {
    $civilite_map = ['Mr'=>'M','Mlle'=>'Mme','Mme'=>'Mme','Monsieur'=>'M'];
    $locataire_map = ['Propriétaire'=>'1','Locataire'=>'2','Hébergé(e)'=>'2'];
    if (isset($data['civilite'])) $data['civilite'] = $civilite_map[$data['civilite']] ?? 'M';
    if (isset($data['locataire'])) $data['locataire'] = $locataire_map[$data['locataire']] ?? '2';
    if (isset($data['FICP'])) $data['FICP'] = strtolower($data['FICP']) === 'oui' ? 1 : 0;
}

function pgc_send_to_api($entry, $form_id) {
    $api_url = get_option('pgc_api_uri');
    $src = get_option('pgc_src');
    $debug = get_option('pgc_debug');

    $mappings = [
        6 => [ 'prenom'=>'9.3', 'nom'=>'9.6', 'email'=>'18', 'dnat'=>'10', 'tel_mobile'=>'17', 'adresse'=>'16.1', 'cp'=>'16.5', 'ville'=>'16.3', 'salaire'=>'20', 'locataire'=>'21', 'situation_familiale'=>'19', 'nbre_enfant'=>'12', 'mensuconso1'=>'6', 'tresorerie'=>'8', 'montant_pension_versee'=>'23', 'FICP'=>'22', 'civilite'=>'1'],
        7 => [ 'prenom'=>'9.3', 'nom'=>'9.6', 'email'=>'18', 'dnat'=>'10', 'tel_mobile'=>'17', 'adresse'=>'16.1', 'cp'=>'16.5', 'ville'=>'16.3', 'salaire'=>'20', 'locataire'=>'21', 'situation_familiale'=>'19', 'nbre_enfant'=>'12', 'mensuconso1'=>'6', 'tresorerie'=>'8', 'montant_pension_versee'=>'23', 'FICP'=>'22', 'civilite'=>'1'],
        8 => [ 'prenom'=>'9.3', 'nom'=>'9.6', 'email'=>'18', 'dnat'=>'10', 'tel_mobile'=>'17', 'adresse'=>'16.1', 'cp'=>'16.5', 'ville'=>'16.3', 'salaire'=>'20', 'locataire'=>'21', 'situation_familiale'=>'19', 'nbre_enfant'=>'12', 'mensuconso1'=>'6', 'tresorerie'=>'8', 'montant_pension_versee'=>'23', 'FICP'=>'22', 'civilite'=>'1'],
        9 => [ 'prenom'=>'9.3', 'nom'=>'9.6', 'email'=>'18', 'dnat'=>'10', 'tel_mobile'=>'17', 'adresse'=>'16.1', 'cp'=>'16.5', 'ville'=>'16.3', 'salaire'=>'20', 'locataire'=>'21', 'situation_familiale'=>'19', 'nbre_enfant'=>'12', 'mensuconso1'=>'6', 'tresorerie'=>'8', 'montant_pension_versee'=>'23', 'FICP'=>'22', 'civilite'=>'1'],
        11 => [ 'prenom'=>'9.3', 'nom'=>'9.6', 'email'=>'18', 'dnat'=>'10', 'tel_mobile'=>'17', 'adresse'=>'16.1', 'cp'=>'16.5', 'ville'=>'16.3', 'salaire'=>'20', 'locataire'=>'21', 'situation_familiale'=>'19', 'nbre_enfant'=>'12', 'mensuconso1'=>'6', 'tresorerie'=>'8', 'montant_pension_versee'=>'23', 'FICP'=>'22', 'civilite'=>'1']
    ];

    if (!isset($mappings[$form_id])) return;
    $map = $mappings[$form_id];

    $email = rgar($entry, $map['email']);
    $check = wp_remote_get($api_url . '?check=1&email=' . urlencode($email));
    if (strpos(wp_remote_retrieve_body($check), '<root>0</root>') !== false) return;

    $payload = ['affi'=>1, 'src'=>$src];
    foreach ($map as $api_field => $gf_id) {
        $val = rgar($entry, $gf_id);
        if ($api_field === 'dnat' && $val) $val = date('d/m/Y', strtotime($val));
        $payload[$api_field] = $val;
    }

    $payload = pgc_map_default($payload);
    pgc_translate_enums($payload);

    $response = wp_remote_post($api_url . '?affi=1', [
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        'body' => $payload
    ]);

    $status = is_wp_error($response) ? '[Erreur] ' . $response->get_error_message() : wp_remote_retrieve_body($response);
    gform_update_meta($entry['id'], 'pgc_status', $status);
}

foreach ([6,7,8,9,11] as $fid) {
    add_action("gform_after_submission_{$fid}", function($entry, $form) use ($fid) {
        pgc_send_to_api($entry, $fid);
    }, 10, 2);
}