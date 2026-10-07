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
        if (!is_object($transient) || empty($transient->checked[$this->plugin_basename])) {
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

        if (!is_object($args) || empty($args->slug) || $args->slug !== dirname($this->plugin_basename)) {
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
                'changelog' => !empty($release['body']) ? wp_kses_post(nl2br($release['body'])) : '',
            ),
        );
    }

    private function valid_version($version)
    {
        return is_string($version) && strlen($version) <= 100
            && preg_match('/^\d+\.\d+(?:\.\d+)?(?:[-+][A-Za-z0-9.-]+)?$/D', $version) === 1;
    }

    private function valid_package_url($url)
    {
        if (!is_string($url) || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) return false;
        $parts = wp_parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || strtolower($parts['host'] ?? '') !== 'github.com'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])) return false;
        $path = $parts['path'] ?? '';
        if (preg_match('/(?:^|\/)\.{1,2}(?:\/|$)/', rawurldecode($path))) return false;
        $prefix = '/' . $this->repo . '/';
        return strpos($path, $prefix . 'releases/download/') === 0 || strpos($path, $prefix . 'archive/refs/tags/') === 0;
    }

    private function get_latest_release()
    {
        $cache_key = 'wc_quantumepay_latest_release_' . md5($this->repo . $this->branch . $this->asset_name);
        delete_transient($cache_key);

        $response = wp_remote_get('https://api.github.com/repos/' . $this->repo . '/releases/latest', array(
            'timeout' => 15, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 524288,
            'headers' => array(
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version'),
            ),
        ));

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body) || !is_string($body['tag_name'] ?? null) || !empty($body['draft'])
            || !$this->valid_version(preg_replace('/^v/', '', $body['tag_name']))) {
            return false;
        }

        $download_url = $this->get_release_download_url($body);

        if (!$download_url) {
            if ($this->asset_name !== '') return false;
            $download_url = 'https://github.com/' . $this->repo . '/archive/refs/tags/' . rawurlencode($body['tag_name']) . '.zip';
        }

        $release = array(
            'version' => preg_replace('/^v/', '', $body['tag_name']),
            'url' => 'https://github.com/' . $this->repo,
            'download_url' => $download_url,
            'body' => isset($body['body']) && is_string($body['body']) ? wp_kses_post($body['body']) : '',
        );

        return $release;
    }

    private function get_release_download_url($release)
    {
        if (empty($this->asset_name) || empty($release['assets']) || !is_array($release['assets'])) {
            return false;
        }

        foreach ($release['assets'] as $asset) {
            if (is_array($asset) && ($asset['name'] ?? null) === $this->asset_name && $this->valid_package_url($asset['browser_download_url'] ?? null)) {
                return $asset['browser_download_url'];
            }
        }

        return false;
    }
}