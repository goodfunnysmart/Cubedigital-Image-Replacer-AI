<?php

namespace CubeDigital\ImageReplacer;

if (!defined('ABSPATH')) {
    exit;
}

class Settings {
    const OPTION = 'cdair_settings';

    public static function init() {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'register']);
        add_filter('plugin_action_links_' . plugin_basename(CDAIR_FILE), [self::class, 'links']);
    }

    public static function menu() {
        add_options_page(
            __('AI Image Replacer', 'cubedigital-image-replacer'),
            __('AI Image Replacer', 'cubedigital-image-replacer'),
            'manage_options',
            'cdair-settings',
            [self::class, 'render']
        );
    }

    public static function links($links) {
        $url = admin_url('options-general.php?page=cdair-settings');
        array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'cubedigital-image-replacer') . '</a>');
        return $links;
    }

    public static function register() {
        register_setting(self::OPTION, self::OPTION, [
            'sanitize_callback' => [self::class, 'sanitize'],
            'default' => self::defaults(),
        ]);

        add_settings_section(
            'cdair_main',
            __('Replicate and image defaults', 'cubedigital-image-replacer'),
            [self::class, 'section'],
            'cdair-settings'
        );

        add_settings_field('api_token', __('API token', 'cubedigital-image-replacer'), [self::class, 'field_token'], 'cdair-settings', 'cdair_main');
        add_settings_field('variation', __('Variation', 'cubedigital-image-replacer'), [self::class, 'field_variation'], 'cdair-settings', 'cdair_main');
        add_settings_field('saturation', __('Saturation', 'cubedigital-image-replacer'), [self::class, 'field_saturation'], 'cdair-settings', 'cdair_main');
        add_settings_field('prompt', __('Prompt', 'cubedigital-image-replacer'), [self::class, 'field_prompt'], 'cdair-settings', 'cdair_main');
        add_settings_field('negative_prompt', __('Negative prompt', 'cubedigital-image-replacer'), [self::class, 'field_negative'], 'cdair-settings', 'cdair_main');
        add_settings_field('model', __('Model', 'cubedigital-image-replacer'), [self::class, 'field_model'], 'cdair-settings', 'cdair_main');
    }

    public static function section() {
        echo '<p>' . esc_html__('Each replacement calls Replicate, overwrites the media file in place, and regenerates thumbnails. Existing links keep working because the file name does not change. The first version is kept so it can be restored.', 'cubedigital-image-replacer') . '</p>';
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap">';
        echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';
        echo '<p>' . esc_html(sprintf(__('Plugin version %s', 'cubedigital-image-replacer'), CDAIR_VERSION)) . '</p>';
        echo '<form action="options.php" method="post">';
        settings_fields(self::OPTION);
        do_settings_sections('cdair-settings');
        submit_button();
        echo '</form>';
        echo '<p><button type="button" class="button" id="cdair-test">' . esc_html__('Test connection', 'cubedigital-image-replacer') . '</button> <span id="cdair-test-result"></span></p>';
        echo '</div>';
    }

    public static function field_token() {
        $saved = self::token() !== '';
        echo '<input type="password" class="regular-text" name="cdair_settings[api_token]" value="" autocomplete="new-password" placeholder="' . esc_attr($saved ? __('Token saved', 'cubedigital-image-replacer') : '') . '">';
        echo '<p class="description">' . esc_html__('Create a token at replicate.com/account/api-tokens. Leave this blank to keep the saved token.', 'cubedigital-image-replacer') . '</p>';
        if ($saved) {
            echo '<label><input type="checkbox" name="cdair_settings[clear_token]" value="1"> ' . esc_html__('Remove saved token', 'cubedigital-image-replacer') . '</label>';
        }
    }

    public static function field_variation() {
        printf(
            '<input type="number" min="1" max="40" step="1" name="cdair_settings[variation]" value="%d">',
            (int) self::get('variation', 5)
        );
        echo '<p class="description">' . esc_html__('How far the new image may drift from the original. 5 stays very close. If a result looks unchanged, try 10 to 15. This uses Replicate prompt strength.', 'cubedigital-image-replacer') . '</p>';
    }

    public static function field_saturation() {
        printf(
            '<input type="number" min="0" max="200" step="1" name="cdair_settings[saturation]" value="%d">',
            (int) self::get('saturation', 100)
        );
        echo '<p class="description">' . esc_html__('Applied after the AI variation. 100 leaves saturation unchanged. 80 is slightly muted, 120 is slightly richer.', 'cubedigital-image-replacer') . '</p>';
    }

    public static function field_prompt() {
        printf(
            '<textarea class="large-text" rows="3" name="cdair_settings[prompt]">%s</textarea>',
            esc_textarea(self::prompt())
        );
    }

    public static function field_negative() {
        printf(
            '<textarea class="large-text" rows="3" name="cdair_settings[negative_prompt]">%s</textarea>',
            esc_textarea(self::negative_prompt())
        );
    }

    public static function field_model() {
        printf(
            '<input type="text" class="regular-text" name="cdair_settings[model]" value="%s">',
            esc_attr(self::model())
        );
        echo '<p class="description">' . esc_html__('Leave this as stability-ai/sdxl unless the replacement model accepts an image, a prompt, and prompt_strength.', 'cubedigital-image-replacer') . '</p>';
    }

    public static function sanitize($input) {
        $existing = get_option(self::OPTION, []);
        if (!is_array($existing)) {
            $existing = [];
        }
        $clean = self::defaults();
        if (!empty($input['clear_token'])) {
            $clean['api_token'] = '';
        } elseif (isset($input['api_token']) && $input['api_token'] !== '') {
            $clean['api_token'] = sanitize_text_field($input['api_token']);
        } else {
            $clean['api_token'] = isset($existing['api_token']) ? sanitize_text_field($existing['api_token']) : '';
        }
        $clean['variation'] = min(40, max(1, absint($input['variation'] ?? 5)));
        $clean['saturation'] = min(200, max(0, absint($input['saturation'] ?? 100)));
        $clean['prompt'] = self::trim_text($input['prompt'] ?? '');
        $clean['negative_prompt'] = self::trim_text($input['negative_prompt'] ?? '');
        $model = sanitize_text_field($input['model'] ?? 'stability-ai/sdxl');
        if (preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $model) || preg_match('/^[a-f0-9]{64}$/', $model)) {
            $clean['model'] = $model;
        }
        return $clean;
    }

    public static function defaults() {
        return [
            'api_token' => '',
            'variation' => 5,
            'saturation' => 100,
            'prompt' => 'Keep the exact same subject, composition, framing, lighting and colours. Make only a very subtle photographic variation.',
            'negative_prompt' => 'different subject, different composition, cropped, watermark, text, logo, blurry, distorted, artifacts, extra objects',
            'model' => 'stability-ai/sdxl',
        ];
    }

    public static function all() {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        return array_merge(self::defaults(), $stored);
    }

    public static function get($key, $default = '') {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function token() {
        return trim((string) self::get('api_token', ''));
    }

    public static function strength() {
        return ((int) self::get('variation', 5)) / 100;
    }

    public static function saturation() {
        return (int) self::get('saturation', 100);
    }

    public static function prompt() {
        $prompt = trim((string) self::get('prompt', ''));
        if ($prompt === '') {
            return self::defaults()['prompt'];
        }
        return $prompt;
    }

    public static function negative_prompt() {
        $prompt = trim((string) self::get('negative_prompt', ''));
        if ($prompt === '') {
            return self::defaults()['negative_prompt'];
        }
        return $prompt;
    }

    public static function model() {
        $model = trim((string) self::get('model', 'stability-ai/sdxl'));
        return $model !== '' ? $model : 'stability-ai/sdxl';
    }

    private static function trim_text($value) {
        $value = sanitize_textarea_field((string) $value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 1000);
        }
        return substr($value, 0, 1000);
    }
}
