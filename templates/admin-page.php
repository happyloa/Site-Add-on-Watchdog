<?php
/** @var Risk[] $risks */
/** @var string[] $ignored */
/** @var array $settings */
/** @var string $scanNonce */
?>
<div class="wrap">
    <h1><?php esc_html_e('WP Plugin Watchdog', 'wp-plugin-watchdog'); ?></h1>

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
        <table class="widefat">
            <thead>
            <tr>
                <th><?php esc_html_e('Plugin', 'wp-plugin-watchdog'); ?></th>
                <th><?php esc_html_e('Local Version', 'wp-plugin-watchdog'); ?></th>
                <th><?php esc_html_e('Directory Version', 'wp-plugin-watchdog'); ?></th>
                <th><?php esc_html_e('Reasons', 'wp-plugin-watchdog'); ?></th>
                <th><?php esc_html_e('Actions', 'wp-plugin-watchdog'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($risks as $risk) : ?>
                <tr>
                    <td><?php echo esc_html($risk->pluginName); ?></td>
                    <td><?php echo esc_html($risk->localVersion); ?></td>
                    <td><?php echo esc_html($risk->remoteVersion ?? __('N/A', 'wp-plugin-watchdog')); ?></td>
                    <td>
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
                                                <?php echo esc_html($vuln['title'] ?? ''); ?>
                                                <?php if (! empty($vuln['cve'])) : ?>
                                                    - <?php echo esc_html($vuln['cve']); ?>
                                                <?php endif; ?>
                                                <?php if (! empty($vuln['fixed_in'])) : ?>
                                                    (<?php printf(esc_html__('Fixed in %s', 'wp-plugin-watchdog'), esc_html($vuln['fixed_in'])); ?>)
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </td>
                    <td>
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
                <th scope="row"><?php esc_html_e('Email notifications', 'wp-plugin-watchdog'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="settings[email_enabled]" <?php checked($settings['email_enabled']); ?> />
                        <?php esc_html_e('Enabled', 'wp-plugin-watchdog'); ?>
                    </label>
                    <p>
                        <label>
                            <?php esc_html_e('Recipients (comma separated)', 'wp-plugin-watchdog'); ?><br />
                            <input type="text" name="settings[email_recipients]" value="<?php echo esc_attr($settings['email_recipients']); ?>" class="regular-text" />
                        </label>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Discord notifications', 'wp-plugin-watchdog'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="settings[discord_enabled]" <?php checked($settings['discord_enabled']); ?> />
                        <?php esc_html_e('Enabled', 'wp-plugin-watchdog'); ?>
                    </label>
                    <p>
                        <label>
                            <?php esc_html_e('Discord webhook URL', 'wp-plugin-watchdog'); ?><br />
                            <input type="url" name="settings[discord_webhook]" value="<?php echo esc_attr($settings['discord_webhook']); ?>" class="regular-text" />
                        </label>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Generic webhook', 'wp-plugin-watchdog'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="settings[webhook_enabled]" <?php checked($settings['webhook_enabled']); ?> />
                        <?php esc_html_e('Enabled', 'wp-plugin-watchdog'); ?>
                    </label>
                    <p>
                        <label>
                            <?php esc_html_e('Webhook URL', 'wp-plugin-watchdog'); ?><br />
                            <input type="url" name="settings[webhook_url]" value="<?php echo esc_attr($settings['webhook_url']); ?>" class="regular-text" />
                        </label>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('WPScan API key', 'wp-plugin-watchdog'); ?></th>
                <td>
                    <input type="text" name="settings[wpscan_api_key]" value="<?php echo esc_attr($settings['wpscan_api_key']); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('Optional. Provide your own WPScan API key to enrich vulnerability reports.', 'wp-plugin-watchdog'); ?></p>
                </td>
            </tr>
        </table>
        <p><button class="button button-primary" type="submit"><?php esc_html_e('Save settings', 'wp-plugin-watchdog'); ?></button></p>
    </form>
</div>
