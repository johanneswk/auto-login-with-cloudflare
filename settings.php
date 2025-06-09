<?php

namespace AutoLoginWithCloudflare;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

function settings_init()
{
    register_setting('AutoLoginWithCloudflare', 'AutoLoginWithCloudflare_auth_domain');
    register_setting('AutoLoginWithCloudflare', 'AutoLoginWithCloudflare_aud');
    register_setting('AutoLoginWithCloudflare', 'AutoLoginWithCloudflare_redirect_login_page');

    add_settings_section(
        'AutoLoginWithCloudflare_section_general',
        __('Application settings', 'auto-login-with-cloudflare'),
        __NAMESPACE__ . '\\section_general_callback',
        'AutoLoginWithCloudflare'
    );

    add_settings_field(
        'AutoLoginWithCloudflare_field_auth_domain',
        __('Auth domain', 'auto-login-with-cloudflare'),
        __NAMESPACE__ . '\\field_auth_domain_cb',
        'AutoLoginWithCloudflare',
        'AutoLoginWithCloudflare_section_general',
        array(
            'label_for' => 'AutoLoginWithCloudflare_field_auth_domain',
            'class' => 'AutoLoginWithCloudflare_row',
        )
    );

    add_settings_field(
        'AutoLoginWithCloudflare_field_aud',
        __('Application audience (AUD) tag', 'auto-login-with-cloudflare'),
        __NAMESPACE__ . '\\field_aud_cb',
        'AutoLoginWithCloudflare',
        'AutoLoginWithCloudflare_section_general',
        array(
            'label_for' => 'AutoLoginWithCloudflare_field_aud',
            'class' => 'AutoLoginWithCloudflare_row',
        )
    );

    add_settings_field(
        'AutoLoginWithCloudflare_field_redirect_login_page',
        __('Redirect login page', 'auto-login-with-cloudflare'),
        __NAMESPACE__ . '\\field_redirect_login_page_cb',
        'AutoLoginWithCloudflare',
        'AutoLoginWithCloudflare_section_general',
        array(
            'label_for' => 'AutoLoginWithCloudflare_field_redirect_login_page',
            'class' => 'AutoLoginWithCloudflare_row',
        )
    );
}

add_action('admin_init', __NAMESPACE__ . '\\settings_init');

function section_general_callback($args)
{
}

function field_auth_domain_cb($args)
{
    if (defined('WP_CF_ACCESS_AUTH_DOMAIN')) {
        $auth_domain = constant('WP_CF_ACCESS_AUTH_DOMAIN');
        $disabled = true;
    } else {
        $auth_domain = get_option('AutoLoginWithCloudflare_auth_domain');
        $disabled = false;
    }
?>
    <input name="AutoLoginWithCloudflare_auth_domain" type="text" id="<?php echo $args['label_for'] ?>" value="<?php echo esc_html_e($auth_domain) ?>" class="regular-text" <?php echo $disabled ? "disabled" : "" ?>>
<?php
}

function field_aud_cb($args)
{
    if (defined('WP_CF_ACCESS_JWT_AUD')) {
        $aud = constant('WP_CF_ACCESS_JWT_AUD');
        $disabled = true;
    } else {
        $aud = get_option('AutoLoginWithCloudflare_aud');
        $disabled = false;
    }
?>
    <input name="AutoLoginWithCloudflare_aud" type="text" id="<?php echo $args['label_for'] ?>" value="<?php echo esc_html_e($aud) ?>" class="regular-text" <?php echo $disabled ? "disabled" : "" ?>>
<?php
}

function field_redirect_login_page_cb($args)
{
    if (defined('WP_CF_ACCESS_REDIRECT_LOGIN')) {
        $redirect_login_page = constant('WP_CF_ACCESS_REDIRECT_LOGIN');
        $disabled = true;
    } else {
        $redirect_login_page = get_option('AutoLoginWithCloudflare_redirect_login_page');
        $disabled = false;
    }
?>
    <label for="<?php echo $args['label_for'] ?>">
        <input name="AutoLoginWithCloudflare_redirect_login_page" type="checkbox" id="<?php echo $args['label_for'] ?>" <?php echo $redirect_login_page ? "checked" : "" ?> <?php echo $disabled ? "disabled" : "" ?>>
        <?php echo __('redirect to Cloudflare Access', 'auto-login-with-cloudflare') ?>
    </label>
<?php
}

function settings_page()
{
    add_options_page(
        __('Auto Login with Cloudflare', 'auto-login-with-cloudflare'),
        __('Auto Login with Cloudflare', 'auto-login-with-cloudflare'),
        'manage_options',
        'AutoLoginWithCloudflare',
        __NAMESPACE__ . '\\settings_page_html'
    );
}
add_action('admin_menu', __NAMESPACE__ . '\\settings_page');

function settings_page_html()
{
    if (!current_user_can('manage_options')) {
        return;
    }
?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="post">
            <?php

            settings_fields('AutoLoginWithCloudflare');
            do_settings_sections('AutoLoginWithCloudflare');
            submit_button(__('Save Settings', 'auto-login-with-cloudflare'));

            ?>
        </form>
    </div>
<?php
}
