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

function render_text_field($name, $constant, $option, $args)
{
    $value = defined($constant) ? constant($constant) : get_option($option);
    $disabled = defined($constant);
    ?>
    <input name="<?php echo esc_attr($name); ?>" type="text" id="<?php echo esc_attr($args['label_for']); ?>" value="<?php echo esc_attr($value); ?>" class="regular-text" <?php echo $disabled ? 'disabled' : ''; ?>>
    <?php
}

function render_checkbox_field($name, $constant, $option, $args)
{
    $value = defined($constant) ? constant($constant) : get_option($option);
    $disabled = defined($constant);
    ?>
    <label for="<?php echo esc_attr($args['label_for']); ?>">
        <input name="<?php echo esc_attr($name); ?>" type="checkbox" id="<?php echo esc_attr($args['label_for']); ?>" <?php echo $value ? 'checked' : ''; ?> <?php echo $disabled ? 'disabled' : ''; ?>>
        <?php echo __('redirect to Cloudflare Access', 'auto-login-with-cloudflare'); ?>
    </label>
    <?php
}

function field_auth_domain_cb($args)
{
    render_text_field('AutoLoginWithCloudflare_auth_domain', 'WP_CF_ACCESS_AUTH_DOMAIN', 'AutoLoginWithCloudflare_auth_domain', $args);
}

function field_aud_cb($args)
{
    render_text_field('AutoLoginWithCloudflare_aud', 'WP_CF_ACCESS_JWT_AUD', 'AutoLoginWithCloudflare_aud', $args);
}

function field_redirect_login_page_cb($args)
{
    render_checkbox_field('AutoLoginWithCloudflare_redirect_login_page', 'WP_CF_ACCESS_REDIRECT_LOGIN', 'AutoLoginWithCloudflare_redirect_login_page', $args);
}

add_action('admin_menu', function () {
    add_options_page(
        __('Auto Login with Cloudflare', 'auto-login-with-cloudflare'),
        __('Auto Login with Cloudflare', 'auto-login-with-cloudflare'),
        'manage_options',
        'AutoLoginWithCloudflare',
        function () {
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
    );
});
