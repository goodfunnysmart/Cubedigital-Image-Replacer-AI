<?php

namespace CubeDigital\ImageReplacer;

if (!defined('ABSPATH')) {
    exit;
}

class Admin {
    public static function init() {
        add_filter('media_row_actions', [self::class, 'row_actions'], 10, 2);
        add_filter('attachment_fields_to_edit', [self::class, 'fields'], 10, 2);
        add_action('add_meta_boxes_attachment', [self::class, 'meta_box']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_screen']);
        add_action('wp_enqueue_media', [self::class, 'enqueue']);
        add_action('wp_ajax_cdair_start', [self::class, 'start']);
        add_action('wp_ajax_cdair_poll', [self::class, 'poll']);
        add_action('wp_ajax_cdair_restore', [self::class, 'restore']);
        add_action('wp_ajax_cdair_test', [self::class, 'test']);
        add_action('wp_ajax_cdair_replace', [self::class, 'post_replace']);
        add_action('wp_ajax_cdair_restore', [self::class, 'post_restore']);
        add_action('admin_post_cdair_replace', [self::class, 'post_replace']);
        add_action('admin_post_cdair_restore', [self::class, 'post_restore']);
        add_action('admin_notices', [self::class, 'notice']);
        add_filter('wp_get_attachment_image_src', [self::class, 'bust_admin_cache'], 10, 2);
    }

    public static function enqueue_screen($hook) {
        if (in_array($hook, ['upload.php', 'post.php', 'settings_page_cdair-settings'], true)) {
            self::enqueue();
        }
    }

    public static function enqueue() {
        if (!current_user_can('upload_files') && !current_user_can('manage_options')) {
            return;
        }
        wp_enqueue_script('cdair-admin', plugins_url('assets/admin.js', CDAIR_FILE), [], CDAIR_VERSION, false);
        wp_localize_script('cdair-admin', 'cdair', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cdair_media'),
            'confirmReplace' => sprintf(
                __('Replace this image with a %d%% AI variation? The file address stays the same. The original is kept so you can restore it. This uses Replicate credits.', 'cubedigital-image-replacer'),
                (int) Settings::get('variation', 5)
            ),
            'confirmRestore' => __('Restore the original image? The current file will be overwritten with the backup.', 'cubedigital-image-replacer'),
            'working' => __('Generating… this can take a minute', 'cubedigital-image-replacer'),
            'restoring' => __('Restoring…', 'cubedigital-image-replacer'),
            'testing' => __('Testing…', 'cubedigital-image-replacer'),
            'failed' => __('The request failed. Nothing was changed if you did not get a success message.', 'cubedigital-image-replacer'),
            'timeout' => __('Replicate took too long. Check the image before trying again. If it looks unchanged, nothing was saved.', 'cubedigital-image-replacer'),
        ]);
    }

    public static function row_actions($actions, $post) {
        if (!self::can_edit($post->ID) || !Replacer::is_supported($post->ID)) {
            return $actions;
        }
        $actions['cdair_replace'] = self::control($post->ID, 'cdair_replace', 'cdair_start', __('AI replace', 'cubedigital-image-replacer'), false);
        if (get_post_meta($post->ID, '_cdair_has_original', true)) {
            $actions['cdair_restore'] = self::control($post->ID, 'cdair_restore', 'cdair_restore', __('Restore original', 'cubedigital-image-replacer'), false);
        }
        return $actions;
    }

    public static function fields($form_fields, $post) {
        if (!self::can_edit($post->ID) || !Replacer::is_supported($post->ID)) {
            return $form_fields;
        }
        $form_fields['cdair_actions'] = [
            'label' => __('AI variation', 'cubedigital-image-replacer'),
            'input' => 'html',
            'html' => self::buttons($post->ID),
        ];
        return $form_fields;
    }

    public static function meta_box($post) {
        if (!Replacer::is_supported($post->ID)) {
            return;
        }
        add_meta_box(
            'cdair-replace',
            __('AI Image Replacer', 'cubedigital-image-replacer'),
            [self::class, 'render_meta_box'],
            'attachment',
            'side',
            'high'
        );
    }

