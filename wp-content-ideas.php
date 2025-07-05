<?php
/*
* Plugin Name: WP Content Ideas
* Plugin URI: https://kevin-benabdelhak.fr/plugins/wp-content-ideas/
* Description: WP Content Ideas est un plugin WordPress qui utilise l'API OpenAI pour générer des idées d'articles basées sur les titres existants dans votre blog. Accédez à une interface conviviale pour générer facilement des suggestions créatives.
* Version: 1.0
* Author: Kevin BENABDELHAK
* Author URI: https://kevin-benabdelhak.fr
* Contributors: kevinbenabdelhak
*/


if (!defined('ABSPATH')) {
    exit; 
}



if ( !class_exists( 'YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
    require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
}
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$monUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/kevinbenabdelhak/wp-content-ideas/', 
    __FILE__,
    'wp-content-ideas' 
);

$monUpdateChecker->setBranch('main');






add_action('admin_menu', 'wp_content_ideas_menu');

function wp_content_ideas_menu() {
    add_menu_page(
        'WP Content Ideas', 
        'WP Content Ideas', 
        'manage_options',   
        'wp-content-ideas',
        'wp_content_ideas_page'
    );
}

function wp_content_ideas_page() {
    if (isset($_POST['api_key'])) {
        update_option('wp_content_ideas_api_key', sanitize_text_field($_POST['api_key']));
        echo '<div class="updated"><p>Clé API enregistrée avec succès!</p></div>';
    }

    $api_key = get_option('wp_content_ideas_api_key', '');

    $existing_posts = get_posts(array(
        'numberposts' => -1,
        'post_type'   => 'post',
        'post_status' => 'publish',
    ));
    $titles = wp_list_pluck($existing_posts, 'post_title');

    ?>
    <div class="wrap">
        <h1>Générateur d'idées d'articles</h1>
        <form method="post">
            <label for="api_key">Clé API OpenAI:</label>
            <input type="text" name="api_key" id="api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" required />
            <input type="submit" class="button button-secondary" value="Enregistrer la clé API" />
        </form>
        <button id="generate-ideas" class="button button-primary">Générer des idées</button>
        <div id="ideas-result"></div>
    </div>

    <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#generate-ideas').click(function() {
                var titles = <?php echo json_encode($titles); ?>;
                var data = {
                    action: 'generate_openai_ideas',
                    titles: titles,
                    security: '<?php echo wp_create_nonce("wp_rest"); ?>',
                };

                $('#ideas-result').html('Chargement...');
                $.post(ajaxurl, data, function(response) {
                    if (response.success) {
                        $('#ideas-result').html(response.data.html);
                    } else {
                        $('#ideas-result').html('<p>Aucune idée n\'a été générée.</p>');
                    }
                });
            });
        });
    </script>
    <?php
}

add_action('wp_ajax_generate_openai_ideas', 'generate_openai_ideas_ajax');

function generate_openai_ideas_ajax() {
    check_ajax_referer('wp_rest', 'security'); 

    $titles = isset($_POST['titles']) ? $_POST['titles'] : [];
    $titles_string = implode("\n", $titles); 

    $prompt = "Voici des titres d'articles : \"$titles_string\".\n" .
              "Génère une liste d'idées d'articles et retourne seulement le code HTML au format suivant : <ul><li>Titre 1</li><li>Titre 2</li></ul>. " .
              "Ne mets pas de préfixe ou de suffixe, juste le HTML.";

    $data = [
        'model' => 'gpt-4o-mini',
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $prompt
                    ]
                ]
            ]
        ],
        'temperature' => 1,
        'max_tokens' => 500,
    ];

    $api_key = get_option('wp_content_ideas_api_key'); 

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body' => json_encode($data),
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error('Erreur lors de l\'appel à l\'API : ' . $response->get_error_message());
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($body['choices']) && !empty($body['choices'])) {
        $html = trim($body['choices'][0]['message']['content']);
        
        if (strpos($html, '<ul') === false) {
            $html = '<ul><li>' . implode('</li><li>', explode("\n", $html)) . '</li></ul>';
        }

        wp_send_json_success(['html' => $html]);
    }

    wp_send_json_error('La réponse ne contient pas d\'idées valides.');
}