<?php
/*
Plugin Name: RAG AI Chatbot
Description: WordPress AI Chatbot with FastAPI backend2
Version: 1.0
*/

defined('ABSPATH') || exit;

define('RAG_CHATBOT_PLUGIN_FILE', __FILE__);
define('RAG_CHATBOT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RAG_CHATBOT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RAG_CHATBOT_ACCOUNT_PAGE_SLUG', 'account-settings');
define('RAG_CHATBOT_ACCOUNT_PAGE_OPTION', 'rag_user_settings_page_id');

add_shortcode('rag_chatbot', 'rag_chatbot_ui');
add_shortcode('rag_user_settings', 'rag_user_settings_shortcode');

/**
 * Create or fix the Account Settings page (published, with shortcode).
 *
 * @return int Page ID, or 0 on failure.
 */
function rag_chatbot_create_account_settings_page()
{
    $shortcode = '[rag_user_settings]';

    $by_slug = get_posts([
        'post_type'      => 'page',
        'name'           => RAG_CHATBOT_ACCOUNT_PAGE_SLUG,
        'post_status'    => ['publish', 'draft', 'private', 'pending'],
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ]);

    if (!empty($by_slug)) {
        $page_id = (int) $by_slug[0];
        $post    = get_post($page_id);
        if ($post && strpos((string) $post->post_content, 'rag_user_settings') === false) {
            wp_update_post([
                'ID'           => $page_id,
                'post_content' => trim((string) $post->post_content) . "\n\n" . $shortcode,
            ]);
        }
        if ($post && $post->post_status !== 'publish') {
            wp_update_post([
                'ID'          => $page_id,
                'post_status' => 'publish',
            ]);
        }
        update_option(RAG_CHATBOT_ACCOUNT_PAGE_OPTION, $page_id);
        return $page_id;
    }

    $page_id = wp_insert_post([
        'post_title'   => __('Account settings', 'rag-ai-chatbot'),
        'post_name'    => RAG_CHATBOT_ACCOUNT_PAGE_SLUG,
        'post_content' => $shortcode,
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ], true);

    if (is_wp_error($page_id) || !$page_id) {
        return 0;
    }

    update_option(RAG_CHATBOT_ACCOUNT_PAGE_OPTION, (int) $page_id);
    return (int) $page_id;
}

/**
 * Plugin activation: add the Account Settings page and refresh permalinks.
 */
function rag_chatbot_activate()
{
    rag_chatbot_create_account_settings_page();
    flush_rewrite_rules(true);
}

register_activation_hook(RAG_CHATBOT_PLUGIN_FILE, 'rag_chatbot_activate');

/**
 * If the page was never created (e.g. plugin copied without re-activation), create it when an admin loads the dashboard.
 */
function rag_chatbot_admin_maybe_ensure_account_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $stored = (int) get_option(RAG_CHATBOT_ACCOUNT_PAGE_OPTION);
    if ($stored && get_post_status($stored) === 'publish') {
        return;
    }
    rag_chatbot_create_account_settings_page();
}

add_action('admin_init', 'rag_chatbot_admin_maybe_ensure_account_page');

/**
 * Logged-in visitors: discreet footer link to the Account settings page.
 */
function rag_chatbot_footer_account_link()
{
    if (!is_user_logged_in() || is_admin()) {
        return;
    }
    if (!apply_filters('rag_user_settings_show_footer_link', true)) {
        return;
    }
    $page_id = (int) get_option(RAG_CHATBOT_ACCOUNT_PAGE_OPTION);
    if (!$page_id || get_post_status($page_id) !== 'publish') {
        return;
    }
    $url = get_permalink($page_id);
    if (!$url) {
        return;
    }
    if (is_page($page_id)) {
        return;
    }
    echo '<p class="rag-account-settings-footer-link" style="text-align:center;margin:2rem 0 1.5rem;font-size:14px;opacity:0.9;">'
        . '<a href="' . esc_url($url) . '">' . esc_html__('Account settings', 'rag-ai-chatbot') . '</a></p>';
}

add_action('wp_footer', 'rag_chatbot_footer_account_link', 20);

/**
 * Enqueue user settings assets only when the shortcode is on the page.
 */