    public static function render_meta_box($post) {
        echo '<p>' . esc_html__('Overwrite this file with a close AI variation. The URL does not change, and the original is kept.', 'cubedigital-image-replacer') . '</p>';
        $stamp = (int) get_post_meta($post->ID, '_cdair_replaced_at', true);
        if ($stamp && get_post_meta($post->ID, '_cdair_has_original', true)) {
            echo '<p>' . esc_html(sprintf(
                __('Last changed: %s', 'cubedigital-image-replacer'),
                wp_date(get_option('date_format') . ' ' . get_option('time_format'), $stamp)
            )) . '</p>';
        }
        echo self::buttons($post->ID);
    }

    public static function start() {
        self::authorize();
        $id = self::attachment_id();
        if (get_transient('cdair_lock_' . $id)) {
            wp_send_json_error(['message' => __('A variation is already running for this image.', 'cubedigital-image-replacer')]);
        }
        set_transient('cdair_lock_' . $id, 1, 3 * MINUTE_IN_SECONDS);

        $ready = Replacer::preflight($id);
        if (is_wp_error($ready)) {
            delete_transient('cdair_lock_' . $id);
            wp_send_json_error(['message' => $ready->get_error_message()]);
        }

        if (function_exists('set_time_limit')) {
            set_time_limit(180);
        }
        $prediction = Replicate::create($id);
        if (is_wp_error($prediction)) {
            delete_transient('cdair_lock_' . $id);
            wp_send_json_error(['message' => $prediction->get_error_message()]);
        }

        set_transient('cdair_job_' . $id, [
            'get' => $prediction['urls']['get'],
            'id' => isset($prediction['id']) ? (string) $prediction['id'] : '',
            'user' => get_current_user_id(),
        ], 10 * MINUTE_IN_SECONDS);

        wp_send_json_success(['status' => 'processing']);
    }

    public static function poll() {
        self::authorize();
        $id = self::attachment_id();
        $job = get_transient('cdair_job_' . $id);
        if (is_array($job) && (int) $job['user'] !== get_current_user_id()) {
            wp_send_json_error(['message' => __('A variation is already running for this image.', 'cubedigital-image-replacer')]);
        }
        if (!is_array($job) || empty($job['get'])) {
            delete_transient('cdair_lock_' . $id);
            wp_send_json_error(['message' => __('No variation is running for this image.', 'cubedigital-image-replacer')]);
        }

        $prediction = Replicate::fetch($job['get']);
        if (is_wp_error($prediction)) {
            self::clear_job($id);
            wp_send_json_error(['message' => $prediction->get_error_message()]);
        }

        $status = isset($prediction['status']) ? (string) $prediction['status'] : '';
        if ($status === 'starting' || $status === 'processing') {
            wp_send_json_success(['status' => 'processing']);
        }
        if ($status !== 'succeeded') {
            self::clear_job($id);
            $error = !empty($prediction['error']) ? (string) $prediction['error'] : __('Replicate did not return an image, so nothing was changed.', 'cubedigital-image-replacer');
            wp_send_json_error(['message' => $error]);
        }

        if (!empty($job['id']) && get_transient('cdair_done_' . $id) === $job['id']) {
            self::clear_job($id);
            wp_send_json_success(['message' => self::success_message(true)]);
        }
        if (get_transient('cdair_saving_' . $id)) {
            wp_send_json_success(['status' => 'processing']);
        }
        set_transient('cdair_saving_' . $id, 1, 3 * MINUTE_IN_SECONDS);

        if (function_exists('set_time_limit')) {
            set_time_limit(180);
        }
        $binary = Replicate::download(Replicate::output_url($prediction));
        if (is_wp_error($binary)) {
            self::clear_job($id);
            wp_send_json_error(['message' => $binary->get_error_message()]);
        }

        $saved = Replacer::commit($id, $binary, Settings::saturation());
        if (is_wp_error($saved)) {
            self::clear_job($id);
            wp_send_json_error(['message' => $saved->get_error_message()]);
        }

        set_transient('cdair_done_' . $id, $job['id'], 2 * MINUTE_IN_SECONDS);
        self::clear_job($id);
        wp_send_json_success(['message' => self::success_message(!empty($saved['regenerated']))]);
    }

