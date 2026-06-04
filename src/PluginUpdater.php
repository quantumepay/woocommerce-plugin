<?php

namespace WooQuantum;

class PluginUpdater
{
    private $plugin_file;
    private $plugin_basename;
    private $repo;
    private $branch;
    private $asset_name;

    public function __construct($plugin_file, $repo, $branch = 'main', $asset_name = '')
    {
        $this->plugin_file = $plugin_file;
        $this->plugin_basename = plugin_basename($plugin_file);
        $this->repo = $repo;
        $this->branch = $branch;
        $this->asset_name = $asset_name;

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
    }

    public function check_for_update($transient)
    {
        if (empty($transient->checked[$this->plugin_basename])) {
            return $transient;
        }

        $release = $this->get_latest_release();

        if (!$release || empty($release['version']) || empty($release['download_url'])) {
            return $transient;
        }

        if (version_compare($transient->checked[$this->plugin_basename], $release['version'], '<')) {
            $transient->response[$this->plugin_basename] = (object) array(
                'slug' => dirname($this->plugin_basename),
                'plugin' => $this->plugin_basename,
                'new_version' => $release['version'],
                'url' => $release['url'],
                'package' => $release['download_url'],
            );
        }

        return $transient;
    }

    public function plugin_info($result, $action, $args)
    {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (empty($args->slug) || $args->slug !== dirname($this->plugin_basename)) {
            return $result;
        }

        $release = $this->get_latest_release();

        if (!$release) {
            return $result;
        }

        return (object) array(
            'name' => 'Qoin - Payment Gateway',
            'slug' => dirname($this->plugin_basename),
            'version' => $release['version'],
            'author' => 'Quantum ePay',
            'homepage' => $release['url'],
            'download_link' => $release['download_url'],
            'sections' => array(
                'description' => 'Accept credit card payments with Qoin.',
                'changelog' => !empty($release['body']) ? nl2br($release['body']) : '',
            ),
        );
    }

    private function get_latest_release()
    {
        $cache_key = 'wc_quantumepay_latest_release_' . md5($this->repo . $this->branch . $this->asset_name);
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $response = wp_remote_get('https://api.github.com/repos/' . $this->repo . '/releases/latest', array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version'),
            ),
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['tag_name'])) {
            return false;
        }

        $download_url = $this->get_release_download_url($body);

        if (!$download_url) {
            $download_url = 'https://github.com/' . $this->repo . '/archive/refs/tags/' . $body['tag_name'] . '.zip';
        }

        $release = array(
            'version' => ltrim($body['tag_name'], 'v'),
            'url' => !empty($body['html_url']) ? $body['html_url'] : 'https://github.com/' . $this->repo,
            'download_url' => $download_url,
            'body' => !empty($body['body']) ? $body['body'] : '',
        );

        set_transient($cache_key, $release, 6 * HOUR_IN_SECONDS);

        return $release;
    }

    private function get_release_download_url($release)
    {
        if (empty($this->asset_name) || empty($release['assets'])) {
            return false;
        }

        foreach ($release['assets'] as $asset) {
            if (!empty($asset['name']) && $asset['name'] === $this->asset_name && !empty($asset['browser_download_url'])) {
                return $asset['browser_download_url'];
            }
        }

        return false;
    }
}