function rag_chatbot_maybe_enqueue_user_settings()
{
    if (!is_singular()) {
        return;
    }
    global $post;
    $settings_page_id = (int) get_option(RAG_CHATBOT_ACCOUNT_PAGE_OPTION);
    $is_settings_page = $post && $settings_page_id && (int) $post->ID === $settings_page_id;
    if (!$post || (!$is_settings_page && !has_shortcode((string) $post->post_content, 'rag_user_settings'))) {
        return;
    }

    wp_enqueue_style(
        'rag-user-settings',
        RAG_CHATBOT_PLUGIN_URL . 'assets/user-settings.css',
        [],
        '1.0'
    );

    wp_enqueue_script(
        'rag-user-settings',
        RAG_CHATBOT_PLUGIN_URL . 'assets/user-settings.js',
        [],
        '1.0',
        true
    );

    wp_localize_script('rag-user-settings', 'RagUserSettings', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('rag_user_settings'),
    ]);
}

add_action('wp_enqueue_scripts', 'rag_chatbot_maybe_enqueue_user_settings');

/**
 * Front-end account settings (profile, email, password).
 *
 * A published page with slug "account-settings" is created on plugin activation (or from
 * Dashboard → any admin visit if missing). You can also place [rag_user_settings] on any page.
 * Logged-out visitors see a login prompt.
 */
function rag_user_settings_shortcode()
{
    if (!is_user_logged_in()) {
        $login = wp_login_url(get_permalink());
        return '<p class="rag-user-settings-login-notice">Please <a href="' . esc_url($login) . '">log in</a> to manage your account settings.</p>';
    }

    $user = wp_get_current_user();

    ob_start();
    ?>

    <div id="rag-user-settings" class="rag-user-settings">

        <h2><?php esc_html_e('Account settings', 'rag-ai-chatbot'); ?></h2>

        <section aria-labelledby="rag-profile-heading">
            <h3 id="rag-profile-heading"><?php esc_html_e('Profile', 'rag-ai-chatbot'); ?></h3>
            <form id="rag-profile-form" novalidate>
                <div class="field">
                    <label for="rag-display-name"><?php esc_html_e('Display name', 'rag-ai-chatbot'); ?></label>
                    <input type="text" id="rag-display-name" name="display_name" required
                           value="<?php echo esc_attr($user->display_name); ?>">
                </div>
                <div class="field">
                    <label for="rag-first-name"><?php esc_html_e('First name', 'rag-ai-chatbot'); ?></label>
                    <input type="text" id="rag-first-name" name="first_name"
                           value="<?php echo esc_attr($user->first_name); ?>">
                </div>
                <div class="field">
                    <label for="rag-last-name"><?php esc_html_e('Last name', 'rag-ai-chatbot'); ?></label>
                    <input type="text" id="rag-last-name" name="last_name"
                           value="<?php echo esc_attr($user->last_name); ?>">
                </div>
                <div class="field">
                    <label for="rag-nickname"><?php esc_html_e('Nickname', 'rag-ai-chatbot'); ?></label>
                    <input type="text" id="rag-nickname" name="nickname"
                           value="<?php echo esc_attr(get_user_meta($user->ID, 'nickname', true)); ?>">
                </div>
                <div class="field">
                    <label for="rag-user-url"><?php esc_html_e('Website', 'rag-ai-chatbot'); ?></label>
                    <input type="text" id="rag-user-url" name="user_url"
                           value="<?php echo esc_attr($user->user_url); ?>"
                           placeholder="https://">
                </div>
                <div class="field">
                    <label for="rag-description"><?php esc_html_e('Biographical info', 'rag-ai-chatbot'); ?></label>
                    <textarea id="rag-description" name="description"><?php echo esc_textarea($user->description); ?></textarea>
                </div>
                <div class="actions">
                    <button type="submit" class="btn-primary"><?php esc_html_e('Save profile', 'rag-ai-chatbot'); ?></button>
                </div>
                <div class="notice" role="status" aria-live="polite"></div>
            </form>
        </section>

        <section aria-labelledby="rag-email-heading">
            <h3 id="rag-email-heading"><?php esc_html_e('Email address', 'rag-ai-chatbot'); ?></h3>
            <p class="hint"><?php esc_html_e('Current email:', 'rag-ai-chatbot'); ?>
                <span class="readonly-email"><?php echo esc_html($user->user_email); ?></span></p>
            <form id="rag-email-form" novalidate>
                <div class="field">
                    <label for="rag-user-email"><?php esc_html_e('New email', 'rag-ai-chatbot'); ?></label>
                    <input type="email" id="rag-user-email" name="user_email" required autocomplete="email"
                           value="<?php echo esc_attr($user->user_email); ?>">
                </div>
                <div class="field">
                    <label for="rag-email-password"><?php esc_html_e('Confirm with password', 'rag-ai-chatbot'); ?></label>
                    <input type="password" id="rag-email-password" name="email_confirm_password" required autocomplete="current-password">
                    <p class="hint"><?php esc_html_e('Enter your current password to change your email.', 'rag-ai-chatbot'); ?></p>
                </div>
                <div class="actions">
                    <button type="submit" class="btn-primary"><?php esc_html_e('Update email', 'rag-ai-chatbot'); ?></button>
                </div>
                <div class="notice" role="status" aria-live="polite"></div>
            </form>
        </section>

        <section aria-labelledby="rag-password-heading">
            <h3 id="rag-password-heading"><?php esc_html_e('Password', 'rag-ai-chatbot'); ?></h3>
            <form id="rag-password-form" novalidate>
                <div class="field">
                    <label for="rag-current-password"><?php esc_html_e('Current password', 'rag-ai-chatbot'); ?></label>
                    <input type="password" id="rag-current-password" name="current_password" required autocomplete="current-password">
                </div>
                <div class="field">
                    <label for="rag-new-password"><?php esc_html_e('New password', 'rag-ai-chatbot'); ?></label>
                    <input type="password" id="rag-new-password" name="new_password" required autocomplete="new-password" minlength="8">
                    <p class="hint"><?php esc_html_e('At least 8 characters.', 'rag-ai-chatbot'); ?></p>
                </div>
                <div class="field">
                    <label for="rag-confirm-password"><?php esc_html_e('Confirm new password', 'rag-ai-chatbot'); ?></label>
                    <input type="password" id="rag-confirm-password" name="confirm_password" required autocomplete="new-password" minlength="8">
                </div>
                <div class="actions">
                    <button type="submit" class="btn-primary"><?php esc_html_e('Update password', 'rag-ai-chatbot'); ?></button>
                </div>
                <div class="notice" role="status" aria-live="polite"></div>
            </form>
        </section>

    </div>

    <?php
    return ob_get_clean();
}