    public static function restore() {
        self::authorize();
        $id = self::attachment_id();
        if (function_exists('set_time_limit')) {
            set_time_limit(180);
        }
        $restored = Replacer::restore($id);
        if (is_wp_error($restored)) {
            wp_send_json_error(['message' => $restored->get_error_message()]);
        }
        wp_send_json_success(['message' => __('Original image restored and thumbnails regenerated.', 'cubedigital-image-replacer')]);
    }

    public static function test() {
        if (!check_ajax_referer('cdair_media', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You cannot test the API token.', 'cubedigital-image-replacer')], 403);
        }
        $result = Replicate::test();
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['message' => $result]);
    }

    public static function bust_admin_cache($image, $attachment_id) {
        if (!is_admin() || !is_array($image) || empty($image[0])) {
            return $image;
        }
        $stamp = get_post_meta($attachment_id, '_cdair_replaced_at', true);
        if (!$stamp) {
            return $image;
        }
        $image[0] = add_query_arg('cdair', $stamp, $image[0]);
        return $image;
    }

    public static function post_replace() {
        $id = self::post_attachment_id('cdair_replace');
        self::redirect_with_notice(self::run_replace($id));
    }

    public static function post_restore() {
        $id = self::post_attachment_id('cdair_restore');
        if (function_exists('set_time_limit')) {
            set_time_limit(180);
        }
        $restored = Replacer::restore($id);
        if (is_wp_error($restored)) {
            self::redirect_with_notice($restored);
        }
        self::redirect_with_notice(__('Original image restored and thumbnails regenerated.', 'cubedigital-image-replacer'));
    }

    public static function notice() {
        $key = 'cdair_notice_' . get_current_user_id();
        $notice = get_transient($key);
        if (!is_array($notice) || empty($notice['message'])) {
            return;
        }
        delete_transient($key);
        $class = !empty($notice['error']) ? 'notice notice-error' : 'notice notice-success';
        echo '<div class="' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
    }

    public static function run_replace($attachment_id) {
        if (get_transient('cdair_lock_' . $attachment_id)) {
            return new \WP_Error('cdair_busy', __('A variation is already running for this image.', 'cubedigital-image-replacer'));
        }
        set_transient('cdair_lock_' . $attachment_id, 1, 3 * MINUTE_IN_SECONDS);

        $ready = Replacer::preflight($attachment_id);
        if (is_wp_error($ready)) {
            delete_transient('cdair_lock_' . $attachment_id);
            return $ready;
        }
        if (function_exists('set_time_limit')) {
            set_time_limit(180);
        }

        $prediction = Replicate::create($attachment_id);
        if (is_wp_error($prediction) || empty($prediction['urls']['get'])) {
            delete_transient('cdair_lock_' . $attachment_id);
            return is_wp_error($prediction) ? $prediction : new \WP_Error('cdair_replicate', __('Replicate did not start a prediction.', 'cubedigital-image-replacer'));
        }

        $get = $prediction['urls']['get'];
        $status = isset($prediction['status']) ? (string) $prediction['status'] : 'starting';
        $deadline = time() + 150;
        while (($status === 'starting' || $status === 'processing') && time() < $deadline) {
            sleep(2);
            $prediction = Replicate::fetch($get);
            if (is_wp_error($prediction)) {
                delete_transient('cdair_lock_' . $attachment_id);
                return $prediction;
            }
            $status = isset($prediction['status']) ? (string) $prediction['status'] : '';
        }

        if ($status !== 'succeeded') {
            delete_transient('cdair_lock_' . $attachment_id);
            $error = !empty($prediction['error']) ? (string) $prediction['error'] : __('Replicate did not return an image, so nothing was changed.', 'cubedigital-image-replacer');
            return new \WP_Error('cdair_replicate', $error);
        }

        $binary = Replicate::download(Replicate::output_url($prediction));
        if (is_wp_error($binary)) {
            delete_transient('cdair_lock_' . $attachment_id);
            return $binary;
        }

        $saved = Replacer::commit($attachment_id, $binary, Settings::saturation());
        delete_transient('cdair_lock_' . $attachment_id);
        if (is_wp_error($saved)) {
            return $saved;
        }
        return self::success_message(!empty($saved['regenerated']));
    }

