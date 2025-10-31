<?php
/**
 * Plugin Name: DevSource Audio Automatique
 * Description: Génère automatiquement un fichier audio MP3 avec OpenAI TTS pour chaque article, l’enregistre dans la bibliothèque de médias, et l’ajoute à la fin du contenu. L'affichage est garanti si le fichier existe physiquement.
 * Author: Maxime Guinard
 * Version: 2.9 (Correction Affichage & Messages)
 */

// S'assure que les fonctions nécessaires sont chargées
if ( ! function_exists( 'wp_handle_upload' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/file.php' );
}
if ( ! function_exists( 'wp_insert_attachment' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/post.php' );
}
if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/image.php' ); 
}


// =================================================================
// 1. GESTION DES RÉGLAGES DU PLUGIN & META BOX
// =================================================================

// --- Ajout de la Meta Box dans l'éditeur d'article ---
add_action('add_meta_boxes', 'devsource_audio_add_metabox');
function devsource_audio_add_metabox() {
    add_meta_box(
        'devsource_audio_metabox_id',
        'Options Audio Automatique',
        'devsource_audio_metabox_callback',
        'post', 
        'side', 
        'default'
    );
}

// Contenu de la Meta Box
function devsource_audio_metabox_callback($post) {
    wp_nonce_field('devsource_audio_save_metabox_data', 'devsource_audio_metabox_nonce');

    $is_disabled = get_post_meta($post->ID, '_devsource_audio_disabled', true);
    $checked = checked($is_disabled, '1', false);
    
    $audio_url = get_post_meta($post->ID, '_devsource_audio_url', true);
    ?>
    <p>
        <label for="devsource_audio_disabled">
            <input type="checkbox" name="devsource_audio_disabled" id="devsource_audio_disabled" value="1" <?php echo $checked; ?> />
            **Désactiver** la génération audio pour cet article
        </label>
        <br>
        <small>Cochez pour ignorer la génération et l'affichage de l'audio.</small>
    </p>
    <?php if ($audio_url) : ?>
        <p style="margin-top: 15px; border-top: 1px solid #eee; padding-top: 10px;">
            <strong style="color: green;">Audio Généré !</strong><br>
            <small>Fichier : <?php echo esc_html(basename($audio_url)); ?></small>
        </p>
    <?php endif; ?>
    <?php
}

// Sauvegarde les données de la Meta Box
add_action('save_post', 'devsource_audio_save_metabox_data');
function devsource_audio_save_metabox_data($post_id) {
    if (!isset($_POST['devsource_audio_metabox_nonce']) || !wp_verify_nonce($_POST['devsource_audio_metabox_nonce'], 'devsource_audio_save_metabox_data')) {
        return $post_id;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return $post_id;
    }

    $is_disabled = isset($_POST['devsource_audio_disabled']) ? '1' : '0';
    update_post_meta($post_id, '_devsource_audio_disabled', $is_disabled);
}


// --- Réglages généraux du plugin ---
add_action('admin_menu', 'devsource_audio_settings_page');
function devsource_audio_settings_page() {
    add_options_page(
        'Réglages DevSource Audio',
        'DevSource Audio Auto',
        'manage_options',
        'devsource-audio-settings',
        'devsource_audio_settings_content'
    );
}
function devsource_audio_settings_content() {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('devsource_audio_options');
            do_settings_sections('devsource-audio-settings');
            submit_button('Sauvegarder les réglages');
            ?>
        </form>
        <p>⚠️ **Important** : Le modèle TTS OpenAI valide est `tts-1` (standard) ou `tts-1-hd` (haute qualité).</p>
    </div>
    <?php
}
add_action('admin_init', 'devsource_audio_settings_init');
function devsource_audio_settings_init() {
    register_setting('devsource_audio_options', 'devsource_audio_settings');
    add_settings_section('devsource_audio_section', 'Configuration OpenAI Text-to-Speech (TTS)', 'devsource_audio_section_callback', 'devsource-audio-settings');

    $fields = [
        'api_key' => ['label' => 'Clé API OpenAI', 'callback' => 'devsource_text_input_callback', 'args' => ['name' => 'api_key', 'type' => 'password', 'placeholder' => 'sk-proj-xxxxxxxxxxxxxxxxxxxx']],
        'tts_model' => ['label' => 'Modèle TTS', 'callback' => 'devsource_select_input_callback', 'args' => [
            'name' => 'tts_model', 'options' => ['tts-1' => 'tts-1 (Standard)','tts-1-hd' => 'tts-1-hd (Haute Qualité)'], 'default' => 'tts-1'
        ]],
        'voice' => ['label' => 'Voix', 'callback' => 'devsource_select_input_callback', 'args' => [
            'name' => 'voice', 'options' => ['alloy' => 'Alloy (Féminine, Amicale)', 'echo' => 'Echo (Masculine, Chaleureuse)', 'fable' => 'Fable (Masculine, Narrative)', 'onyx' => 'Onyx (Masculine, Professionnelle)', 'nova' => 'Nova (Féminine, Claire)', 'shimmer' => 'Shimmer (Féminine, Émotionnelle)'], 'default' => 'alloy'
        ]],
        'instructions' => ['label' => 'Instructions Vocales', 'callback' => 'devsource_textarea_callback', 'args' => ['name' => 'instructions', 'rows' => 4]],
    ];
    foreach ($fields as $id => $field) {
        add_settings_field('devsource_' . $id, $field['label'], $field['callback'], 'devsource-audio-settings', 'devsource_audio_section', $field['args']);
    }
}
function devsource_audio_section_callback() {
    echo '<p>Entrez votre clé API OpenAI et configurez les paramètres de la voix pour la synthèse vocale.</p>';
}
function devsource_text_input_callback($args) {
    $options = get_option('devsource_audio_settings'); $name = $args['name']; $type = isset($args['type']) ? $args['type'] : 'text'; $placeholder = isset($args['placeholder']) ? $args['placeholder'] : ''; $value = isset($options[$name]) ? esc_attr($options[$name]) : '';
    if ($type === 'password' && !empty($value)) { $value = '*************************'; }
    echo '<input type="' . esc_attr($type) . '" name="devsource_audio_settings[' . esc_attr($name) . ']" value="' . $value . '" placeholder="' . esc_attr($placeholder) . '" class="regular-text" />';
    if ($type === 'password' && !empty($options[$name])) {
        echo '<input type="hidden" name="devsource_audio_settings[' . esc_attr($name) . ']" value="' . esc_attr($options[$name]) . '" />';
        echo '<p class="description">La clé est enregistrée. Laissez vide pour la conserver, ou entrez une nouvelle clé pour la mettre à jour.</p>';
    }
}
function devsource_textarea_callback($args) {
    $options = get_option('devsource_audio_settings'); $name = $args['name']; $rows = isset($args['rows']) ? (int)$args['rows'] : 4; $value = isset($options[$name]) ? esc_textarea($options[$name]) : '';
    echo '<textarea name="devsource_audio_settings[' . esc_attr($name) . ']" rows="' . esc_attr($rows) . '" cols="50" class="large-text">' . $value . '</textarea>';
}
function devsource_select_input_callback($args) {
    $options = get_option('devsource_audio_settings'); $name = $args['name']; $select_options = $args['options']; $default = $args['default']; $current_value = isset($options[$name]) ? esc_attr($options[$name]) : $default;
    echo '<select name="devsource_audio_settings[' . esc_attr($name) . ']">';
    foreach ($select_options as $key => $label) {
        echo '<option value="' . esc_attr($key) . '" ' . selected($current_value, $key, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
}


// =================================================================
// 2. LOGIQUE DE GÉNÉRATION AUDIO (LIMIT DE CARACTÈRES)
// =================================================================

add_action('save_post', 'devsource_generate_audio_on_publish', 10, 3);

function devsource_generate_audio_on_publish($post_ID, $post, $update) {
    @set_time_limit(300); 
    @ini_set('memory_limit', '256M'); 

    if ($post->post_type != 'post' || $post->post_status != 'publish') return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    $is_disabled = get_post_meta($post_ID, '_devsource_audio_disabled', true);
    if ($is_disabled === '1') {
        return;
    }

    $upload_dir = wp_upload_dir();
    $audio_dir = $upload_dir['basedir'] . '/audios';
    
    $post_slug = sanitize_file_name($post->post_name);
    if (empty($post_slug)) {
        $post_slug = $post_ID; 
    }
    $filename = $post_slug . '.mp3';
    $audio_file_path = $audio_dir . '/' . $filename; 
    $audio_url = $upload_dir['baseurl'] . '/audios/' . $filename; 

    $audio_url_exists_meta = get_post_meta($post_ID, '_devsource_audio_url', true);
    $file_exists_on_server = file_exists($audio_file_path);

    // Arrête si TOUT est présent (pas de régénération)
    if ($audio_url_exists_meta && $file_exists_on_server) {
        return;
    }

    $settings = get_option('devsource_audio_settings');
    if (empty($settings['api_key'])) {
        error_log('DEVSOURCE AUDIO : Clé API OpenAI non configurée.');
        return;
    }

    $api_key   = $settings['api_key'];
    $tts_model = !empty($settings['tts_model']) ? $settings['tts_model'] : 'tts-1'; 
    $voice     = !empty($settings['voice']) ? $settings['voice'] : 'alloy';

    $max_chars = 2500; 
    $full_content = strip_tags($post->post_title . '. ' . $post->post_content);
    $content_to_send = mb_substr($full_content, 0, $max_chars); 
    
    if (empty($content_to_send)) { // Utilise $content_to_send ici
        return;
    }

    $body = json_encode([
        "model" => $tts_model, 
        "voice" => $voice,
        "input" => $content_to_send, // Envoie le contenu limité
    ]);

    $response = wp_remote_post('https://api.openai.com/v1/audio/speech', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => $body,
        'timeout' => 180, 
    ]);

    if (is_wp_error($response)) {
        error_log('DEVSOURCE AUDIO ERREUR (wp_remote_post) : ' . $response->get_error_message());
        return;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        $response_body = wp_remote_retrieve_body($response);
        error_log('DEVSOURCE AUDIO ERREUR (OpenAI API - code ' . $response_code . ') : ' . $response_body);
        return;
    }

    $audio_data = wp_remote_retrieve_body($response);

    if (!file_exists($audio_dir)) {
        if (!mkdir($audio_dir, 0755, true)) {
            error_log('DEVSOURCE AUDIO ERREUR : Impossible de créer le répertoire d\'audios : ' . $audio_dir);
            return;
        }
    }

    if (file_put_contents($audio_file_path, $audio_data) === false) {
        error_log('DEVSOURCE AUDIO ERREUR : Impossible d\'écrire le fichier audio : ' . $audio_file_path);
        return;
    }
    
    // Si le fichier audio est généré et enregistré dans le FTP, nous allons tenter de mettre à jour les métadonnées
    // et l'attachement, même si la sauvegarde de l'article devait planter après.

    $file_type = wp_check_filetype($filename, null);
    
    $existing_attachment_id = get_post_meta($post_ID, '_devsource_audio_attachment_id', true);
    if ($existing_attachment_id) {
        wp_delete_attachment($existing_attachment_id, true);
        delete_post_meta($post_ID, '_devsource_audio_attachment_id');
    }

    $attachment = array(
        'post_mime_type' => $file_type['type'],
        'post_title'     => $post->post_title . ' (Audio TTS)',
        'post_content'   => 'Généré par DevSource Audio Automatique pour l\'article ID ' . $post_ID,
        'post_status'    => 'inherit'
    );
    
    $attach_id = wp_insert_attachment($attachment, $audio_file_path, $post_ID);

    if (is_wp_error($attach_id)) {
        error_log('DEVSOURCE AUDIO ERREUR : Échec de wp_insert_attachment : ' . $attach_id->get_error_message());
    } else {
         $attach_data = wp_generate_attachment_metadata( $attach_id, $audio_file_path );
         wp_update_attachment_metadata( $attach_id, $attach_data );
         update_post_meta($post_ID, '_devsource_audio_attachment_id', $attach_id);
    }
    
    // Met à jour les métadonnées même si l'insertion de l'attachement a échoué (pour garantir l'affichage)
    update_post_meta($post_ID, '_devsource_audio_url', $audio_url);
    update_post_meta($post_ID, '_devsource_audio_voice', $voice);
}

// =================================================================
// 3. AFFICHAGE DU LECTEUR AUDIO (CORRECTIONS D'AFFICHAGE ET RE-VÉRIFICATION)
// =================================================================

add_filter('the_content', 'devsource_append_audio_player');
function devsource_append_audio_player($content) {
    global $post;
    
    if (get_post_type() !== 'post') return $content;
    
    $is_disabled = get_post_meta($post->ID, '_devsource_audio_disabled', true);
    if ($is_disabled === '1') {
        return $content; 
    }

    // Récupère les réglages par défaut pour la voix si non stockée
    $settings = get_option('devsource_audio_settings');
    $default_voice = !empty($settings['voice']) ? $settings['voice'] : 'alloy';

    // 1. Tente de récupérer l'URL et la voix depuis les métadonnées
    $audio_url = get_post_meta($post->ID, '_devsource_audio_url', true);
    $voice = get_post_meta($post->ID, '_devsource_audio_voice', true);
    if (empty($voice)) $voice = $default_voice; // Utilise la voix par défaut si non enregistrée

    // 2. Si l'URL n'est pas dans la BDD (cause : timeout), calcule l'URL à partir du slug et vérifie le fichier physique
    if (empty($audio_url)) {
        $upload_dir = wp_upload_dir();
        $audio_dir = $upload_dir['basedir'] . '/audios';
        
        $post_slug = sanitize_file_name($post->post_name);
        if (empty($post_slug)) {
            $post_slug = $post->ID; 
        }
        $filename = $post_slug . '.mp3';
        $audio_file_path = $audio_dir . '/' . $filename;

        // VÉRIFICATION CRUCIALE : Le fichier existe-t-il sur le disque ?
        if (file_exists($audio_file_path)) {
            $audio_url = $upload_dir['baseurl'] . '/audios/' . $filename;
            // Si la méta-donnée de l'URL était manquante, on la met à jour ici pour éviter de refaire cette vérification
            update_post_meta($post->ID, '_devsource_audio_url', $audio_url);
            // On met à jour la voix aussi, si elle était manquante
            update_post_meta($post->ID, '_devsource_audio_voice', $voice);
        }
    }

    // 3. Si une URL valide a été trouvée (BDD ou physique)
    if (!empty($audio_url)) {
        
        $player = '<div class="devsource-audio-player" style="margin-top:20px; padding: 15px; border: 1px solid #ddd; border-left: 5px solid #0073aa; background: #f9f9f9;">';
        $player .= '<h3>Écouter l\'article</h3>';
        
        // --- NOTE SUR LA LIMITE SUPPRIMÉE ---

        $player .= '<audio controls preload="metadata" style="width:100%;">'; 
        $player .= '<source src="' . esc_url($audio_url) . '" type="audio/mpeg">';
        $player .= 'Votre navigateur ne supporte pas la lecture audio.';
        $player .= '</audio>';
        // --- TEXTE DE LA VOIX MODIFIÉ ---
        $player .= '<p style="font-size: 0.8em; margin-top: 5px; color: #666;">Généré par OpenAI TTS (Voix : ' . esc_html(ucfirst($voice)) . ' by Devsource)</p>';
        $player .= '</div>';
        
        $content .= $player;
    }

    return $content;
}