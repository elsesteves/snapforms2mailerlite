<?php
/**
 * Plugin Name: SnapForms2MailerLite - MailerLite Integration for SnapForms submissions
 * Description: Add subscribers to MailerLite lists upon SnapForms form submission.
 * Version:     1.0.0
 * Author:      Eduardo Esteves
 * Author URI: https://edluis97.github.io/ 
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__.'/includes/MailerLiteClient.php';
global $snapforms2mailerlite_configsDir;
global $snapforms2mailerlite_forms;

// Store config outside plugin: wp-content/snapforms/addons/mailerlite/config.json
$snapforms2mailerlite_configsDir = trailingslashit(WP_CONTENT_DIR) . 'snapforms/addons/mailerlite/config.json';
try {
    $snapforms2mailerlite_forms = MailerLiteClient::loadFormsConfig($snapforms2mailerlite_configsDir);
} catch (Exception $e) {
    // Fail gracefully if config is missing/invalid
    error_log('[SnapForms2MailerLite] Config error: ' . $e->getMessage());
}

add_filter('query_vars', function ($vars) {
    $vars[] = 'id_form';
    $vars[] = 'id_submission';
    $vars[] = 'token';
    return $vars;
});

register_activation_hook(__FILE__, 'snapforms2mailerlite_install');

function snapforms2mailerlite_install() {
    // Create sample config in wp-content if missing (do not overwrite existing)
    global $snapforms2mailerlite_configsDir;

    $configPath = $snapforms2mailerlite_configsDir;
    if (file_exists($configPath)) {
        return; // Respect existing config
    }

    $dir = dirname($configPath);
    if (!is_dir($dir)) {
        if (function_exists('wp_mkdir_p')) {
            wp_mkdir_p($dir);
        } else {
            mkdir($dir, 0755, true);
        }
    }

    $sample = [
        'forms' => [
            [
                'id_form' => 'SNAPFORMS_FORM_ID',
                'api_token' => 'MAILERLITE_API_TOKEN',
                'fields' => [
                    'SNAPFORMS_FIELD_UUID_A' => 'MAILERLITE_FIELD_NAME_A',
                    'SNAPFORMS_FIELD_UUID_B' => 'MAILERLITE_FIELD_NAME_B',
                    'SNAPFORMS_FIELD_UUID_C' => 'MAILERLITE_FIELD_NAME_C',
                ],
                'required_consents' => [
                    'SNAPFORMS_FIELD_UUID'
                ],
                'groups' => [
                    'MAILERLITE_GROUP_ID'
                ],
                'settings' => [
                    'timeout' => 30
                ]
            ]
        ]
    ];

    file_put_contents($configPath, wp_json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

add_action('snapforms.submission.new', function($args) {
    $id_submission = $args['id_submission'];
    $id_form = $args['id_form'];

    if(empty($id_submission) || empty($id_form)) {
        return;
    }

    $submission = apply_filters('snapforms_msgbus', "submission/".$id_form."/".$id_submission."/obtain", array(
        'include_context' => true,
    ))['data'] ?? null;

    global $snapforms2mailerlite_forms;
    $formConfig = $snapforms2mailerlite_forms[$id_form] ?? null;
    if (!$formConfig) {
        return; // No config for this form
    }

    try {
        $client = new MailerLiteClient($formConfig);
    } catch (Exception $e) {
        error_log('[SnapForms2MailerLite] Form config error: ' . $e->getMessage());
        return;
    }

    $result = $client->addSubscriber($submission);
    if (is_wp_error($result)) {
        // Optionally log errors, but keep silent in UI
        error_log('[SnapForms2MailerLite] ' . $result->get_error_message());
    }
});
