<?php

namespace HM\Cavalcade\Runner;

use Exception;

const IMDSV2_TOKEN_URL = 'http://169.254.169.254/latest/api/token';
const NETWORK_MACS_URL = 'http://169.254.169.254/latest/meta-data/network/interfaces/macs/';
const MAX_RETRY = 3;
const RETRY_INTERVAL = 1000;

function init_healthcheck($option)
{
    global $eip, $prev_ip_check, $ip_check_interval;

    list($eip, $ip_check_interval) = explode(',', $option);
    $prev_ip_check = time();
}

$get_current_ips = (function () {
    $token = null;

    $renew_token = function () use (&$token) {
        $ch = curl_init(IMDSV2_TOKEN_URL);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-aws-ec2-metadata-token-ttl-seconds: 21600']);
        curl_setopt($ch, CURLOPT_PUT, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $content = curl_exec($ch);
        curl_close($ch);
        $token = $content;
    };

    $get = null;
    $get = function ($url, $retry_count = 0) use (&$get, &$token, $renew_token) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-aws-ec2-metadata-token: $token"]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $content = substr($response, $header_size);
        curl_close($ch);

        if ($status !== 200) {
            if ($status === 401) {
                $renew_token();
            }
            if ($retry_count < MAX_RETRY) {
                usleep(RETRY_INTERVAL * 1000);
                return $get($url, $retry_count + 1);
            }
            throw new MetadataError("failed to get URL: $url");
        }
        return $content;
    };

    $func = function () use ($get) {
        # Cast as array explicitly to avoid Intelephense error.
        # https://github.com/bmewburn/vscode-intelephense/issues/1643
        $ips = (array)array_merge(...array_map(
            function ($mac) use ($get) {
                return array_filter(array_map(
                    'trim',
                    explode("\n", $get(NETWORK_MACS_URL . $mac . 'public-ipv4s'))
                ));
            },
            array_filter(array_map('trim', explode("\n", $get(NETWORK_MACS_URL))))
        ));
        sort($ips);
        return $ips;
    };

    $renew_token();

    return $func;
})();

function has_eip($log)
{
    global $get_current_ips, $eip;

    try {
        $ips = $get_current_ips();
    } catch (Exception $e) {
        $log->info('failed to get public IP: ' . $e->getMessage());
        return false;
    }

    if (in_array($eip, $ips)) {
        $log->debug('found EIP', ['eip' => $eip]);
        return true;
    }

    $log->debug('could not find EIP', ['eip' => $eip, 'current_ips' => var_export($ips, true)]);
    return false;
}

function check_eip($log, $now)
{
    global $prev_ip_check, $ip_check_interval, $get_current_ips, $eip;

    if ($now - $prev_ip_check < $ip_check_interval) {
        return true;
    }

    $prev_ip_check = $now;
    if (in_array($eip, $get_current_ips())) {
        return true;
    }

    $log->info('eip lost during excecution, exiting...');

    return false;
}

/*CAVALCADE_GET_IP_FOR_TESTING*/
