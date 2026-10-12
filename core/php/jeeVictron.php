<?php
/* This file is part of Jeedom - plugin Victron GX
 * Point d'entrée des données envoyées par le démon victrond.
 */

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

    if (!jeedom::apiAccess(init('apikey'), 'victron')) {
        echo __('Vous n\'êtes pas autorisé à effectuer cette action', __FILE__);
        die();
    }
    if (init('test') != '') {
        echo 'OK';
        die();
    }
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        log::add('victron', 'error', 'Données invalides reçues du démon : ' . substr($raw, 0, 300));
        http_response_code(400);
        die();
    }
    log::add('victron', 'debug', 'Reçu du démon : ' . substr($raw, 0, 2000));
    set_time_limit(300);
    victron::handleData($data);
    echo 'OK';
} catch (Exception $e) {
    log::add('victron', 'error', displayException($e));
    http_response_code(500);
    echo $e->getMessage();
}