    private static function buttons($attachment_id) {
        $html = self::control($attachment_id, 'cdair_replace', 'cdair_start', __('Replace with AI variation', 'cubedigital-image-replacer'), true);
        if (get_post_meta($attachment_id, '_cdair_has_original', true)) {
            $html .= '<br><br>' . self::control($attachment_id, 'cdair_restore', 'cdair_restore', __('Restore original', 'cubedigital-image-replacer'), true);
        }
        return $html;
    }

    private static function control($attachment_id, $post_action, $ajax_action, $label, $button) {
        return sprintf(
            '<a href="%1$s" class="%2$s" data-cdair-action="%3$s" data-id="%4$d">%5$s</a>',
            esc_url(self::action_url($attachment_id, $post_action)),
            $button ? 'button cdair-action' : 'cdair-action',
            esc_attr($ajax_action),
            (int) $attachment_id,
            esc_html($label)
        );
    }

    private static function action_url($attachment_id, $action) {
        return wp_nonce_url(
            add_query_arg(
                [
                    'action' => $action,
                    'attachment_id' => (int) $attachment_id,
                ],
                admin_url('admin-ajax.php')
            ),
            $action . '_' . (int) $attachment_id
        );
    }

    private static function post_attachment_id($action) {
        $id = isset($_REQUEST['attachment_id']) ? absint($_REQUEST['attachment_id']) : 0;
        if (!$id || !self::can_edit($id)) {
            wp_die(esc_html__('You cannot edit this image.', 'cubedigital-image-replacer'));
        }
        check_admin_referer($action . '_' . $id);
        return $id;
    }

    private static function redirect_with_notice($result) {
        $error = is_wp_error($result);
        set_transient('cdair_notice_' . get_current_user_id(), [
            'error' => $error,
            'message' => $error ? $result->get_error_message() : (string) $result,
        ], MINUTE_IN_SECONDS);
        $back = wp_get_referer();
        if (!$back || strpos($back, 'admin-post.php') !== false || strpos($back, 'admin-ajax.php') !== false) {
            $back = admin_url('upload.php');
        }
        wp_safe_redirect($back);
        exit;
    }

    private static function authorize() {
        if (!check_ajax_referer('cdair_media', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed. Reload the page and try again.', 'cubedigital-image-replacer')], 403);
        }
        $id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        if (!$id || !self::can_edit($id)) {
            wp_send_json_error(['message' => __('You cannot edit this image.', 'cubedigital-image-replacer')], 403);
        }
    }

    private static function attachment_id() {
        return isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
    }

    private static function can_edit($attachment_id) {
        return current_user_can('upload_files') && current_user_can('edit_post', $attachment_id);
    }

    private static function clear_job($attachment_id) {
        delete_transient('cdair_job_' . $attachment_id);
        delete_transient('cdair_lock_' . $attachment_id);
        delete_transient('cdair_saving_' . $attachment_id);
    }

    private static function success_message($regenerated) {
        if ($regenerated) {
            return __('Replaced this image and regenerated its thumbnails. The file address is unchanged. Clear any cache if the old picture is still showing.', 'cubedigital-image-replacer');
        }
        return __('The image file was replaced, but thumbnails could not be regenerated. Clear any cache and check the image sizes.', 'cubedigital-image-replacer');
    }
}