/**
 * AJAX: save profile fields for the current user.
 */
function rag_ajax_save_user_profile()
{
    check_ajax_referer('rag_user_settings', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => __('You must be logged in.', 'rag-ai-chatbot')], 401);
    }

    $user_id = get_current_user_id();

    $display_name = isset($_POST['display_name']) ? sanitize_text_field(wp_unslash($_POST['display_name'])) : '';
    if ($display_name === '') {
        wp_send_json_error(['message' => __('Display name is required.', 'rag-ai-chatbot')]);
    }

    $first_name = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
    $last_name  = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '';
    $nickname   = isset($_POST['nickname']) ? sanitize_text_field(wp_unslash($_POST['nickname'])) : '';
    $user_url   = isset($_POST['user_url']) ? esc_url_raw(wp_unslash($_POST['user_url'])) : '';
    $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';

    $result = wp_update_user([
        'ID'           => $user_id,
        'display_name' => $display_name,
        'first_name'   => $first_name,
        'last_name'    => $last_name,
        'user_url'     => $user_url,
        'description'  => $description,
    ]);

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }

    if ($nickname !== '') {
        update_user_meta($user_id, 'nickname', $nickname);
    } else {
        delete_user_meta($user_id, 'nickname');
    }

    wp_send_json_success(['message' => __('Profile saved.', 'rag-ai-chatbot')]);
}

/**
 * AJAX: change email (requires current password).
 */
function rag_ajax_update_user_email()
{
    check_ajax_referer('rag_user_settings', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => __('You must be logged in.', 'rag-ai-chatbot')], 401);
    }

    $user_id = get_current_user_id();
    $user    = get_userdata($user_id);

    $new_email = isset($_POST['user_email']) ? sanitize_email(wp_unslash($_POST['user_email'])) : '';
    $password  = isset($_POST['email_confirm_password']) ? wp_unslash($_POST['email_confirm_password']) : '';

    if ($new_email === '' || !is_email($new_email)) {
        wp_send_json_error(['message' => __('Please enter a valid email address.', 'rag-ai-chatbot')]);
    }

    if ($password === '') {
        wp_send_json_error(['message' => __('Password is required to change your email.', 'rag-ai-chatbot')]);
    }

    if (!wp_check_password($password, $user->user_pass, $user_id)) {
        wp_send_json_error(['message' => __('Incorrect password.', 'rag-ai-chatbot')]);
    }

    if ($new_email === $user->user_email) {
        wp_send_json_success([
            'message' => __('Email unchanged.', 'rag-ai-chatbot'),
            'email'   => $new_email,
        ]);
    }

    $owner_id = email_exists($new_email);
    if ($owner_id && (int) $owner_id !== (int) $user_id) {
        wp_send_json_error(['message' => __('That email address is already in use.', 'rag-ai-chatbot')]);
    }

    $result = wp_update_user([
        'ID'         => $user_id,
        'user_email' => $new_email,
    ]);

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }

    wp_send_json_success([
        'message' => __('Email updated.', 'rag-ai-chatbot'),
        'email'   => $new_email,
    ]);
}

