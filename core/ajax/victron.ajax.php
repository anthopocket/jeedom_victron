<?php
/* This file is part of Jeedom - plugin Victron GX */

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }
    ajax::init();

    if (init('action') == 'sync') {
        victron::sendToDaemon(array('action' => 'sync'));
        ajax::success();
    }

    if (init('action') == 'syncEq') {
        $eqLogic = victron::byId(init('id'));
        if (!is_object($eqLogic)) {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }
        $eqLogic->syncCommands();
        ajax::success();
    }

    if (init('action') == 'getStatus') {
        $status = victron::getGxStatus();
        $status['installation_id'] = config::byKey('installation_id', 'victron', isset($status['installation_id']) ? $status['installation_id'] : '');
        ajax::success($status);
    }

    if (init('action') == 'pair') {
        $host = trim(init('host'));
        if ($host == '') {
            throw new Exception(__('Renseignez l\'adresse du GX', __FILE__));
        }
        $python = victron::getPython();
        if (!is_executable($python)) {
            throw new Exception(__('Installez d\'abord les dépendances', __FILE__));
        }
        $id = trim(init('vrm'));
        if ($id == '') {
            $id = config::byKey('installation_id', 'victron', 'jeedom');
        }
        $cmd = escapeshellarg($python) . ' ' . escapeshellarg(realpath(__DIR__ . '/../../resources/victrond') . '/pair.py')
            . ' --host ' . escapeshellarg($host) . ' --id ' . escapeshellarg($id) . ' 2>&1';
        $output = shell_exec($cmd);
        $lines = array_filter(explode("\n", trim((string) $output)));
        $result = json_decode((string) end($lines), true);
        if (!is_array($result)) {
            throw new Exception(__('Réponse inattendue : ', __FILE__) . $output);
        }
        if (empty($result['ok'])) {
            throw new Exception($result['error']);
        }
        config::save('host', $host, 'victron');
        config::save('username', $result['username'], 'victron');
        config::save('password', $result['password'], 'victron');
        config::save('port', 8883, 'victron');
        config::save('ssl', 1, 'victron');
        ajax::success(array('username' => $result['username'], 'password' => $result['password']));
    }

    throw new Exception(__('Aucune méthode correspondante à', __FILE__) . ' : ' . init('action'));
} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
