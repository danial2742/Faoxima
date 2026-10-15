<?php

declare(strict_types=1);

if (!function_exists('faoxima_manualsale_ext_is_file')) {
    function faoxima_manualsale_ext_is_file(string $ext): bool
    {
        $ext = strtolower(ltrim(trim($ext), '.'));
        if ($ext === '' || $ext === 'sub' || $ext === 'text') {
            return false;
        }
        if (function_exists('rxManualsaleExtIsFile')) {
            return (bool)rxManualsaleExtIsFile($ext);
        }
        return true;
    }
}

if (!function_exists('faoxima_build_service_output')) {
    function faoxima_build_service_output(array $opts): array
    {
        $panelType = (string)($opts['panel_type'] ?? '');
        $content   = (string)($opts['content'] ?? '');
        $subLink   = trim((string)($opts['sub_link'] ?? ''));
        $fileExt   = strtolower(ltrim(trim((string)($opts['file_ext'] ?? '')), '.'));
        $username  = preg_replace('/[^A-Za-z0-9-]/', '', str_replace('_', '-', (string)($opts['username'] ?? 'config')));
        if ($username === '') {
            $username = 'config';
        }

        $configs = [];
        if (isset($opts['configs']) && is_array($opts['configs'])) {
            foreach ($opts['configs'] as $c) {
                if (is_string($c) && trim($c) !== '') {
                    $configs[] = $c;
                }
            }
        }

        $items = [];
        if (isset($opts['items']) && is_array($opts['items'])) {
            foreach ($opts['items'] as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $itContent = (string)($it['content'] ?? '');
                if (trim($itContent) === '') {
                    continue;
                }
                $items[] = [
                    'content'  => $itContent,
                    'file_ext' => strtolower(ltrim(trim((string)($it['file_ext'] ?? '')), '.')),
                    'sub_link' => trim((string)($it['sub_link'] ?? '')),
                ];
            }
        }

        $output = [];

        if ($panelType === 'Manualsale') {
            if (count($items) > 1) {
                $unifiedSub = $subLink;
                if ($unifiedSub === '') {
                    foreach ($items as $it) {
                        if ($it['sub_link'] !== '') {
                            $unifiedSub = $it['sub_link'];
                            break;
                        }
                    }
                }
                if ($unifiedSub !== '') {
                    $output[] = ['type' => 'link', 'value' => $unifiedSub];
                }
                $index = 0;
                foreach ($items as $it) {
                    $index++;
                    $itExt = $it['file_ext'];
                    $itContent = $it['content'];
                    if ($itExt === 'sub') {
                        $linkValue = $itContent !== '' ? $itContent : $it['sub_link'];
                        if ($linkValue !== '') {
                            $output[] = ['type' => 'link', 'value' => $linkValue];
                        }
                        continue;
                    }
                    if (faoxima_manualsale_ext_is_file($itExt)) {
                        $ext = ($itExt !== '') ? $itExt : 'bin';
                        $output[] = [
                            'type'     => 'file',
                            'value'    => 'data:application/octet-stream;base64,' . base64_encode($itContent),
                            'filename' => function_exists('normalizeConfigFilename') ? normalizeConfigFilename($username . '-' . $index . '.' . $ext, '', $ext === 'conf') : $username . '-' . $index . '.' . $ext,
                        ];
                    } else {
                        $output[] = ['type' => 'text', 'value' => $itContent];
                    }
                }
                return $output;
            }

            $isLink = $fileExt === 'sub';
            $isFile = faoxima_manualsale_ext_is_file($fileExt);

            if ($isLink) {
                $linkValue = $content !== '' ? $content : $subLink;
                if ($linkValue !== '') {
                    $output[] = ['type' => 'link', 'value' => $linkValue];
                }
                return $output;
            }

            if ($subLink !== '') {
                $output[] = ['type' => 'link', 'value' => $subLink];
            }

            if ($content !== '') {
                if ($isFile) {
                    $ext = ($fileExt !== '') ? $fileExt : 'bin';
                    $output[] = [
                        'type'     => 'file',
                        'value'    => 'data:application/octet-stream;base64,' . base64_encode($content),
                        'filename' => function_exists('normalizeConfigFilename') ? normalizeConfigFilename($username . '.' . $ext, '', $ext === 'conf') : $username . '.' . $ext,
                    ];
                } else {
                    $output[] = ['type' => 'text', 'value' => $content];
                }
            } elseif (!empty($configs)) {
                $output[] = ['type' => 'text', 'value' => $configs[0]];
            }

            return $output;
        }

        if ($subLink !== '') {
            $output[] = ['type' => 'link', 'value' => $subLink];
        }

        $configItems = [];
        foreach ($configs as $c) {
            if ($c !== $subLink) {
                $configItems[] = $c;
            }
        }
        if (empty($configItems) && $content !== '' && $content !== $subLink) {
            $configItems[] = $content;
        }
        if (!empty($configItems)) {
            $output[] = ['type' => 'config', 'value' => $configItems];
        }

        return $output;
    }
}

if (!function_exists('faoxima_wg_postprocess')) {
    /**
     * WireGuard / AmneziaWG accounts: drop subscription `link` entries (meaningless for
     * conf-based services) and append one AmneziaVPN `vpn_key` entry per conf found.
     * $extraConfs: raw conf texts known to the caller (e.g. x-ui single_config_file, WGDashboard conf).
     */
    function faoxima_wg_postprocess(array $output, array $extraConfs = []): array
    {
        if (!function_exists('amnezia_vpn_key_from_conf') || !function_exists('rx_is_wg_account')) {
            return $output;
        }

        $confs = [];
        $addConf = static function ($conf) use (&$confs): void {
            if (is_string($conf) && stripos($conf, '[Interface]') !== false) {
                $confs[md5($conf)] = $conf;
            }
        };
        foreach ($extraConfs as $c) {
            $addConf($c);
        }

        $isWg = !empty($confs);
        foreach ($output as $entry) {
            $type  = (string)($entry['type'] ?? '');
            $value = $entry['value'] ?? '';
            if ($type === 'file' && is_string($value)) {
                if (preg_match('#^data:[^;,]*;base64,(.*)$#s', $value, $m)) {
                    $value = (string)base64_decode($m[1], true);
                }
                $addConf($value);
            } elseif ($type === 'text' && is_string($value)) {
                $addConf($value);
            } elseif ($type === 'config') {
                foreach ((array)$value as $link) {
                    if (!is_string($link)) {
                        continue;
                    }
                    if (preg_match('#^\s*(wireguard|wg|vpn|awg|amneziawg|xuifile|xuiawgfile)://#i', $link)) {
                        $isWg = true;
                    }
                    if (function_exists('xui_wg_conf_from_link')) {
                        $parsed = xui_wg_conf_from_link($link);
                        if (is_array($parsed)) {
                            $addConf($parsed['conf']);
                        }
                    }
                }
            }
        }
        if (!empty($confs)) {
            $isWg = true;
        }
        if (!$isWg) {
            return $output;
        }

        $output = array_values(array_filter($output, static function ($entry) {
            return ($entry['type'] ?? '') !== 'link';
        }));

        foreach ($confs as $conf) {
            $key = amnezia_vpn_key_from_conf($conf, '');
            if ($key === null) {
                continue;
            }
            $output[] = [
                'type'  => 'vpn_key',
                'value' => $key,
                'label' => preg_match('/^\s*(Jc|Jmin|Jmax|S1|S2|H1)\s*=/mi', $conf) ? 'AmneziaWG' : 'WireGuard',
            ];
        }
        return $output;
    }
}
