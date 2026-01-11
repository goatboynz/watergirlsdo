<?php
/**
 * Helper to communicate with Home Assistant API
 */

function get_ha_api_token() {
    return getenv('SUPERVISOR_TOKEN');
}

function get_ha_api_url() {
    return 'http://supervisor/core/api';
}

function ha_get_entities() {
    $token = get_ha_api_token();
    $url = get_ha_api_url() . '/states';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        return [];
    }

    return json_decode($response, true);
}

function ha_set_state($entity_id, $state) {
    $token = get_ha_api_token();
    $domain = explode('.', $entity_id)[0];
    $service = ($state === 'on') ? 'turn_on' : 'turn_off';
    
    $url = get_ha_api_url() . '/services/' . $domain . '/' . $service;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['entity_id' => $entity_id]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

function ha_get_history($entity_ids, $start_time_iso) {
    $token = get_ha_api_token();
    // Example start_time_iso: 2026-01-11T08:00:00Z
    $url = get_ha_api_url() . '/history/period/' . urlencode($start_time_iso) . '?filter_entity_id=' . implode(',', $entity_ids);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}
?>
