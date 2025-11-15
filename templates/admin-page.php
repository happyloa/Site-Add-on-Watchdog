<?php
/** @var Risk[] $risks */
/** @var string[] $ignored */
/** @var array $settings */
/** @var string $scanNonce */
?>
<div class="wrap">
    <h1><?php esc_html_e('WP Plugin Watchdog', 'wp-plugin-watchdog'); ?></h1>

    <?php $webhookError = get_transient('wp_watchdog_webhook_error'); ?>
    <?php if (! empty($webhookError)) : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($webhookError); ?></p></div>
    <?php endif; ?>

    <?php if (isset($_GET['updated'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'wp-plugin-watchdog'); ?></p></div>
    <?php endif; ?>

    <?php if (isset($_GET['scan'])) : ?>
        <div class="notice notice-info is-dismissible"><p><?php esc_html_e('Manual scan completed.', 'wp-plugin-watchdog'); ?></p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('wp_watchdog_scan'); ?>
        <input type="hidden" name="action" value="wp_watchdog_scan">
        <p><button class="button button-primary" type="submit"><?php esc_html_e('Run manual scan', 'wp-plugin-watchdog'); ?></button></p>
    </form>

    <h2><?php esc_html_e('Potential Risks', 'wp-plugin-watchdog'); ?></h2>
    <?php if (empty($risks)) : ?>
        <p><?php esc_html_e('No risks detected.', 'wp-plugin-watchdog'); ?></p>
    <?php else : ?>
        <?php
        $columns = [
            'plugin'   => __('Plugin', 'wp-plugin-watchdog'),
            'local'    => __('Local Version', 'wp-plugin-watchdog'),
            'remote'   => __('Directory Version', 'wp-plugin-watchdog'),
            'reasons'  => __('Reasons', 'wp-plugin-watchdog'),
            'actions'  => __('Actions', 'wp-plugin-watchdog'),
        ];
        $perPage = (int) apply_filters('wp_watchdog_admin_risks_per_page', 10);
        $normalizeForSort = static function (string $value): string {
            $normalized = function_exists('remove_accents') ? remove_accents($value) : $value;

            return strtolower($normalized);
        };
        ?>
        <div class="wp-watchdog-risk-table" data-wp-watchdog-table data-per-page="<?php echo esc_attr(max($perPage, 1)); ?>">
            <div class="wp-watchdog-risk-table__controls">
                <div class="wp-watchdog-risk-table__pagination" data-pagination>
                    <button type="button" class="button" data-action="prev" aria-label="<?php esc_attr_e('Previous page', 'wp-plugin-watchdog'); ?>" disabled>&lsaquo;</button>
                    <span class="wp-watchdog-risk-table__page-status" data-page-status></span>
                    <button type="button" class="button" data-action="next" aria-label="<?php esc_attr_e('Next page', 'wp-plugin-watchdog'); ?>" disabled>&rsaquo;</button>
                </div>
            </div>
            <div class="wp-watchdog-risk-table__scroll">
                <table class="widefat fixed striped wp-list-table">
                    <thead>
                    <tr>
                        <th scope="col">
                            <button type="button" class="wp-watchdog-risk-table__sort" data-sort-key="sortPlugin" data-sort-default="asc" data-sort-initial aria-sort="ascending">
                                <?php echo esc_html($columns['plugin']); ?>
                                <span class="wp-watchdog-risk-table__sort-indicator" aria-hidden="true"></span>
                            </button>
                        </th>
                        <th scope="col">
                            <button type="button" class="wp-watchdog-risk-table__sort" data-sort-key="sortLocal" data-sort-default="desc" aria-sort="none">
                                <?php echo esc_html($columns['local']); ?>
                                <span class="wp-watchdog-risk-table__sort-indicator" aria-hidden="true"></span>
                            </button>
                        </th>
                        <th scope="col">
                            <button type="button" class="wp-watchdog-risk-table__sort" data-sort-key="sortRemote" data-sort-default="desc" aria-sort="none">
                                <?php echo esc_html($columns['remote']); ?>
                                <span class="wp-watchdog-risk-table__sort-indicator" aria-hidden="true"></span>
                            </button>
                        </th>
                        <th scope="col">
                            <button type="button" class="wp-watchdog-risk-table__sort" data-sort-key="sortReasons" data-sort-default="asc" aria-sort="none">
                                <?php echo esc_html($columns['reasons']); ?>
                                <span class="wp-watchdog-risk-table__sort-indicator" aria-hidden="true"></span>
                            </button>
                        </th>
                        <th scope="col"><?php echo esc_html($columns['actions']); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($risks as $risk) : ?>
                        <?php
                        $remoteVersion = $risk->remoteVersion ?? __('N/A', 'wp-plugin-watchdog');
                        $remoteSort    = is_string($risk->remoteVersion) ? $normalizeForSort($risk->remoteVersion) : '';
                        $reasonParts   = $risk->reasons;
                        if (! empty($risk->details['vulnerabilities'])) {
                            foreach ($risk->details['vulnerabilities'] as $vulnerability) {
                                if (! empty($vulnerability['severity_label'])) {
                                    $reasonParts[] = $vulnerability['severity_label'];
                                }
                                if (! empty($vulnerability['title'])) {
                                    $reasonParts[] = $vulnerability['title'];
                                }
                                if (! empty($vulnerability['cve'])) {
                                    $reasonParts[] = $vulnerability['cve'];
                                }
                                if (! empty($vulnerability['fixed_in'])) {
                                    $reasonParts[] = sprintf(
                                        /* translators: %s is a plugin version number */
                                        __('Fixed in %s', 'wp-plugin-watchdog'),
                                        $vulnerability['fixed_in']
                                    );
                                }
                            }
                        }
                        $reasonSort = $normalizeForSort(implode(' ', $reasonParts));
                        ?>
                        <tr
                            data-sort-plugin="<?php echo esc_attr($normalizeForSort($risk->pluginName)); ?>"
                            data-sort-local="<?php echo esc_attr($normalizeForSort($risk->localVersion)); ?>"
                            data-sort-remote="<?php echo esc_attr($remoteSort); ?>"
                            data-sort-reasons="<?php echo esc_attr($reasonSort); ?>"
                        >
                            <td class="column-primary" data-column="plugin" data-column-label="<?php echo esc_attr($columns['plugin']); ?>">
                                <?php echo esc_html($risk->pluginName); ?>
                            </td>
                            <td data-column="local" data-column-label="<?php echo esc_attr($columns['local']); ?>">
                                <?php echo esc_html($risk->localVersion); ?>
                            </td>
                            <td data-column="remote" data-column-label="<?php echo esc_attr($columns['remote']); ?>">
                                <?php echo esc_html($remoteVersion); ?>
                            </td>
                            <td data-column="reasons" data-column-label="<?php echo esc_attr($columns['reasons']); ?>">
                                <ul>
                                    <?php foreach ($risk->reasons as $reason) : ?>
                                        <li><?php echo esc_html($reason); ?></li>
                                    <?php endforeach; ?>
                                    <?php if (! empty($risk->details['vulnerabilities'])) : ?>
                                        <li>
                                            <?php esc_html_e('WPScan vulnerabilities:', 'wp-plugin-watchdog'); ?>
                                            <ul>
                                                <?php foreach ($risk->details['vulnerabilities'] as $vuln) : ?>
                                                    <li>
                                                        <?php if (! empty($vuln['severity']) && ! empty($vuln['severity_label'])) : ?>
                                                            <?php $severityClass = 'wp-watchdog-severity wp-watchdog-severity--' . sanitize_html_class((string) $vuln['severity']); ?>
                                                            <span class="<?php echo esc_attr($severityClass); ?>"><?php echo esc_html($vuln['severity_label']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if (! empty($vuln['title'])) : ?>
                                                            <span class="wp-watchdog-vulnerability__title"><?php echo esc_html($vuln['title']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if (! empty($vuln['cve'])) : ?>
                                                            <span class="wp-watchdog-vulnerability__cve">- <?php echo esc_html($vuln['cve']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if (! empty($vuln['fixed_in'])) : ?>
                                                            <span class="wp-watchdog-vulnerability__fixed">(<?php printf(esc_html__('Fixed in %s', 'wp-plugin-watchdog'), esc_html($vuln['fixed_in'])); ?>)</span>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </td>
                            <td data-column="actions" data-column-label="<?php echo esc_attr($columns['actions']); ?>">
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <?php wp_nonce_field('wp_watchdog_ignore'); ?>
                                    <input type="hidden" name="action" value="wp_watchdog_ignore">
                                    <input type="hidden" name="plugin_slug" value="<?php echo esc_attr($risk->pluginSlug); ?>">
                                    <button class="button" type="submit"><?php esc_html_e('Ignore', 'wp-plugin-watchdog'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <h2><?php esc_html_e('Ignored Plugins', 'wp-plugin-watchdog'); ?></h2>
    <?php if (empty($ignored)) : ?>
        <p><?php esc_html_e('No plugins are being ignored.', 'wp-plugin-watchdog'); ?></p>
    <?php else : ?>
        <ul>
            <?php foreach ($ignored as $slug) : ?>
                <li>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                        <?php wp_nonce_field('wp_watchdog_unignore'); ?>
                        <input type="hidden" name="action" value="wp_watchdog_unignore">
                        <input type="hidden" name="plugin_slug" value="<?php echo esc_attr($slug); ?>">
                        <button class="button-link" type="submit"><?php echo esc_html($slug); ?> &times;</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <h2><?php esc_html_e('Notifications', 'wp-plugin-watchdog'); ?></h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('wp_watchdog_settings'); ?>
        <input type="hidden" name="action" value="wp_watchdog_save_settings">
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Scan frequency', 'wp-plugin-watchdog'); ?></th>
                <td>
                    <label for="wp-watchdog-notification-frequency" class="screen-reader-text"><?php esc_html_e('Scan frequency', 'wp-plugin-watchdog'); ?></label>
                    <select id="wp-watchdog-notification-frequency" name="settings[notifications][frequency]">
                        <option value="daily" <?php selected($settings['notifications']['frequency'], 'daily'); ?>><?php esc_html_e('Daily', 'wp-plugin-watchdog'); ?></option>
                        <option value="weekly" <?php selected($settings['notifications']['frequency'], 'weekly'); ?>><?php esc_html_e('Weekly', 'wp-plugin-watchdog'); ?></option>
                        <option value="testing" <?php selected($settings['notifications']['frequency'], 'testing'); ?>><?php esc_html_e('Testing (every 10 minutes)', 'wp-plugin-watchdog'); ?></option>
                        <option value="manual" <?php selected($settings['notifications']['frequency'], 'manual'); ?>><?php esc_html_e('Manual (no automatic scans)', 'wp-plugin-watchdog'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Choose how often the automatic scan should run.', 'wp-plugin-watchdog'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Email notifications', 'wp-plugin-watchdog'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="settings[notifications][email][enabled]" <?php checked($settings['notifications']['email']['enabled']); ?> />
                        <?php esc_html_e('Enabled', 'wp-plugin-watchdog'); ?>
                    </label>
                    <p>
                        <label>
                            <?php esc_html_e('Recipients (comma separated)', 'wp-plugin-watchdog'); ?><br />
                            <input type="text" name="settings[notifications][email][recipients]" value="<?php echo esc_attr($settings['notifications']['email']['recipients']); ?>" class="regular-text" />
                        </label>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Discord notifications', 'wp-plugin-watchdog'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="settings[notifications][discord][enabled]" <?php checked($settings['notifications']['discord']['enabled']); ?> />
                        <?php esc_html_e('Enabled', 'wp-plugin-watchdog'); ?>
                    </label>
                    <p>
                        <label>
                            <?php esc_html_e('Discord webhook URL', 'wp-plugin-watchdog'); ?><br />
                            <input type="url" name="settings[notifications][discord][webhook]" value="<?php echo esc_attr($settings['notifications']['discord']['webhook']); ?>" class="regular-text" />
                        </label>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Slack notifications', 'wp-plugin-watchdog'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="settings[notifications][slack][enabled]" <?php checked($settings['notifications']['slack']['enabled']); ?> />
                        <?php esc_html_e('Enabled', 'wp-plugin-watchdog'); ?>
                    </label>
                    <p>
                        <label>
                            <?php esc_html_e('Slack webhook URL', 'wp-plugin-watchdog'); ?><br />
                            <input type="url" name="settings[notifications][slack][webhook]" value="<?php echo esc_attr($settings['notifications']['slack']['webhook']); ?>" class="regular-text" />
                        </label>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Microsoft Teams notifications', 'wp-plugin-watchdog'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="settings[notifications][teams][enabled]" <?php checked($settings['notifications']['teams']['enabled']); ?> />
                        <?php esc_html_e('Enabled', 'wp-plugin-watchdog'); ?>
                    </label>
                    <p>
                        <label>
                            <?php esc_html_e('Teams webhook URL', 'wp-plugin-watchdog'); ?><br />
                            <input type="url" name="settings[notifications][teams][webhook]" value="<?php echo esc_attr($settings['notifications']['teams']['webhook']); ?>" class="regular-text" />
                        </label>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Generic webhook', 'wp-plugin-watchdog'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="settings[notifications][webhook][enabled]" <?php checked($settings['notifications']['webhook']['enabled']); ?> />
                        <?php esc_html_e('Enabled', 'wp-plugin-watchdog'); ?>
                    </label>
                    <p>
                        <label>
                            <?php esc_html_e('Webhook URL', 'wp-plugin-watchdog'); ?><br />
                            <input type="url" name="settings[notifications][webhook][url]" value="<?php echo esc_attr($settings['notifications']['webhook']['url']); ?>" class="regular-text" />
                        </label>
                    </p>
                    <p>
                        <label>
                            <?php esc_html_e('Webhook secret (optional)', 'wp-plugin-watchdog'); ?><br />
                            <input type="text" name="settings[notifications][webhook][secret]" value="<?php echo esc_attr($settings['notifications']['webhook']['secret'] ?? ''); ?>" class="regular-text" autocomplete="off" />
                        </label>
                        <span class="description"><?php esc_html_e('Used to sign webhook payloads with an HMAC signature.', 'wp-plugin-watchdog'); ?></span>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('WPScan API key', 'wp-plugin-watchdog'); ?></th>
                <td>
                    <input type="text" name="settings[notifications][wpscan_api_key]" value="<?php echo esc_attr($settings['notifications']['wpscan_api_key']); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('Optional. Provide your own WPScan API key to enrich vulnerability reports.', 'wp-plugin-watchdog'); ?></p>
                </td>
            </tr>
        </table>
        <p><button class="button button-primary" type="submit"><?php esc_html_e('Save settings', 'wp-plugin-watchdog'); ?></button></p>
    </form>
</div>