/**
 * AJAX: change password (requires current password).
 */
function rag_ajax_update_user_password()
{
    check_ajax_referer('rag_user_settings', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => __('You must be logged in.', 'rag-ai-chatbot')], 401);
    }

    $user_id = get_current_user_id();
    $user    = get_userdata($user_id);

    $current = isset($_POST['current_password']) ? wp_unslash($_POST['current_password']) : '';
    $new     = isset($_POST['new_password']) ? wp_unslash($_POST['new_password']) : '';
    $confirm = isset($_POST['confirm_password']) ? wp_unslash($_POST['confirm_password']) : '';

    if ($current === '' || $new === '' || $confirm === '') {
        wp_send_json_error(['message' => __('Please fill in all password fields.', 'rag-ai-chatbot')]);
    }

    if (!wp_check_password($current, $user->user_pass, $user_id)) {
        wp_send_json_error(['message' => __('Current password is incorrect.', 'rag-ai-chatbot')]);
    }

    if (strlen($new) < 8) {
        wp_send_json_error(['message' => __('New password must be at least 8 characters.', 'rag-ai-chatbot')]);
    }

    if ($new !== $confirm) {
        wp_send_json_error(['message' => __('New passwords do not match.', 'rag-ai-chatbot')]);
    }

    wp_set_password($new, $user_id);

    wp_set_auth_cookie($user_id, false, is_ssl());
    wp_set_current_user($user_id);

    wp_send_json_success(['message' => __('Password updated. You remain logged in.', 'rag-ai-chatbot')]);
}

add_action('wp_ajax_rag_save_user_profile', 'rag_ajax_save_user_profile');
add_action('wp_ajax_rag_update_user_email', 'rag_ajax_update_user_email');
add_action('wp_ajax_rag_update_user_password', 'rag_ajax_update_user_password');

function rag_chatbot_ui() {

    ob_start();
    ?>

    <div id="rag-chat-widget">

        <div id="rag-chat-box"></div>

        <div style="display:flex; gap:10px; margin-top:10px;">

            <input
                type="text"
                id="rag-user-input"
                placeholder="Ask something..."
                style="flex:1; padding:10px;"
            >

            <button onclick="sendRagMessage()">
                Send
            </button>

        </div>

    </div>

    <style>

        #rag-chat-widget{
            max-width:700px;
            margin:auto;
            border:1px solid #ddd;
            padding:20px;
            border-radius:10px;
            background:#fafafa;
        }

        #rag-chat-box{
            height:400px;
            overflow:auto;
            background:white;
            padding:10px;
            border-radius:10px;
        }

        .msg{
            margin-bottom:10px;
        }

    </style>

    <script>

    function sendRagMessage() {

        let message = document.getElementById("rag-user-input").value;

        fetch("<?php echo admin_url('admin-ajax.php?action=rag_chat'); ?>", {

            method: "POST",

            headers: {
                "Content-Type": "application/x-www-form-urlencoded"
            },

            body: "message=" + encodeURIComponent(message)

        })

        .then(res => res.json())

        .then(data => {

            document.getElementById("rag-chat-box").innerHTML +=
                "<div class='msg'><b>You:</b> " + message + "</div>";

            document.getElementById("rag-chat-box").innerHTML +=
                "<div class='msg'><b>Bot:</b> " + data.reply + "</div>";

        });

    }

    </script>

    <?php

    return ob_get_clean();
}

add_action('wp_ajax_rag_chat', 'rag_chat');
add_action('wp_ajax_nopriv_rag_chat', 'rag_chat');

function rag_chat() {

    $message = sanitize_text_field($_POST['message']);

    $response = wp_remote_post(
        'http://localhost:8000/ask',

        [
            'headers' => [
                'Content-Type' => 'application/json',
            ],

            'body' => json_encode([
                'question' => $message
            ]),

            'timeout' => 120
        ]
    );

    if (is_wp_error($response)) {

        wp_send_json([
            'reply' => $response->get_error_message()
        ]);
    }

    $body = json_decode(
        wp_remote_retrieve_body($response),
        true
    );

    wp_send_json([
        'reply' => $body['answer']
    ]);
}